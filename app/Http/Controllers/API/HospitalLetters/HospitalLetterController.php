<?php

namespace App\Http\Controllers\API\HospitalLetters;

use App\Http\Controllers\Controller;
use App\Mail\ConversationNotification;
use App\Models\HospitalLetter;
use App\Models\FollowUp;
use App\Models\PatientHistory;
use App\Models\PatientHistoryConversation;
use App\Models\PatientHistoryConversationStatus;
use App\Models\Referral;
use App\Models\User;
use App\Services\TransferReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Support\Pagination;
use App\Support\HospitalDoctorAccess;

class HospitalLetterController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        // Authorization is checked in each action because ROLE HOSPITAL USER
        // may submit a DG-review request without receiving the normal
        // Hospital Letter create/update permissions.
        $this->middleware('permission:View Hospital Letter|Create Hospital Letter|Update Hospital Letter|Delete Hospital Letter', ['only' => ['index','show','update','destroy']]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $doctorAccess = app(HospitalDoctorAccess::class);
        $doctorHospitalIds = $doctorAccess->hospitalIds($user);

        if (!$user->can('View Hospital Letter') && $doctorHospitalIds === null) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $letters = HospitalLetter::with(['referral', 'printedBy', 'proposedHospital', 'proposedBy', 'dgApprovedBy'])
            ->when($request->filled('outcome'), function ($query) use ($request): void {
                $query->where('outcome', $request->input('outcome'));
            })
            ->when($request->filled('date_from'), function ($query) use ($request): void {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            })
            ->when($request->filled('date_to'), function ($query) use ($request): void {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            })
            ->when($doctorHospitalIds !== null, function ($query) use ($doctorAccess, $user): void {
                $query->whereHas('referral', function ($referralQuery) use ($doctorAccess, $user): void {
                    $doctorAccess->applyReferralScope($referralQuery, $user);
                })->whereHas('followups');
            })
            ->latest('letter_id')
            ->paginate(Pagination::perPage($request, 25));

        $lettersData = collect($letters->items());
        if ($doctorHospitalIds !== null) {
            $lettersData->each(fn ($letter) => $letter->makeHidden(['letter_file']));
        }

        return response()->json([
            'data' => $lettersData->values(),
            'meta' => Pagination::meta($letters),
            'statusCode' => 200
        ]);
    }

    /**
     * Stream an uploaded follow-up document through the authenticated API.
     * Hospital users may review the uploaded file for an authorized follow-up,
     * but generated-letter and print endpoints remain restricted until DG
     * approval is recorded.
     */
    public function file(int $id)
    {
        $user = auth()->user();
        $doctorAccess = app(HospitalDoctorAccess::class);

        if (!$this->canViewStoredLetter($user)) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $letter = HospitalLetter::with(['referral.patient'])
            ->whereHas('followups')
            ->find($id);

        // Hospital users may only open files belonging to an authorized
        // hospital/own case. Return not-found so another facility's records
        // are not disclosed.
        if ($doctorAccess->isHospitalDoctor($user)
            && (! $letter || ! $doctorAccess->canAccessReferral($user, $letter->referral))) {
            return response()->json([
                'message' => 'Follow-up letter file not found',
                'statusCode' => 404,
            ], 404);
        }

        if (!$letter || !$letter->letter_file) {
            return response()->json([
                'message' => 'Follow-up letter file not found',
                'statusCode' => 404,
            ], 404);
        }

        $relativePath = ltrim(str_replace('\\', '/', (string) $letter->letter_file), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return response()->json([
                'message' => 'Follow-up letter file not found',
                'statusCode' => 404,
            ], 404);
        }

        $privateRoot = realpath(storage_path('app'));
        $privatePath = realpath(storage_path('app/' . $relativePath));
        $filePath = null;

        if ($privateRoot && $privatePath
            && str_starts_with($privatePath, $privateRoot . DIRECTORY_SEPARATOR)
            && is_file($privatePath)) {
            $filePath = $privatePath;
        }

        // Keep authorized access compatible with legacy files uploaded before
        // follow-up documents moved to private storage.
        if (!$filePath) {
            $publicRoot = realpath(public_path());
            $legacyPath = realpath(public_path($relativePath));
            if ($publicRoot && $legacyPath
                && str_starts_with($legacyPath, $publicRoot . DIRECTORY_SEPARATOR)
                && is_file($legacyPath)) {
                $filePath = $legacyPath;
            }
        }

        if (!$filePath) {
            return response()->json([
                'message' => 'Follow-up letter file not found',
                'statusCode' => 404,
            ], 404);
        }

        $fileName = basename($filePath);
        $mimeType = function_exists('mime_content_type')
            ? (mime_content_type($filePath) ?: 'application/octet-stream')
            : 'application/octet-stream';

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . addcslashes($fileName, "\\\"") . '"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * DG approval for a follow-up submitted by ROLE HOSPITAL USER.
     * Existing non-hospital follow-ups do not enter this endpoint and keep
     * their current workflow unchanged.
     */
    public function approveFollowUp(Request $request, int $id, TransferReferralService $transfers)
    {
        $user = auth()->user();

        if (! $this->canReviewFollowUpApproval($user)) {
            return response()->json([
                'message' => 'Only the Director General may review follow-up requests.',
                'statusCode' => 403,
            ], 403);
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:confirmed,rejected'],
            'comments' => ['nullable', 'string', 'max:10000'],
        ]);

        $letter = HospitalLetter::with([
            'referral.patient',
            'referral.patientHistory',
            'proposedHospital',
            'proposedBy',
        ])->find($id);

        if (! $letter) {
            return response()->json([
                'message' => 'Follow-up request not found',
                'statusCode' => 404,
            ], 404);
        }

        if ($letter->dg_approval_status !== 'pending') {
            return response()->json([
                'message' => 'This follow-up request has already been reviewed.',
                'data' => $letter,
                'statusCode' => 409,
            ], 409);
        }

        if ($validated['decision'] === 'rejected') {
            $letter->update([
                'dg_approval_status' => 'rejected',
                'dg_approved_by' => $user->getKey(),
                'dg_approved_at' => now(),
                'dg_comments' => $validated['comments'] ?? null,
            ]);

            $letter->refresh()->load(['referral.patient', 'proposedHospital', 'proposedBy', 'dgApprovedBy']);
            $this->notifyFollowUpDecision($letter, $user, false);

            return response()->json([
                'message' => 'Follow-up request returned to the hospital user.',
                'data' => $letter,
                'statusCode' => 200,
            ]);
        }

        $letter = DB::transaction(function () use ($letter, $validated, $user, $transfers): HospitalLetter {
            $lockedLetter = HospitalLetter::with(['referral.patientHistory', 'proposedHospital'])
                ->whereKey($letter->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedLetter->dg_approval_status !== 'pending') {
                throw ValidationException::withMessages([
                    'decision' => 'This follow-up request has already been reviewed.',
                ]);
            }

            $referral = Referral::whereKey($lockedLetter->referral_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedLetter->update([
                'dg_approval_status' => 'confirmed',
                'dg_approved_by' => $user->getKey(),
                'dg_approved_at' => now(),
                'dg_comments' => $validated['comments'] ?? null,
            ]);

            if ($lockedLetter->outcome === 'Transferred') {
                if (! $lockedLetter->proposed_hospital_id) {
                    throw ValidationException::withMessages([
                        'hospital_id' => 'A receiving hospital is required before this transfer can be confirmed.',
                    ]);
                }

                $transfers->create(
                    $lockedLetter,
                    $referral,
                    (int) $lockedLetter->proposed_hospital_id,
                    (int) $user->getKey(),
                );
            } elseif (in_array($lockedLetter->outcome, ['Finished', 'Death'], true)) {
                // Preserve the existing outcome behavior, but apply it only
                // after DG confirmation for a hospital-user proposal.
                $referral->update(['status' => 'Closed']);
            }

            return $lockedLetter->fresh()->load([
                'referral.patient',
                'proposedHospital',
                'proposedBy',
                'dgApprovedBy',
                'transferredReferral.hospital',
            ]);
        });

        $this->notifyFollowUpDecision($letter, $user, true);

        return response()->json([
            'message' => $this->isPrintableFollowUpOutcome($letter)
                ? 'Follow-up request confirmed. The doctor has been notified and may print the letter.'
                : 'Follow-up request confirmed. The doctor has been notified and the existing outcome workflow has been applied.',
            'data' => $letter,
            'statusCode' => 200,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * @OA\Post(
     *     path="/api/hospital-letters",
     *     summary="Create a new hospital letter",
     *     tags={"Hospital Letters"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 required={"referral_id","outcome"},
     *                 @OA\Property(
     *                     property="referral_id",
     *                     type="integer",
     *                     description="ID of the referral"
     *                 ),
     *                 @OA\Property(
     *                     property="hospital_id",
     *                     type="integer",
     *                     nullable=true,
     *                     description="Required if outcome = Transferred"
     *                 ),
     *                 @OA\Property(
     *                     property="content_summary",
     *                     type="string",
     *                     nullable=true,
     *                     description="Summary of the letter"
     *                 ),
     *                 @OA\Property(
     *                     property="next_appointment_date",
     *                     type="string",
     *                     format="date",
     *                     nullable=true,
     *                     description="Next appointment date; required when outcome is Transferred"
     *                 ),
     *                 @OA\Property(
     *                     property="letter_file",
     *                     type="string",
     *                     format="binary",
     *                     nullable=true,
     *                     description="Letter file (PDF, DOC, DOCX, max 2MB)"
     *                 ),
     *                 @OA\Property(
     *                     property="outcome",
     *                     type="string",
     *                     enum={"Follow-up","Finished","Transferred","Death"},
     *                     description="Outcome of the hospital letter"
     *                 ),
     *                 @OA\Property(
     *                     property="followup_date",
     *                     type="string",
     *                     format="date",
     *                     nullable=true,
     *                     description="Follow-up date (required for Follow-up, Finished, Transferred, Death)"
     *                 ),
     *                 @OA\Property(
     *                     property="notes",
     *                     type="string",
     *                     nullable=true,
     *                     description="Additional notes"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Hospital Letter created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Hospital Letter created successfully"),
     *             @OA\Property(property="data", type="object"),
     *             @OA\Property(property="statusCode", type="integer", example=201)
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Referral not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request, TransferReferralService $transfers)
    {
        $user = auth()->user();
        $doctorAccess = app(HospitalDoctorAccess::class);
        $isHospitalProposal = $doctorAccess->isHospitalDoctor($user);

        // Hospital users submit a proposal through the same form. Other users
        // retain the existing direct follow-up workflow.
        if (! $isHospitalProposal && ! $user->can('Create Hospital Letter')) {
            return response()->json([
                'message'    => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        // Base validation rules
        $rules = [
            'referral_id'           => 'required|exists:referrals,referral_id',
            'hospital_id'           => 'nullable|exists:hospitals,hospital_id',
            'content_summary'       => 'nullable|string',
            'next_appointment_date' => 'nullable|date',
            'letter_file'           => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:2048',
            'outcome'               => 'required|in:Follow-up,Finished,Transferred,Death',
            'submission_key'        => 'nullable|uuid',
        ];

        $validator = Validator::make($request->all(), $rules);

        // Conditional validation
        // $validator->sometimes('followup_date', 'required|date', function ($input) {
        $validator->sometimes('followup_date', 'nullable|date', function ($input) {
            return in_array($input->outcome, ['Follow-up', 'Finished', 'Death', 'Transferred']);
        });

        $validator->sometimes('hospital_id', 'required|exists:hospitals,hospital_id', function ($input) {
            return $input->outcome === 'Transferred';
        });

        $validator->sometimes('next_appointment_date', 'required|date', function ($input) {
            return $input->outcome === 'Transferred';
        });

        if ($validator->fails()) {
            return response()->json([
                'message'    => 'Validation Error',
                'errors'     => $validator->errors(),
                'statusCode' => 422
            ], 422);
        }

        $validated = $validator->validated();

        // Add default followup_date if missing
        if (empty($validated['followup_date'])) {
            $validated['followup_date'] = now()->toDateString();
        }

        // Ensure referral exists
        $referral = Referral::find($validated['referral_id']);
        if (!$referral) {
            return response()->json([
                'message'    => 'Referral not found',
                'statusCode' => 404
            ], 404);
        }

        if ($isHospitalProposal && ! $doctorAccess->canAccessReferral($user, $referral->load('patient'))) {
            return response()->json([
                'message' => 'Follow-up request not found',
                'statusCode' => 404,
            ], 404);
        }

        if ($existing = $this->existingSubmission($validated)) {
            return response()->json(['message' => 'Follow-up already saved successfully', 'data' => $existing, 'statusCode' => 200]);
        }

        // Store follow-up letters on Laravel's private disk. Do not place
        // clinical documents under public/ because a direct URL would bypass
        // the authenticated hospital-user restriction.
        if (!empty($validated['letter_file'])) {
            $file = $request->file('letter_file');
            $extension = $file->getClientOriginalExtension();
            $newFileName = 'hospital_letter_' . Str::uuid() . '.' . $extension;
            $storedPath = $file->storeAs('follow-up-letters', $newFileName, 'local');

            if (!is_string($storedPath) || $storedPath === '') {
                return response()->json([
                    'message' => 'Unable to securely store the follow-up letter.',
                    'statusCode' => 500,
                ], 500);
            }

            $validated['letter_file'] = $storedPath;
        }

        $validated['created_by'] = Auth::id();

        if ($isHospitalProposal) {
            $letter = DB::transaction(function () use ($validated, $referral, $user): HospitalLetter {
                $letter = HospitalLetter::create([
                    'referral_id' => $referral->getKey(),
                    'content_summary' => $validated['content_summary'] ?? null,
                    'next_appointment_date' => $validated['next_appointment_date'] ?? null,
                    'letter_file' => $validated['letter_file'] ?? null,
                    'outcome' => $validated['outcome'],
                    'submission_key' => $validated['submission_key'] ?? null,
                    'dg_approval_status' => 'pending',
                    'proposed_hospital_id' => $validated['hospital_id'] ?? null,
                    'proposed_by' => $user->getKey(),
                    'dg_approval_requested_at' => now(),
                ]);

                FollowUp::create([
                    'letter_id' => $letter->getKey(),
                    'patient_id' => $referral->patient_id,
                    'followup_date' => $validated['followup_date'] ?? now()->toDateString(),
                    'notes' => $validated['content_summary'] ?? null,
                    'followup_status' => match ($validated['outcome']) {
                        'Follow-up' => 'Ongoing',
                        'Transferred' => 'Transferred',
                        default => 'Closed',
                    },
                ]);

                return $letter;
            });

            $this->notifyDirectorGeneralOfFollowUp($letter, $referral, $user);

            return response()->json([
                'message' => 'Follow-up request sent to the Director General for review.',
                'data' => $letter->load(['proposedHospital', 'proposedBy']),
                'statusCode' => 200,
            ]);
        }

        $letter = DB::transaction(function () use ($validated, $referral, $transfers): HospitalLetter {
            $referral = Referral::whereKey($referral->getKey())->lockForUpdate()->firstOrFail();
            if ($existing = $this->existingSubmission($validated)) return $existing;
            // Create the hospital letter, referral changes, and follow-up as
            // one unit so a follow-up failure cannot leave a partial case.
            $letter = HospitalLetter::create($validated);

            // Outcome-specific Follow-up data
            switch ($validated['outcome']) {
                case 'Follow-up':
                    $followupData = [
                        'followup_date'   => $validated['followup_date'],
                        'notes'           => $validated['content_summary'] ?? null,
                        'followup_status' => 'Ongoing',
                    ];
                    break;

                case 'Finished':
                    $followupData = [
                        'followup_date'   => $validated['followup_date'],
                        'notes'           => $validated['content_summary'] ?? null,
                        'followup_status' => 'Closed',
                    ];
                    break;

                case 'Transferred':
                    $followupData = [
                        'followup_date'   => $validated['followup_date'],
                        'notes'           => $validated['content_summary'] ?? null,
                        'followup_status' => 'Transferred',
                        'hospital_id'     => $validated['hospital_id'],
                        'patient_id'      => $referral->patient_id,
                    ];
                    break;

                case 'Death':
                    $followupData = [
                        'followup_date'   => $validated['followup_date'],
                        'notes'           => $validated['content_summary'] ?? null,
                        'followup_status' => 'Closed',
                    ];
                    break;

                default:
                    // Safety fallback in case outcome is missing or invalid
                    $followupData = [
                        'followup_date'   => $validated['followup_date'] ?? now()->toDateString(),
                        'notes'           => $validated['content_summary'] ?? null,
                        'followup_status' => 'Ongoing',
                    ];
                    break;
            }

            // Update referral status only for certain outcomes
            if ($validated['outcome'] === 'Finished' || $validated['outcome'] === 'Death') {
                $referral->update(['status' => 'Closed']);
            }

            // If Transferred, create new referral
            if ($validated['outcome'] === 'Transferred') {
                $transfers->create($letter, $referral, (int) $validated['hospital_id'], (int) Auth::id());
            }

            FollowUp::create([
                'letter_id'       => $letter->letter_id,
                'patient_id'      => $referral->patient_id,
                'followup_date'   => $followupData['followup_date'],
                'notes'           => $followupData['notes'] ?? null,
                'followup_status' => $followupData['followup_status'],
            ]);

            return $letter;
        });

        return response()->json([
            'message'    => 'Follow-up created successfully',
            'data'       => $letter,
            'statusCode' => 200
        ]);
    }

    private function existingSubmission(array $data): ?HospitalLetter
    {
        if (empty($data['submission_key'])) return null;
        $existing = HospitalLetter::withTrashed()->where('submission_key', $data['submission_key'])->first();
        if (! $existing) return null;
        if ($existing->trashed()) {
            throw ValidationException::withMessages(['submission_key' => 'This saved follow-up was removed. Refresh the page before recording another follow-up.']);
        }
        if ((int) $existing->referral_id !== (int) $data['referral_id'] || $existing->outcome !== $data['outcome']
            || ($existing->content_summary ?? '') !== ($data['content_summary'] ?? '')
            || ($existing->next_appointment_date ?? '') !== ($data['next_appointment_date'] ?? '')
            || ($existing->outcome === 'Transferred' && (int) ($existing->proposed_hospital_id ?? $existing->transferredReferral?->hospital_id) !== (int) ($data['hospital_id'] ?? 0))) {
            throw ValidationException::withMessages(['submission_key' => 'This submission was already saved with different details. Refresh the page before recording another follow-up.']);
        }
        return $existing;
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $user = auth()->user();
        $doctorAccess = app(HospitalDoctorAccess::class);
        $doctorHospitalIds = $doctorAccess->hospitalIds($user);
        if (!$user->can('View Hospital Letter') && $doctorHospitalIds === null) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $letter = HospitalLetter::with(['referral', 'followups', 'proposedHospital', 'proposedBy', 'dgApprovedBy'])
            ->when($doctorHospitalIds !== null, function ($query) use ($doctorAccess, $user): void {
                $query->whereHas('referral', function ($referralQuery) use ($doctorAccess, $user): void {
                    $doctorAccess->applyReferralScope($referralQuery, $user);
                })->whereHas('followups');
            })
            ->find($id);

        if (!$letter) {
            return response()->json([
                'message' => 'Hospital Letter not found',
                'statusCode' => 404
            ], 404);
        }

        if ($doctorHospitalIds !== null) {
            $letter->makeHidden(['letter_file']);
        }

        return response()->json([
            'data' => $letter,
            'statusCode' => 200
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function updateHospitalLetter(Request $request, $id, TransferReferralService $transfers)
    {
        $user = auth()->user();
        if (app(HospitalDoctorAccess::class)->deniesFollowUpLetter($user)) {
            return response()->json([
                'message' => 'Hospital doctors may view follow-up records only.',
                'statusCode' => 403,
            ], 403);
        }

        if (!$user->can('Update Hospital Letter')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $letter = HospitalLetter::findOrFail($id);

        // Get referral linked to this letter (supporting parent_referral_id)
        $referral = Referral::where('referral_id', $letter->referral_id)
            ->orWhere('referral_id', $letter->parent_referral_id)
            ->first();

        // Safe patient_id: from referral OR letter
        $patientId = $referral->patient_id ?? $letter->patient_id ?? null;

        if (!$patientId) {
            return response()->json([
                'message'    => 'No patient found for this hospital letter',
                'statusCode' => 404,
            ], 404);
        }

        // Validate input
        $validated = $request->validate([
            'outcome'         => 'nullable|in:Follow-up,Finished,Transferred,Death',
            'followup_date'   => 'nullable|date',
            'content_summary' => 'nullable|string',
            'hospital_id'     => 'nullable|numeric|exists:hospitals,hospital_id',
            'next_appointment_date' => 'nullable|date',
        ]);

        // Outcome fallback (never null)
        $outcome = $validated['outcome'] ?? $letter->outcome ?? 'Follow-up';

        if ($letter->transferred_referral_id && $outcome !== 'Transferred') {
            throw ValidationException::withMessages(['outcome' => 'This entry already has a hospital transfer. Record a new follow-up to change the treatment outcome.']);
        }

        if ($outcome === 'Transferred') {
            $hospitalId = $validated['hospital_id'] ?? $letter->transferredReferral?->hospital_id;
            $appointment = $validated['next_appointment_date'] ?? $letter->next_appointment_date;
            if (! $hospitalId || ! $appointment) {
                throw ValidationException::withMessages(['hospital_id' => 'Select the transfer hospital and its next appointment date.']);
            }
            $letter = DB::transaction(function () use ($letter, $referral, $validated, $hospitalId, $appointment, $transfers): HospitalLetter {
                $referral = Referral::whereKey($referral->getKey())->lockForUpdate()->firstOrFail();
                $letter = HospitalLetter::whereKey($letter->getKey())->lockForUpdate()->firstOrFail();
                // Legacy transferred entries must be repaired before editing; do not create a second child.
                if ($letter->outcome === 'Transferred' && ! $letter->transferred_referral_id) {
                    throw ValidationException::withMessages(['outcome' => 'This existing transfer needs a verified referral link before it can be edited.']);
                }
                $letter->update([
                    'outcome' => 'Transferred',
                    'content_summary' => $validated['content_summary'] ?? $letter->content_summary,
                    'next_appointment_date' => $appointment,
                ]);
                $transfers->create($letter, $referral, (int) $hospitalId, (int) Auth::id());
                FollowUp::updateOrCreate(['letter_id' => $letter->getKey()], [
                    'patient_id' => $referral->patient_id,
                    'followup_date' => $validated['followup_date'] ?? now()->toDateString(),
                    'notes' => $letter->content_summary,
                    'followup_status' => 'Transferred',
                ]);
                return $letter;
            });
            return response()->json(['message' => 'Hospital Letter updated successfully', 'letter' => $letter, 'statusCode' => 200]);
        }

        // Follow-up date fallback
        $followupDate = $validated['followup_date'] ?? now()->toDateString();

        // Build followup data by outcome (all cases covered)
        switch ($outcome) {
            case 'Follow-up':
                $followupData = [
                    'followup_date'   => $followupDate,
                    'notes'           => $validated['content_summary'] ?? $letter->content_summary ?? null,
                    'followup_status' => 'Ongoing',
                    'hospital_id'     => $validated['hospital_id'] ?? $letter->hospital_id ?? null,
                    'patient_id'      => $patientId,
                ];
                break;

            case 'Finished':
                $followupData = [
                    'followup_date'   => $followupDate,
                    'notes'           => $validated['content_summary'] ?? $letter->content_summary ?? null,
                    'followup_status' => 'Closed',
                    'hospital_id'     => $validated['hospital_id'] ?? $letter->hospital_id ?? null,
                    'patient_id'      => $patientId,
                ];
                break;

            case 'Transferred':
                $followupData = [
                    'followup_date'   => $followupDate,
                    'notes'           => $validated['content_summary'] ?? $letter->content_summary ?? null,
                    'followup_status' => 'Transferred',
                    'hospital_id'     => $validated['hospital_id'] ?? $letter->hospital_id ?? null,
                    'patient_id'      => $patientId,
                ];
                break;

            case 'Death':
                $followupData = [
                    'followup_date'   => $followupDate,
                    'notes'           => $validated['content_summary'] ?? $letter->content_summary ?? null,
                    'followup_status' => 'Death',
                    'hospital_id'     => $validated['hospital_id'] ?? $letter->hospital_id ?? null,
                    'patient_id'      => $patientId,
                ];
                break;

            default:
                // Safety fallback for any other unforeseen outcome
                $followupData = [
                    'followup_date'   => $followupDate,
                    'notes'           => $validated['content_summary'] ?? $letter->content_summary ?? null,
                    'followup_status' => 'Ongoing',
                    'hospital_id'     => $validated['hospital_id'] ?? $letter->hospital_id ?? null,
                    'patient_id'      => $patientId,
                ];
                break;
        }

        // Update the hospital letter
        $letter->update([
            'outcome'         => $outcome,
            'content_summary' => $validated['content_summary'] ?? $letter->content_summary,
        ]);

        // Save or update follow-up (always has followup_status now)
        FollowUp::updateOrCreate(
            ['letter_id' => $letter->letter_id],
            [
                'patient_id'      => $followupData['patient_id'],
                'followup_date'   => $followupData['followup_date'],
                'notes'           => $followupData['notes'],
                'followup_status' => $followupData['followup_status'],
                'hospital_id'     => $followupData['hospital_id'],
            ]
        );

        return response()->json([
            'message' => 'Hospital Letter updated successfully',
            'letter'  => $letter,
        ]);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $user = auth()->user();
        if (app(HospitalDoctorAccess::class)->deniesFollowUpLetter($user)) {
            return response()->json([
                'message' => 'Hospital doctors may view follow-up records only.',
                'statusCode' => 403,
            ], 403);
        }

        if (!$user->can('Delete Hospital Letter')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $letter = HospitalLetter::find($id);

        if (!$letter) {
            return response()->json([
                'message' => 'Hospital Letter not found',
                'statusCode' => 404
            ], 404);
        }

        if ($letter->letter_file) {
            $letterPath = str_replace('\\', '/', (string) $letter->letter_file);
            if (str_starts_with($letterPath, 'uploads/hospitalLetters/')) {
                // Legacy public upload path.
                Storage::disk('public')->delete($letterPath);
            } else {
                Storage::disk('local')->delete($letterPath);
            }
        }

        $letter->delete();

        return response()->json([
            'message' => 'Hospital Letter deleted successfully',
            'statusCode' => 200
        ]);
    }

    private function canReviewFollowUpApproval($user): bool
    {
        return $user && $user->hasAnyRole([
            'ROLE DG',
            'ROLE DIRECTOR GENERAL',
            'ROLE ADMIN',
            'ROLE SUPER ADMIN',
            'ROLE SUPERADMIN',
        ]);
    }

    private function notifyDirectorGeneralOfFollowUp(HospitalLetter $letter, Referral $referral, User $sender): void
    {
        $history = $referral->patientHistory;
        if (! $history) {
            $history = PatientHistory::find($referral->patient_histories_id);
        }

        if (! $history) {
            return;
        }

        $recipientId = $history->dg_id
            ?: User::role(['ROLE DG', 'ROLE DIRECTOR GENERAL'])->value('id');

        if (! $recipientId || (int) $recipientId === (int) $sender->getKey()) {
            return;
        }

        $patientName = $referral->patient?->name ?: 'patient';
        $destination = $letter->proposed_hospital_id
            ? ' Proposed hospital: '.($letter->proposedHospital?->hospital_name ?: $letter->proposed_hospital_id).'.'
            : '';

        $this->sendFollowUpConversation(
            (int) $history->getKey(),
            (int) $sender->getKey(),
            (int) $recipientId,
            sprintf(
                'Follow-up request for %s requires DG review. Proposed outcome: %s.%s',
                $patientName,
                $letter->outcome,
                $destination,
            ),
        );
    }

    private function notifyFollowUpDecision(HospitalLetter $letter, User $reviewer, bool $confirmed): void
    {
        $referral = $letter->referral;
        $history = $referral?->patientHistory;
        if (! $history && $referral?->patient_histories_id) {
            $history = PatientHistory::find($referral->patient_histories_id);
        }

        $recipientId = $letter->proposed_by;
        if (! $history || ! $recipientId || (int) $recipientId === (int) $reviewer->getKey()) {
            return;
        }

        $message = $confirmed
            ? ($this->isPrintableFollowUpOutcome($letter)
                ? 'The Director General confirmed your follow-up request. You may now print the follow-up letter.'
                : 'The Director General confirmed your follow-up request. The existing outcome workflow has been applied.')
            : 'The Director General returned your follow-up request for review. Please read the conversation for guidance.';

        $this->sendFollowUpConversation(
            (int) $history->getKey(),
            (int) $reviewer->getKey(),
            (int) $recipientId,
            $message,
        );
    }

    private function sendFollowUpConversation(
        int $patientHistoryId,
        int $senderId,
        int $receiverId,
        string $message,
    ): void {
        $conversation = PatientHistoryConversation::create([
            'patient_history_id' => $patientHistoryId,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'message' => $message,
        ]);

        PatientHistoryConversationStatus::create([
            'conversation_id' => $conversation->getKey(),
            'user_id' => $receiverId,
            'read_at' => null,
            'is_notified' => false,
        ]);

        $recipient = User::find($receiverId);
        if (! $recipient?->email) {
            return;
        }

        try {
            $sender = User::find($senderId);
            $senderDisplayName = trim(($sender?->first_name ?? '').' '.($sender?->middle_name ?? '').' '.($sender?->last_name ?? ''));
            Mail::to($recipient->email)->queue(
                new ConversationNotification($conversation, $senderDisplayName, $recipient),
            );
        } catch (\Throwable $exception) {
            // The in-app notification has already been saved. An unavailable
            // mail transport must not undo the clinical workflow action.
            Log::warning('Follow-up conversation email could not be queued', [
                'conversation_id' => $conversation->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function isPrintableFollowUpOutcome(HospitalLetter $letter): bool
    {
        return in_array($letter->outcome, ['Follow-up', 'Transferred'], true);
    }

    private function canViewStoredLetter($user): bool
    {
        return $user && ($user->can('View Hospital Letter')
            || $user->can('View FollowUp')
            || app(HospitalDoctorAccess::class)->isHospitalDoctor($user)
            || $user->hasAnyRole(['ROLE ADMIN', 'ROLE SUPER ADMIN', 'ROLE SUPERADMIN', 'ROLE DG', 'ROLE DIRECTOR GENERAL']));
    }
}
