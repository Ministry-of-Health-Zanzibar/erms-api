<?php

namespace App\Http\Controllers\API\HospitalLetters;

use App\Http\Controllers\Controller;
use App\Models\HospitalLetter;
use App\Models\Referral;
use App\Models\FollowUp;
use App\Services\TransferReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Support\Pagination;

class HospitalLetterController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:View Hospital Letter|Create Hospital Letter|Update Hospital Letter|Delete Hospital Letter', ['only' => ['index','store','show','update','destroy']]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        if (!$user->can('View Hospital Letter')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $letters = HospitalLetter::with(['referral','printedBy'])
            ->when($request->filled('outcome'), function ($query) use ($request): void {
                $query->where('outcome', $request->input('outcome'));
            })
            ->when($request->filled('date_from'), function ($query) use ($request): void {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            })
            ->when($request->filled('date_to'), function ($query) use ($request): void {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            })
            ->latest('letter_id')
            ->paginate(Pagination::perPage($request, 25));

        return response()->json([
            'data' => $letters->items(),
            'meta' => Pagination::meta($letters),
            'statusCode' => 200
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

        // Permission check
        if (!$user->can('Create Hospital Letter')) {
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
            'letter_file'           => 'nullable|file|mimes:pdf,doc,docx|max:2048',
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

        if ($existing = $this->existingSubmission($validated)) {
            return response()->json(['message' => 'Follow-up already saved successfully', 'data' => $existing, 'statusCode' => 200]);
        }

        // Handle file upload
        if (!empty($validated['letter_file'])) {
            $file = $request->file('letter_file');
            $extension = $file->getClientOriginalExtension();
            $newFileName = 'hospital_letter_' . Str::uuid() . '.' . $extension;
            $file->move(public_path('uploads/hospitalLetters/'), $newFileName);
            $validated['letter_file'] = 'uploads/hospitalLetters/' . $newFileName;
        }

        $validated['created_by'] = Auth::id();

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
            || ($existing->outcome === 'Transferred' && (int) $existing->transferredReferral?->hospital_id !== (int) ($data['hospital_id'] ?? 0))) {
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
        if (!$user->can('View Hospital Letter')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $letter = HospitalLetter::with(['referral','followups'])->find($id);

        if (!$letter) {
            return response()->json([
                'message' => 'Hospital Letter not found',
                'statusCode' => 404
            ], 404);
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
            Storage::disk('public')->delete($letter->letter_file);
        }

        $letter->delete();

        return response()->json([
            'message' => 'Hospital Letter deleted successfully',
            'statusCode' => 200
        ]);
    }
}
