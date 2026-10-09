<?php

namespace App\Http\Controllers\API\Referrals;

use App\Models\Insurance;
use App\Models\PatientHistory;
use App\Models\BoardedOutLetter;
use App\Models\Hospital;
use App\Models\Referral;
use App\Models\Bill;
use App\Models\Payment;
use DB;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use App\Support\Pagination;


class ReferralController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Display a listing of the resource.
     */
    /**
     * @OA\Get(
     *     path="/api/referrals",
     *     summary="Get all referrals",
     *     tags={"referrals"},
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="referral_id", type="integer"),
     *                     @OA\Property(property="patient_id", type="integer"),
     *                     @OA\Property(property="hospital_id", type="integer"),
     *                     @OA\Property(property="reason_id", type="integer"),
     *                     @OA\Property(property="start_date", type="string", format="date-time"),
     *                     @OA\Property(property="end_date", type="string", format="date-time"),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="confirmed_by", type="string"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="deleted_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $dataEntryEmails = [
            'medicalboard@mohz.go.tz',
            'hospital@mohz.go.tz',
            'mkurugenzi@mohz.go.tz',
            'dguser@mohz.go.tz'
        ];

        if (!$user->can('View Referral')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $isDataEntryUser = in_array($user->email, $dataEntryEmails);
        $canSeeAllReferralSources = $user->hasAnyRole([
            'ROLE DIRECTOR GENERAL',
            'ROLE DG',
            'ROLE ADMIN',
            'ROLE SUPER ADMIN',
            'ROLE SUPERADMIN',
        ]);

        // Build a small candidate query first. The list is grouped by
        // referral_number and also contains virtual history rows, so loading
        // every full referral and related model before grouping was expensive.
        $query = Referral::query()->where('status', '<>', 'Requested')->whereHas('patient');

        // DG and administrators must be able to see referrals created from
        // hospital/data-entry patients as well as referrals from other users.
        // The old email-only check excluded the seeded `dg@mohz.go.tz` account
        // and hid valid hospital referrals from DG.
        if (! $canSeeAllReferralSources) {
            if ($isDataEntryUser) {
                $query->whereHas('patient.creator', function ($q) use ($dataEntryEmails) {
                    $q->whereIn('email', $dataEntryEmails);
                });
            } else {
                $query->whereHas('patient.creator', function ($q) use ($dataEntryEmails) {
                    $q->whereNotIn('email', $dataEntryEmails);
                });
            }
        }

        if (! $canSeeAllReferralSources) {
            $query->where('status', '<>', 'Pending');
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $term = mb_strtolower($search);
            $query->whereHas('patient', function ($patientQuery) use ($term): void {
                $patientQuery->whereRaw('LOWER(name) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(matibabu_card) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(zan_id) LIKE ?', [$term.'%']);
            });
        }

        $query->when($request->filled('status'), function ($query) use ($request): void {
            $query->where('status', $request->input('status'));
        })->when($request->filled('facility'), function ($query) use ($request): void {
            $query->where('hospital_id', $request->input('facility'));
        })->when($request->filled('date_from'), function ($query) use ($request): void {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        })->when($request->filled('date_to'), function ($query) use ($request): void {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        });

        if ($request->boolean('has_followup')) {
            $query->whereHas('hospitalLetters.followups');
        }

        $realCandidates = (clone $query)
            ->selectRaw("'real' as source")
            ->selectRaw('CAST(referral_number AS VARCHAR) as source_key')
            ->selectRaw('MAX(created_at) as latest_activity')
            ->selectRaw("MAX(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as has_pending")
            ->groupBy('referral_number');

        $virtualQuery = PatientHistory::query()
            ->whereHas('patient')
            ->whereDoesntHave('referrals')
            ->whereDoesntHave('boardedOutLetters')
            ->whereIn('status', ['requested', 'approved']);

        $boardedOutQuery = PatientHistory::query()
            ->whereHas('patient')
            ->whereHas('boardedOutLetters')
            ->whereDoesntHave('referrals');

        foreach ([$virtualQuery, $boardedOutQuery] as $sourceQuery) {
            if (! $canSeeAllReferralSources) {
                $sourceQuery->whereHas('patient.creator', function ($q) use ($dataEntryEmails, $isDataEntryUser) {
                    $isDataEntryUser
                        ? $q->whereIn('email', $dataEntryEmails)
                        : $q->whereNotIn('email', $dataEntryEmails);
                });
            }

            if ($search !== '') {
                $term = mb_strtolower($search);
                $sourceQuery->whereHas('patient', function ($patientQuery) use ($term): void {
                    $patientQuery->whereRaw('LOWER(name) LIKE ?', [$term.'%'])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$term.'%'])
                        ->orWhereRaw('LOWER(matibabu_card) LIKE ?', [$term.'%'])
                        ->orWhereRaw('LOWER(zan_id) LIKE ?', [$term.'%']);
                });
            }

            $sourceQuery->when($request->filled('date_from'), function ($q) use ($request): void {
                $q->whereDate('created_at', '>=', $request->input('date_from'));
            })->when($request->filled('date_to'), function ($q) use ($request): void {
                $q->whereDate('created_at', '<=', $request->input('date_to'));
            });
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            $virtualQuery = in_array($status, ['requested', 'approved'], true)
                ? $virtualQuery->where('status', $status)
                : $virtualQuery->whereRaw('1 = 0');
            $boardedOutQuery = $status === 'BoardedOut'
                ? $boardedOutQuery
                : $boardedOutQuery->whereRaw('1 = 0');
        }

        if ($request->boolean('has_followup')) {
            $virtualQuery->whereRaw('1 = 0');
            $boardedOutQuery->whereRaw('1 = 0');
        }

        $virtualCandidates = $virtualQuery
            ->selectRaw("'virtual' as source")
            ->selectRaw('CAST(patient_histories_id AS VARCHAR) as source_key')
            ->selectRaw('updated_at as latest_activity')
            ->selectRaw('1 as has_pending');

        $boardedOutCandidates = $boardedOutQuery
            ->selectRaw("'boarded_out' as source")
            ->selectRaw('CAST(patient_histories_id AS VARCHAR) as source_key')
            ->selectRaw('updated_at as latest_activity')
            ->selectRaw('0 as has_pending');

        $candidateQuery = $realCandidates
            ->unionAll($virtualCandidates)
            ->unionAll($boardedOutCandidates);

        $candidates = DB::query()
            ->fromSub($candidateQuery, 'referral_candidates')
            ->orderByDesc('has_pending')
            ->orderByDesc('latest_activity')
            ->orderByDesc('source_key')
            ->paginate(Pagination::perPage($request, 25));

        $candidateRows = collect($candidates->items());
        $realReferralNumbers = $candidateRows->where('source', 'real')->pluck('source_key');

        $allReferrals = Referral::with([
            'patient',
            'reason',
            'hospital.referralType',
            'diagnoses',
            'referralLetters.printedBy',
        ])
            ->withExists([
                'hospitalLetters as has_followup' => function ($query) {
                    $query->whereHas('followups');
                },
            ])
            ->whereIn('referral_number', $realReferralNumbers)
            ->latest()
            ->get();

        /*
        |--------------------------------------------------------------------------
        | GET PATIENT IDS
        |--------------------------------------------------------------------------
        */
        $patientIds = $allReferrals
            ->pluck('patient_id')
            ->unique()
            ->filter()
            ->values();

        /*
        |--------------------------------------------------------------------------
        | PRELOAD EXACT CASE HISTORIES
        |--------------------------------------------------------------------------
        */
        $caseIds = $allReferrals->pluck('patient_histories_id')->filter()->unique();
        $caseHistories = PatientHistory::whereIn('patient_histories_id', $caseIds)
            ->get()
            ->keyBy('patient_histories_id');

        /*
        |--------------------------------------------------------------------------
        | PRELOAD BOARDED OUT LETTERS
        |--------------------------------------------------------------------------
        */
        $boardedOutLetters = BoardedOutLetter::with([
                'patientHistory',
                'printedBy',
                'referral.hospital',
            ])
            ->whereIn('patient_histories_id', $caseIds)
            ->latest()
            ->get()
            ->groupBy('patient_histories_id');

        /*
        |--------------------------------------------------------------------------
        | REAL REFERRALS (IMEREKEBISHWA KUONGEZA LOGIC YA FOLLOW-UP)
        |--------------------------------------------------------------------------
        */
        $referrals = $allReferrals
            ->groupBy('referral_number')
            ->map(function ($group) use (
                $caseHistories,
                $boardedOutLetters
            ) {
                $first = $group->first();
                $linkedCaseIds = $group->pluck('patient_histories_id')->unique();
                $history = $linkedCaseIds->count() === 1 && $linkedCaseIds->first() !== null
                    ? ($caseHistories[$first->patient_histories_id] ?? null) : null;
                if ($history && $group->contains(fn ($ref) => (int) $ref->patient_id !== (int) $history->patient_id)) {
                    $history = null;
                }
                $patientBoardedOutLetters = $history ? ($boardedOutLetters[$history->patient_histories_id] ?? collect()) : collect();
                $groupReferralIds = $group
                    ->pluck('referral_id')
                    ->map(fn ($id) => (int) $id)
                    ->values();
                $boardedOut = $patientBoardedOutLetters->first(
                    fn ($letter) => $letter->referral_id !== null
                        && $groupReferralIds->contains((int) $letter->referral_id)
                ) ?? $patientBoardedOutLetters->first(
                    fn ($letter) => $letter->referral_id === null
                );
                $isBoardedOut = $group->contains(function ($ref) {
                    return $ref->status === 'BoardedOut';
                }) || $history?->status === 'boarded_out' || $boardedOut !== null;
                $statusPriority = [
                    'BoardedOut' => 100,
                    'Confirmed' => 90,
                    'Transferred' => 80,
                    'Death' => 70,
                    'Closed' => 60,
                    'Expired' => 50,
                    'Cancelled' => 40,
                    'Pending' => 20,
                    'Requested' => 10,
                ];
                $caseStatus = $isBoardedOut || $boardedOut
                    ? 'BoardedOut'
                    : ($group->pluck('status')
                        ->sortByDesc(fn ($status) => $statusPriority[$status] ?? 0)
                        ->first() ?? 'Pending');

                // ✅ LOGIC MPYA: Angalia kama kuna angalau rufaa moja kwenye kikundi hiki yenye follow-up
                $groupHasFollowUp = $group->contains(function ($ref) {
                    return (bool) $ref->has_followup;
                });

                $referralLetter = $group
                    ->map(fn ($ref) => $ref->referralLetters)
                    ->filter()
                    ->sortByDesc('referral_letter_id')
                    ->first();

                return [
                    'referral_number' => $first->referral_number,
                    'patient' => $first->patient,
                    'diagnoses' => $first->diagnoses,
                    'reason' => $first->reason,
                    // One status is exposed at group level. Individual
                    // hospital referral statuses remain in the referrals list.
                    'status' => $caseStatus,
                    'case_status' => $history?->status,
                    'case_status_label' => $history?->status_tracking['label'] ?? 'Case link needs review',
                    'case_link_resolved' => $history !== null,
                    'record_type' => 'referral',
                    'hospitals' => $group->pluck('hospital')
                        ->unique('hospital_id')
                        ->values(),
                    
                    // ✅ IMEONGEZWA: Kujua kiwango cha juu (Group Level) kama ina follow up
                    'has_followup' => $groupHasFollowUp, 
                    'referral_letter' => $referralLetter ? [
                        'referral_letter_id' => $referralLetter->referral_letter_id,
                        'is_printed' => (bool) $referralLetter->is_printed,
                        'printed_at' => $referralLetter->printed_at,
                        'printed_by' => $referralLetter->printed_by,
                        'printed_by_name' => $referralLetter->printedBy?->full_name
                            ?: $referralLetter->printedBy?->email,
                        'print_count' => (int) $referralLetter->print_count,
                        'last_printed_language' => $referralLetter->last_printed_language,
                    ] : null,

                    'referrals' => $group->map(function ($ref) {
                        // ✅ Angalia kama rufaa hii mahususi ya hospitali hii ina follow-up
                        $singleRefHasFollowUp = (bool) $ref->has_followup;

                        return [
                            'referral_id' => $ref->referral_id,
                            'patient_histories_id' => $ref->patient_histories_id,
                            'status' => $ref->status,
                            'hospital_id' => $ref->hospital_id,
                            'hospital' => $ref->hospital,
                            'created_at' => $ref->created_at,
                            // ✅ IMEONGEZWA: Ndani ya list ya rufaa za kila hospitali
                            'has_followup' => $singleRefHasFollowUp, 
                        ];
                    })->values(),
                    'has_pending' => $caseStatus !== 'BoardedOut'
                        && $group->contains('status', 'Pending'),
                    'latest_activity' => $group->max('created_at'),
                    'is_boarded_out' => $isBoardedOut,
                    'boarded_out' => $boardedOut ? [
                        'id' => $boardedOut->id,
                        'referral_id' => $boardedOut->referral_id,
                        'hospital' => $boardedOut->referral?->hospital,
                        'receiver' => $boardedOut->receiver,
                        'reference_number' => $boardedOut->reference_number,
                        'reference_date' => $boardedOut->reference_date,
                        'recommendations' => $boardedOut->recommendations,
                        'is_printed' => (bool) $boardedOut->is_printed,
                        'printed_at' => $boardedOut->printed_at,
                        'printed_by' => $boardedOut->printed_by,
                        'printed_by_name' => $boardedOut->printedBy?->full_name
                            ?: $boardedOut->printedBy?->email,
                        'print_count' => (int) $boardedOut->print_count,
                        'last_printed_language' => $boardedOut->last_printed_language,
                    ] : null,
                    'history_id' => $history?->patient_histories_id,
                    'history' => $history
                        ? $this->formatHistory($history)
                        : null,
                ];
            });

        /*
        |--------------------------------------------------------------------------
        | VIRTUAL (REQUESTED + APPROVED)
        |--------------------------------------------------------------------------
        */
        $virtualHistoryIds = $candidateRows
            ->where('source', 'virtual')
            ->pluck('source_key')
            ->map(fn ($id) => (int) $id)
            ->values();

        $noReferralHistories = PatientHistory::with([
                'patient',
                'diagnoses',
                'reason'
            ])
            ->whereIn('patient_histories_id', $virtualHistoryIds)
            ->get()
            ->keyBy('patient_histories_id');

        $virtualReferrals = $noReferralHistories->mapWithKeys(function ($history) {
            return [$history->patient_histories_id => [
                'referral_number' => 'N/A-' . $history->patient_histories_id,
                'patient' => $history->patient,
                'diagnoses' => $history->diagnoses,
                'reason' => $history->reason,
                'history' => $this->formatHistory($history),
                'status' => 'Pending',
                'hospitals' => [null],
                // ✅ Rufaa pepe (Virtual) haina follow-up kwa kuwa haijatengenezwa bado
                'has_followup' => false, 
                'referrals' => [
                    [
                        'referral_id' => null,
                        'status' => 'Pending',
                        'hospital' => null,
                        'created_at' => $history->created_at,
                        'has_followup' => false,
                    ]
                ],
                'has_pending' => true,
                'latest_activity' => $history->updated_at,
                'is_recommendation_only' => true,
                'record_type' => 'history',
                'case_status' => $history->status,
                'case_status_label' => $history->status_tracking['label'] ?? $history->status,
                'history_id' => $history->patient_histories_id,
            ]];
        });

        /*
        |--------------------------------------------------------------------------
        | BOARDED OUT VIRTUALS
        |--------------------------------------------------------------------------
        */
        $boardedOutHistoryIds = $candidateRows
            ->where('source', 'boarded_out')
            ->pluck('source_key')
            ->map(fn ($id) => (int) $id)
            ->values();

        $boardedOutHistories = PatientHistory::with([
                'patient',
                'diagnoses',
                'reason',
                'boardedOutLetters.printedBy',
                'boardedOutLetters.referral.hospital',
            ])
            ->whereIn('patient_histories_id', $boardedOutHistoryIds)
            ->get()
            ->keyBy('patient_histories_id');

        $boardedOutVirtuals = $boardedOutHistories->mapWithKeys(function ($history) {
            $boardedOut = $history->boardedOutLetters->last();
            $isBoardedOut = !is_null($boardedOut);
            return [$history->patient_histories_id => [
                'referral_number' => $isBoardedOut
                    ? 'BO-' . $history->patient_histories_id
                    : 'NBO-' . $history->patient_histories_id,
                'patient' => $history->patient,
                'diagnoses' => $history->diagnoses,
                'reason' => $history->reason,
                'history' => $this->formatHistory($history),
                'status' => $isBoardedOut ? 'BoardedOut' : 'Pending',
                'hospitals' => [null],
                // ✅ Boarded out haina follow up ya hospitali
                'has_followup' => false, 
                'referrals' => [
                    [
                        'referral_id' => null,
                        'status' => $isBoardedOut ? 'BoardedOut' : 'Pending',
                        'hospital' => null,
                        'created_at' => $boardedOut?->created_at ?? $history->created_at,
                        'has_followup' => false,
                    ]
                ],
                'has_pending' => !$isBoardedOut,
                'latest_activity' => $boardedOut?->created_at ?? $history->updated_at,
                'is_boarded_out' => $isBoardedOut,
                'record_type' => 'history',
                'case_status' => $history->status,
                'case_status_label' => $history->status_tracking['label'] ?? $history->status,
                'history_id' => $history->patient_histories_id,
                'boarded_out' => [
                    'id' => $boardedOut?->id,
                    'referral_id' => $boardedOut?->referral_id,
                    'hospital' => $boardedOut?->referral?->hospital,
                    'receiver' => $boardedOut?->receiver,
                    'reference_number' => $boardedOut?->reference_number,
                    'reference_date' => $boardedOut?->reference_date,
                    'recommendations' => $boardedOut?->recommendations,
                    'is_printed' => (bool) $boardedOut?->is_printed,
                    'printed_at' => $boardedOut?->printed_at,
                    'printed_by' => $boardedOut?->printed_by,
                    'printed_by_name' => $boardedOut?->printedBy?->full_name
                        ?: $boardedOut?->printedBy?->email,
                    'print_count' => (int) ($boardedOut?->print_count ?? 0),
                    'last_printed_language' => $boardedOut?->last_printed_language,
                ]
            ]];
        });

        /*
        |--------------------------------------------------------------------------
        | FINAL MERGE + SORT
        |--------------------------------------------------------------------------
        */
        $finalData = $candidateRows
            ->map(function ($candidate) use ($referrals, $virtualReferrals, $boardedOutVirtuals) {
                return match ($candidate->source) {
                    'real' => $referrals->get($candidate->source_key),
                    'virtual' => $virtualReferrals->get((int) $candidate->source_key),
                    'boarded_out' => $boardedOutVirtuals->get((int) $candidate->source_key),
                    default => null,
                };
            })
            ->filter()
            ->values();

        return response([
            'data' => $finalData,
            'meta' => Pagination::meta($candidates),
            'statusCode' => 200
        ], 200);
    }
    // public function index()
    // {
    //     $user = auth()->user();

    //     $dataEntryEmails = [
    //         'medicalboard@mohz.go.tz',
    //         'hospital@mohz.go.tz',
    //         'mkurugenzi@mohz.go.tz',
    //         'dguser@mohz.go.tz'
    //     ];

    //     if (!$user->can('View Referral')) {
    //         return response([
    //             'message' => 'Forbidden',
    //             'statusCode' => 403
    //         ], 403);
    //     }

    //     $isDataEntryUser = in_array($user->email, $dataEntryEmails);

    //     /*
    //     |--------------------------------------------------------------------------
    //     | REAL REFERRALS QUERY
    //     |--------------------------------------------------------------------------
    //     */
    //     $query = Referral::with([
    //         'patient',
    //         'reason',
    //         'hospital',
    //         'diagnoses'
    //     ])
    //     ->where('status', '<>', 'Requested');

    //     if ($isDataEntryUser) {
    //         $query->whereHas('patient.creator', function ($q) use ($dataEntryEmails) {
    //             $q->whereIn('email', $dataEntryEmails);
    //         });
    //     } else {
    //         $query->whereHas('patient.creator', function ($q) use ($dataEntryEmails) {
    //             $q->whereNotIn('email', $dataEntryEmails);
    //         });
    //     }

    //     if (!$user->hasRole(['ROLE DIRECTOR GENERAL', 'ROLE ADMIN'])) {
    //         $query->where('status', '<>', 'Pending');
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | FETCH REFERRALS ONLY ONCE
    //     |--------------------------------------------------------------------------
    //     */
    //     $allReferrals = $query->latest()->get();

    //     /*
    //     |--------------------------------------------------------------------------
    //     | GET PATIENT IDS
    //     |--------------------------------------------------------------------------
    //     */
    //     $patientIds = $allReferrals
    //         ->pluck('patient_id')
    //         ->unique()
    //         ->filter()
    //         ->values();

    //     /*
    //     |--------------------------------------------------------------------------
    //     | PRELOAD LATEST HISTORIES
    //     |--------------------------------------------------------------------------
    //     */
    //     $latestHistories = PatientHistory::whereIn('patient_id', $patientIds)
    //         ->latest('created_at')
    //         ->get()
    //         ->groupBy('patient_id')
    //         ->map(function ($items) {
    //             return $items->first();
    //         });

    //     /*
    //     |--------------------------------------------------------------------------
    //     | PRELOAD BOARDED OUT LETTERS
    //     |--------------------------------------------------------------------------
    //     */
    //     $boardedOutLetters = BoardedOutLetter::with('patientHistory')
    //         ->whereHas('patientHistory', function ($q) use ($patientIds) {
    //             $q->whereIn('patient_id', $patientIds);
    //         })
    //         ->latest()
    //         ->get()
    //         ->groupBy(function ($item) {
    //             return $item->patientHistory?->patient_id;
    //         })
    //         ->map(function ($items) {
    //             return $items->first();
    //         });

    //     /*
    //     |--------------------------------------------------------------------------
    //     | REAL REFERRALS
    //     |--------------------------------------------------------------------------
    //     */
    //     $referrals = $allReferrals
    //         ->groupBy('referral_number')
    //         ->map(function ($group) use (
    //             $latestHistories,
    //             $boardedOutLetters
    //         ) {

    //             $first = $group->first();
    //             // ✅ NO MORE DB QUERIES HERE
    //             $history = $latestHistories[$first->patient_id] ?? null;
    //             $boardedOut = $boardedOutLetters[$first->patient_id] ?? null;
    //             $isBoardedOut = $group->contains(function ($ref) {
    //                 return $ref->status === 'BoardedOut';
    //             });

    //             return [
    //                 'referral_number' => $first->referral_number,
    //                 'patient' => $first->patient,
    //                 'diagnoses' => $first->diagnoses,
    //                 'reason' => $first->reason,
    //                 'status' => $group->pluck('status')
    //                     ->unique()
    //                     ->sort()
    //                     ->implode(', '),
    //                 'hospitals' => $group->pluck('hospital')
    //                     ->unique('hospital_id')
    //                     ->values(),
    //                 'referrals' => $group->map(function ($ref) {
    //                     return [
    //                         'referral_id' => $ref->referral_id,
    //                         'status' => $ref->status,
    //                         'hospital' => $ref->hospital,
    //                         'created_at' => $ref->created_at,
    //                     ];
    //                 })->values(),
    //                 'has_pending' => $group->contains('status', 'Pending'),
    //                 'latest_activity' => $group->max('created_at'),
    //                 'is_boarded_out' => $isBoardedOut,
    //                 'boarded_out' => $boardedOut ? [
    //                     'receiver' => $boardedOut->receiver,
    //                     'reference_number' => $boardedOut->reference_number,
    //                     'reference_date' => $boardedOut->reference_date,
    //                     'recommendations' => $boardedOut->recommendations,
    //                 ] : null,
    //                 'history_id' => $history?->patient_histories_id,
    //                 'history' => $history
    //                     ? $this->formatHistory($history)
    //                     : null,
    //             ];
    //         })
    //         ->values();

    //     /*
    //     |--------------------------------------------------------------------------
    //     | VIRTUAL (REQUESTED + APPROVED)
    //     |--------------------------------------------------------------------------
    //     */
    //     $noReferralHistories = PatientHistory::with([
    //             'patient',
    //             'diagnoses',
    //             'reason'
    //         ])
    //         ->whereDoesntHave('referrals')
    //         ->whereDoesntHave('boardedOutLetters')
    //         ->whereIn('status', ['requested', 'approved'])
    //         ->whereHas('patient.creator', function ($q) use (
    //             $dataEntryEmails,
    //             $isDataEntryUser
    //         ) {
    //             if ($isDataEntryUser) {
    //                 $q->whereIn('email', $dataEntryEmails);
    //             } else {
    //                 $q->whereNotIn('email', $dataEntryEmails);
    //             }
    //         })
    //         ->latest()
    //         ->get();

    //     $virtualReferrals = $noReferralHistories->map(function ($history) {

    //         return [
    //             'referral_number' => 'N/A-' . $history->patient_histories_id,
    //             'patient' => $history->patient,
    //             'diagnoses' => $history->diagnoses,
    //             'reason' => $history->reason,
    //             'history' => $this->formatHistory($history),
    //             'status' => 'Pending',
    //             'hospitals' => [null],
    //             'referrals' => [
    //                 [
    //                     'referral_id' => null,
    //                     'status' => 'Pending',
    //                     'hospital' => null,
    //                     'created_at' => $history->created_at,
    //                 ]
    //             ],
    //             'has_pending' => true,
    //             'latest_activity' => $history->updated_at,
    //             'is_recommendation_only' => true,
    //             'history_id' => $history->patient_histories_id,
    //         ];
    //     });

    //     /*
    //     |--------------------------------------------------------------------------
    //     | BOARDED OUT VIRTUALS
    //     |--------------------------------------------------------------------------
    //     */
    //     $boardedOutHistories = PatientHistory::with([
    //             'patient',
    //             'diagnoses',
    //             'reason',
    //             'boardedOutLetters'
    //         ])
    //         ->whereHas('boardedOutLetters')
    //         ->whereDoesntHave('referrals')
    //         ->whereHas('patient.creator', function ($q) use (
    //             $dataEntryEmails,
    //             $isDataEntryUser
    //         ) {
    //             if ($isDataEntryUser) {
    //                 $q->whereIn('email', $dataEntryEmails);
    //             } else {
    //                 $q->whereNotIn('email', $dataEntryEmails);
    //             }
    //         })
    //         ->latest()
    //         ->get();

    //     $boardedOutVirtuals = $boardedOutHistories->map(function ($history) {
    //         $boardedOut = $history->boardedOutLetters->last();
    //         $isBoardedOut = !is_null($boardedOut);
    //         return [
    //             'referral_number' => $isBoardedOut
    //                 ? 'BO-' . $history->patient_histories_id
    //                 : 'NBO-' . $history->patient_histories_id,
    //             'patient' => $history->patient,
    //             'diagnoses' => $history->diagnoses,
    //             'reason' => $history->reason,
    //             'history' => $this->formatHistory($history),
    //             'status' => $isBoardedOut
    //                 ? 'BoardedOut'
    //                 : 'Pending',
    //             'hospitals' => [null],
    //             'referrals' => [
    //                 [
    //                     'referral_id' => null,
    //                     'status' => $isBoardedOut
    //                         ? 'BoardedOut'
    //                         : 'Pending',
    //                     'hospital' => null,
    //                     'created_at' => $boardedOut?->created_at
    //                         ?? $history->created_at,
    //                 ]
    //             ],
    //             'has_pending' => !$isBoardedOut,
    //             'latest_activity' => $boardedOut?->created_at
    //                 ?? $history->updated_at,
    //             'is_boarded_out' => $isBoardedOut,
    //             'history_id' => $history->patient_histories_id,
    //             'boarded_out' => [
    //                 'receiver' => $boardedOut?->receiver,
    //                 'reference_number' => $boardedOut?->reference_number,
    //                 'reference_date' => $boardedOut?->reference_date,
    //                 'recommendations' => $boardedOut?->recommendations,
    //             ]
    //         ];
    //     });
    //     // $boardedOutVirtuals = $boardedOutHistories->map(function ($history) {

    //     //     $boardedOut = $history->boardedOutLetters->last();
    //     //     $isBoardedOut = !is_null($boardedOut);
        
    //     //     // ✅ ADD THIS LINE (key fix)
    //     //     $hasReferral = $history->referrals()->exists();
        
    //     //     return [
    //     //         'referral_number' => $hasReferral
    //     //             ? ($history->referrals()->latest()->first()?->referral_number
    //     //                 ?? 'REF-' . $history->patient_histories_id)
    //     //             : ($isBoardedOut
    //     //                 ? 'BO-' . $history->patient_histories_id
    //     //                 : 'NBO-' . $history->patient_histories_id),
        
    //     //         'patient' => $history->patient,
    //     //         'diagnoses' => $history->diagnoses,
    //     //         'reason' => $history->reason,
    //     //         'history' => $this->formatHistory($history),
        
    //     //         'status' => $isBoardedOut ? 'BoardedOut' : 'Pending',
        
    //     //         'hospitals' => [null],
        
    //     //         'referrals' => [
    //     //             [
    //     //                 'referral_id' => null,
    //     //                 'status' => $isBoardedOut ? 'BoardedOut' : 'Pending',
    //     //                 'hospital' => null,
    //     //                 'created_at' => $boardedOut?->created_at ?? $history->created_at,
    //     //             ]
    //     //         ],
        
    //     //         'has_pending' => !$isBoardedOut,
        
    //     //         'latest_activity' => $boardedOut?->created_at ?? $history->updated_at,
        
    //     //         'is_boarded_out' => $isBoardedOut,
        
    //     //         'history_id' => $history->patient_histories_id,
        
    //     //         'boarded_out' => [
    //     //             'receiver' => $boardedOut?->receiver,
    //     //             'reference_number' => $boardedOut?->reference_number,
    //     //             'reference_date' => $boardedOut?->reference_date,
    //     //             'recommendations' => $boardedOut?->recommendations,
    //     //         ]
    //     //     ];
    //     // });

    //     /*
    //     |--------------------------------------------------------------------------
    //     | FINAL MERGE + SORT
    //     |--------------------------------------------------------------------------
    //     */
    //     $finalData = collect()
    //         ->concat($referrals)
    //         ->concat($virtualReferrals)
    //         ->concat($boardedOutVirtuals)
    //         ->sort(function ($a, $b) {
    //             if ($a['has_pending'] !== $b['has_pending']) {
    //                 return $b['has_pending'] <=> $a['has_pending'];
    //             }
    //             return strtotime($b['latest_activity'])
    //                 <=> strtotime($a['latest_activity']);
    //         })
    //         ->values();

    //     return response([
    //         'data' => $finalData,
    //         'statusCode' => 200
    //     ], 200);
    // }
    //=========================== Previos works fine ===================================
    // public function index()
    // {
    //     $user = auth()->user();

    //     $dataEntryEmails = [
    //         'medicalboard@mohz.go.tz',
    //         'hospital@mohz.go.tz',
    //         'mkurugenzi@mohz.go.tz',
    //         'dguser@mohz.go.tz'
    //     ];

    //     if (!$user->can('View Referral')) {
    //         return response([
    //             'message' => 'Forbidden',
    //             'statusCode' => 403
    //         ], 403);
    //     }

    //     $isDataEntryUser = in_array($user->email, $dataEntryEmails);


    //     // -----------------------------
    //     // REAL REFERRALS
    //     // -----------------------------
    //     $query = Referral::with([
    //             'patient',
    //             'reason',
    //             'hospital',
    //             'diagnoses'
    //         ])
    //         ->where('status', '<>', 'Requested');

    //         // 🔥 EXCLUDE BOARDED OUT PATIENTS
    //         // ->whereNotIn('patient_id', $boardedOutPatientIds);

    //     if ($isDataEntryUser) {

    //         $query->whereHas('patient.creator', function ($q) use ($dataEntryEmails) {
    //             $q->whereIn('email', $dataEntryEmails);
    //         });

    //     } else {

    //         $query->whereHas('patient.creator', function ($q) use ($dataEntryEmails) {
    //             $q->whereNotIn('email', $dataEntryEmails);
    //         });
    //     }

    //     if (!$user->hasRole(['ROLE DIRECTOR GENERAL', 'ROLE ADMIN'])) {
    //         $query->where('status', '<>', 'Pending');
    //     }

    //     $referrals = $query->latest()->get()
    //         ->groupBy('referral_number')
    //         ->map(function ($group) {

    //             $first = $group->first();

    //             $history = PatientHistory::where('patient_id', $first->patient_id ?? null)
    //                 ->latest('created_at')
    //                 ->first();

    //             // return [
    //             $boardedOut = BoardedOutLetter::whereHas('patientHistory', function ($q) use ($first) {
    //                 $q->where('patient_id', $first->patient_id);
    //             })
    //             ->latest()
    //             ->first();
                
    //             $isBoardedOut = $group->contains(function ($ref) {
    //                 return $ref->status === 'BoardedOut';
    //             });
                
    //             return [

    //                 'referral_number' => $first->referral_number,

    //                 'patient' => $first->patient,

    //                 'diagnoses' => $first->diagnoses,

    //                 'reason' => $first->reason,

    //                 'status' => $group->pluck('status')
    //                     ->unique()
    //                     ->sort()
    //                     ->implode(', '),

    //                 'hospitals' => $group->pluck('hospital')
    //                     ->unique('hospital_id')
    //                     ->values(),

    //                 'referrals' => $group->map(function ($ref) {

    //                     return [
    //                         'referral_id' => $ref->referral_id,
    //                         'status' => $ref->status,
    //                         'hospital' => $ref->hospital,
    //                         'created_at' => $ref->created_at,
    //                     ];

    //                 })->values(),

    //                 'has_pending' => $group->contains('status', 'Pending'),

    //                 'latest_activity' => $group->max('created_at'),

    //                 'is_boarded_out' => $isBoardedOut,

    //                 'boarded_out' => $boardedOut ? [
    //                     'receiver' => $boardedOut->receiver,
    //                     'reference_number' => $boardedOut->reference_number,
    //                     'reference_date' => $boardedOut->reference_date,
    //                     'recommendations' => $boardedOut->recommendations,
    //                 ] : null,

    //                 'history_id' => $history?->patient_histories_id,

    //                 'history' => $history
    //                     ? $this->formatHistory($history)
    //                     : null,
    //             ];
    //         })
    //         ->values();

    //     // -----------------------------
    //     // VIRTUAL (REQUESTED + APPROVED)
    //     // -----------------------------
    //     $noReferralHistories = PatientHistory::with([
    //             'patient',
    //             'diagnoses',
    //             'reason'
    //         ])
    //         ->whereDoesntHave('referrals')

    //         // 🔥 PREVENT BOARDED OUT DUPLICATES
    //         ->whereDoesntHave('boardedOutLetters')

    //         ->whereIn('status', ['requested', 'approved'])

    //         ->whereHas('patient.creator', function ($q) use (
    //             $dataEntryEmails,
    //             $isDataEntryUser
    //         ) {

    //             if ($isDataEntryUser) {
    //                 $q->whereIn('email', $dataEntryEmails);
    //             } else {
    //                 $q->whereNotIn('email', $dataEntryEmails);
    //             }
    //         })
    //         ->latest()
    //         ->get();

    //     $virtualReferrals = $noReferralHistories->map(function ($history) {

    //         return [

    //             'referral_number' => 'N/A-' . $history->patient_histories_id,

    //             'patient' => $history->patient,

    //             'diagnoses' => $history->diagnoses,

    //             'reason' => $history->reason,

    //             'history' => $this->formatHistory($history),

    //             'status' => 'Pending',

    //             'hospitals' => [null],

    //             'referrals' => [
    //                 [
    //                     'referral_id' => null,
    //                     'status' => 'Pending',
    //                     'hospital' => null,
    //                     'created_at' => $history->created_at,
    //                 ]
    //             ],

    //             'has_pending' => true,

    //             'latest_activity' => $history->updated_at,

    //             'is_recommendation_only' => true,

    //             'history_id' => $history->patient_histories_id,
    //         ];
    //     });

    //     // -----------------------------
    //     // BOARDED OUT
    //     // -----------------------------
    //     $boardedOutHistories = PatientHistory::with([
    //             'patient',
    //             'diagnoses',
    //             'reason',
    //             'boardedOutLetters'
    //         ])
    //         ->whereHas('boardedOutLetters')

    //         // ONLY histories WITHOUT referrals
    //         ->whereDoesntHave('referrals')

    //         ->whereHas('patient.creator', function ($q) use (
    //             $dataEntryEmails,
    //             $isDataEntryUser
    //         ) {

    //             if ($isDataEntryUser) {
    //                 $q->whereIn('email', $dataEntryEmails);
    //             } else {
    //                 $q->whereNotIn('email', $dataEntryEmails);
    //             }
    //         })
    //         ->latest()
    //         ->get();

    //     $boardedOutVirtuals = $boardedOutHistories->map(function ($history) {

    //         $boardedOut = $history->boardedOutLetters->last();

    //         $isBoardedOut = !is_null($boardedOut);

    //         return [

    //             'referral_number' => $isBoardedOut
    //                 ? 'BO-' . $history->patient_histories_id
    //                 : 'NBO-' . $history->patient_histories_id,

    //             'patient' => $history->patient,

    //             'diagnoses' => $history->diagnoses,

    //             'reason' => $history->reason,

    //             'history' => $this->formatHistory($history),

    //             'status' => $isBoardedOut
    //                 ? 'BoardedOut'
    //                 : 'Pending',

    //             'hospitals' => [null],

    //             'referrals' => [
    //                 [
    //                     'referral_id' => null,
    //                     'status' => $isBoardedOut
    //                         ? 'BoardedOut'
    //                         : 'Pending',

    //                     'hospital' => null,

    //                     'created_at' => $boardedOut?->created_at
    //                         ?? $history->created_at,
    //                 ]
    //             ],

    //             'has_pending' => !$isBoardedOut,

    //             'latest_activity' => $boardedOut?->created_at
    //                 ?? $history->updated_at,

    //             'is_boarded_out' => $isBoardedOut,

    //             'history_id' => $history->patient_histories_id,

    //             'boarded_out' => [
    //                 'receiver' => $boardedOut?->receiver,
    //                 'reference_number' => $boardedOut?->reference_number,
    //                 'reference_date' => $boardedOut?->reference_date,
    //                 'recommendations' => $boardedOut?->recommendations,
    //             ]
    //         ];
    //     });

    //     // -----------------------------
    //     // FINAL MERGE + SORT
    //     // -----------------------------
    //     $finalData = collect()
    //         ->concat($referrals)
    //         ->concat($virtualReferrals)
    //         ->concat($boardedOutVirtuals)
    //         ->sort(function ($a, $b) {

    //             if ($a['has_pending'] !== $b['has_pending']) {
    //                 return $b['has_pending'] <=> $a['has_pending'];
    //             }

    //             return strtotime($b['latest_activity'])
    //                 <=> strtotime($a['latest_activity']);
    //         })
    //         ->values();

    //     return response([
    //         'data' => $finalData,
    //         'statusCode' => 200
    //     ], 200);
    // }
    // ==========================

    private function formatHistory($history)
    {
        if (!$history) return null;

        return [
            'patient_histories_id' => $history->patient_histories_id,
            'case_type' => $history->case_type ?? 'N/A',
            'board_comments' => $history->board_comments ?? 'N/A',
            'status' => $history->status ?? 'unknown',
        ];
    }

    public function getReferralwithBills()
    {
        $user = auth()->user();
        if (!$user->can('View Referral')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $referrals = DB::table('referrals')
            ->join("patients", "patients.patient_id", '=', 'referrals.patient_id')
            ->join("reasons", "reasons.reason_id", '=', 'referrals.reason_id')
            ->leftjoin("bills", "bills.referral_id", '=', 'referrals.referral_id')
            ->select(
                "referrals.*",

                "patients.name as patient_name",
                "patients.date_of_birth",
                "patients.gender",
                "patients.phone",

                "reasons.referral_reason_name",

                "bills.*",
            )
            ->get();

        if ($referrals) {
            return response([
                'data' => $referrals,
                'statusCode' => 200,
            ], 200);
        } else {
            return response([
                'message' => 'No data found',
                'statusCode' => 200,
            ], 200);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * @OA\Post(
     *     path="/api/referrals",
     *     summary="Create referral",
     *     tags={"referrals"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="patient_id", type="integer"),
     *             @OA\Property(property="reason_id", type="integer"),
     *             @OA\Property(property="start_date", type="string", format="date-time"),
     *             @OA\Property(property="end_date", type="string", format="date-time"),
     *             @OA\Property(property="status", type="string"),
     *         ),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        if (
            !$user->can('Create Referral')
        ) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'patient_id' => ['required', 'numeric', 'exists:patients,patient_id'],
            'patient_histories_id' => ['nullable', 'integer', 'exists:patient_histories,patient_histories_id'],
            'reason_id'  => ['required', 'numeric', 'exists:reasons,reason_id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'statusCode' => 422,
            ], 422);
        }

        // --- Generate referral number ---
        $today = now()->format('Y-m-d'); // e.g. 2025-09-01
        $count = Referral::whereDate('created_at', $today)->count() + 1;
        $referralNumber = 'REF-' . $today . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

        $historyQuery = PatientHistory::where('patient_id', $request['patient_id']);
        $history = $request->filled('patient_histories_id')
            ? $historyQuery->find($request->input('patient_histories_id'))
            : (clone $historyQuery)->whereIn('status', ['requested', 'approved'])->get();
        if ($history instanceof \Illuminate\Support\Collection) {
            $history = $history->count() === 1 ? $history->first() : null;
        }
        if (! $history) {
            return response()->json(['message' => 'Select the medical history case for this referral.', 'statusCode' => 422], 422);
        }

        $referral = Referral::create([
            'patient_id'       => $request['patient_id'],
            'patient_histories_id' => $history->patient_histories_id,
            'reason_id'        => $request['reason_id'],
            'status'           => 'Pending',
            'referral_number'  => $referralNumber,
            'created_by'       => Auth::id(),
        ]);

        if ($referral) {
            return response([
                'data'       => $referral,
                'message'    => 'Referral created successfully.',
                'statusCode' => 201,
            ], 201);
        } else {
            return response([
                'message'    => 'Internal server error',
                'statusCode' => 500,
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    /**
     * @OA\Get(
     *     path="/api/referrals/{referral_id}",
     *     summary="Find referral by ID",
     *     tags={"referrals"},
     *     @OA\Parameter(
     *         name="referral_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="referral_id", type="integer"),
     *                     @OA\Property(property="patient_id", type="integer"),
     *                     @OA\Property(property="reason_id", type="integer"),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="confirmed_by", type="string"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="deleted_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    // public function show(int $id)
    // {
    //     $user = auth()->user();

    //     if (!$user->can('View Referral')) {
    //         return response()->json([
    //             'message' => 'Forbidden',
    //             'statusCode' => 403
    //         ], 403);
    //     }

    //     // =========================
    //     // 1. TRY NORMAL REFERRAL
    //     // =========================
    //     $referral = Referral::with([
    //         'patient' => function ($query) {
    //             $query->with([
    //                 'geographicalLocation',
    //                 'files',
    //                 'patientList.boardMembers',
    //                 'patientHistories' => function ($q) {
    //                     $q->orderBy('patient_histories_id', 'desc')->with([
    //                         'diagnoses',
    //                         'boardDiagnoses',
    //                         'reason',
    //                         'boardReason',
    //                     ]);
    //                 },
    //             ]);
    //         },
    //         'hospital',
    //         'hospitalLetters',
    //         'referralLetters',
    //         'parent',
    //         'children',
    //         'bills',
    //         'confirmedBy',
    //         'creator',
    //         'diagnoses',
    //     ])
    //     ->where('referral_id', $id)
    //     ->first();

    //     // =========================
    //     // 2. IF NOT FOUND → TRY HISTORY
    //     // =========================
    //     if (!$referral) {

    //         $history = PatientHistory::with([
    //             'patient' => function ($query) {
    //                 $query->with([
    //                     'geographicalLocation',
    //                     'files',
    //                     'patientList.boardMembers',
    //                     'patientHistories' => function ($q) {
    //                         $q->orderBy('patient_histories_id', 'desc')->with([
    //                             'diagnoses',
    //                             'boardDiagnoses',
    //                             'reason',
    //                             'boardReason',
    //                             'boardedOutLetters'
    //                         ]);
    //                     },
    //                 ]);
    //             },
    //             'diagnoses',
    //             'boardDiagnoses',
    //             'reason',
    //             'boardReason',
    //             'boardedOutLetters'
    //         ])
    //         ->where('patient_histories_id', $id)
    //         ->first();

    //         if (!$history) {
    //             return response()->json([
    //                 'message' => 'Referral not found',
    //                 'statusCode' => 404,
    //             ], 404);
    //         }

    //         // =========================
    //         // 🔥 CONVERT HISTORY → REFERRAL FORMAT
    //         // =========================
    //         $referral = new \stdClass();
    //         $hasBoardedOut = $history->boardedOutLetters()->exists();
    //         $referral->is_boarded_out = $hasBoardedOut;
    //         $referral->boarded_out_letter = $history->boardedOutLetters()->latest()->first();

    //         $referral->referral_id = null;
    //         $referral->referral_number = 'N/A-' . $history->patient_histories_id;
    //         $referral->status = $hasBoardedOut ? 'BoardedOut' : 'Pending';
    //         $referral->hospital = null;
    //         $referral->hospitalLetters = [];
    //         $referral->referralLetters = [];
    //         $referral->parent = null;
    //         $referral->children = [];
    //         $referral->bills = [];
    //         $referral->confirmedBy = null;
    //         $referral->creator = null;
    //         $referral->diagnoses = $history->diagnoses;

    //         // attach patient (IMPORTANT)
    //         $referral->patient = $history->patient;

    //         // flag for frontend (optional but useful)
    //         $referral->is_recommendation_only = true;
    //         $referral->history_id = $history->patient_histories_id;
    //     }

    //     // =========================
    //     // 3. AGE CALCULATION
    //     // =========================
    //     $patient = $referral->patient ?? null;

    //     if ($patient && $patient->date_of_birth) {
    //         if (is_numeric($patient->date_of_birth)) {
    //             $patient->age_details = [
    //                 'years'  => 0, 'months' => 0, 'days' => 0,
    //                 'string' => "Invalid Date Data"
    //             ];
    //         } else {
    //             try {
    //                 $dob = \Carbon\Carbon::parse($patient->date_of_birth);
    //                 $now = \Carbon\Carbon::now();
    //                 $diff = $dob->diff($now);

    //                 $patient->age_details = [
    //                     'years'  => $diff->y,
    //                     'months' => $diff->m,
    //                     'days'   => $diff->d,
    //                     'string' => "{$diff->y}y {$diff->m}m {$diff->d}d"
    //                 ];
    //             } catch (\Exception $e) {
    //                 $patient->age_details = ['string' => "Unknown"];
    //             }
    //         }
    //     }

    //     return response()->json([
    //         'data' => $referral,
    //         'statusCode' => 200,
    //     ], 200);
    // }
    public function show(Request $request, int $id)
    {
        $user = auth()->user();

        if (!$user->can('View Referral')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $type = $request->query('type', 'referral');

        /**
         * ===================================
         * LOAD RELATIONSHIPS
         * ===================================
         */
        $relations = [
            'patient' => function ($query) {
                $query->with([
                    'geographicalLocation',
                    'files',
                    'patientList.boardMembers',
                    'patientHistories' => function ($q) {
                        $q->orderBy('patient_histories_id', 'desc')->with([
                            'diagnoses',
                            'boardDiagnoses',
                            'reason',
                            'boardReason',
                            'boardedOutLetters.printedBy'
                        ]);
                    },
                ]);
            },
            'hospital.referralType',
            'referralFlights',
            'hospitalLetters.printedBy',
            'referralLetters.printedBy',
            'parent',
            'children',
            'bills',
            'confirmedBy',
            'creator',
            'diagnoses',
        ];

        /**
         * ===================================
         * REFERRAL MODE
         * ===================================
         */
        if ($type === 'referral') {

            $referral = Referral::with($relations)
                ->where('referral_id', $id)
                ->first();

            if (!$referral) {
                return response()->json([
                    'message' => 'Referral not found',
                    'statusCode' => 404,
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | GET RELATED HISTORY
            |--------------------------------------------------------------------------
            */
            $history = $referral->patientHistory()->where('patient_id', $referral->patient_id)
                ->with(['boardedOutLetters.printedBy', 'diagnoses', 'boardDiagnoses', 'reason', 'boardReason'])->first();
            $referral->case_history = $history;
            $referral->case_status = $history?->status;
            $referral->case_link_resolved = $history !== null;
            if ($referral->patient) {
                $referral->patient->setRelation('patientHistories', collect($history ? [$history] : []));
            }

            /*
            |--------------------------------------------------------------------------
            | CHECK BOARDED OUT
            |--------------------------------------------------------------------------
            */
            $boardedOutLetter = $history?->boardedOutLetters
                ?->sortByDesc('id')
                ->first();

            $referral->history_id = $history?->patient_histories_id;

            $referral->is_boarded_out = $history?->status === 'boarded_out' || !is_null($boardedOutLetter);

            $referral->boarded_out_letter = $boardedOutLetter;

            if (!$referral) {
                return response()->json([
                    'message' => 'Referral not found',
                    'statusCode' => 404,
                ], 404);
            }
        }

        /**
         * ===================================
         * HISTORY MODE
         * ===================================
         */
        else if ($type === 'history') {

            $history = PatientHistory::with([
                'patient' => function ($query) {
                    $query->with([
                        'geographicalLocation',
                        'files',
                        'patientList.boardMembers',
                        'patientHistories' => function ($q) {
                            $q->orderBy('patient_histories_id', 'desc')->with([
                                'diagnoses',
                                'boardDiagnoses',
                                'reason',
                                'boardReason',
                                'boardedOutLetters.printedBy'
                            ]);
                        },
                    ]);
                },
                'diagnoses',
                'boardDiagnoses',
                'reason',
                'boardReason',
                'boardedOutLetters.printedBy'
            ])
            ->where('patient_histories_id', $id)
            ->first();

            if (!$history) {
                return response()->json([
                    'message' => 'History not found',
                    'statusCode' => 404,
                ], 404);
            }

            $hasBoardedOut = $history->boardedOutLetters()->exists();

            $referral = new \stdClass();

            $referral->is_boarded_out = $hasBoardedOut;
            $referral->boarded_out_letter = $history->boardedOutLetters
                ?->sortByDesc('id')
                ->first();

            $referral->referral_id = null;
            // new
            $hasReferral = $history->referrals()->exists();

            $latestReferral = $history->referrals()
                ->with([
                    'hospital.referralType',
                    'referralLetters',
                    'confirmedBy',
                    'creator',
                ])
                ->latest()
                ->first();

            $hasReferral = !is_null($latestReferral);

            if ($hasReferral) {

                // CASE A: real referral always wins
                $referral->referral_number = $latestReferral?->referral_number;
                $referral->referral_id = $latestReferral?->referral_id;
                $referral->referral_letters = $latestReferral?->referralLetters;
                $referral->hospital_id = $latestReferral?->hospital_id;
                $referral->hospital = $latestReferral?->hospital;

                $referral->confirmedBy = $latestReferral?->confirmed_by;
                $referral->creator = $latestReferral?->creator;

            } else {

                // CASE B & C: follow boarded-out logic
                $referral->referral_number = $hasBoardedOut
                    ? 'BO-' . $history->patient_histories_id
                    : 'NBO-' . $history->patient_histories_id;
            }
            // end new
            $referral->status = $hasBoardedOut ? 'BoardedOut' : 'Pending';

            $referral->hospitalLetters = [];
            $referral->parent = null;
            $referral->children = [];
            $referral->bills = [];

            $referral->diagnoses = $history->diagnoses;
            $referral->patient = $history->patient;
            // Keep the patient at the top level only: the case is also nested
            // in patientHistories, so retaining its patient relation creates
            // a circular response when this history view is serialized.
            $history->unsetRelation('patient');

            $referral->is_recommendation_only = true;
            $referral->history_id = $history->patient_histories_id;
            $referral->case_history = $history;
            $referral->case_status = $history->status;
            if ($referral->patient) {
                $referral->patient->setRelation('patientHistories', collect([$history]));
            }
        }

        else {
            return response()->json([
                'message' => 'Invalid type',
                'statusCode' => 422,
            ], 422);
        }

        // The record page prints the original hospital letter even when the
        // displayed referral/history points to a newer transfer. Transfer
        // follow-up printing continues to use the child's own letter.
        $printSource = $type === 'referral' ? $referral : ($latestReferral ?? null);
        $original = $printSource ? app(\App\Services\TransferReferralService::class)->originalReferral($printSource) : null;
        $original?->load('hospital.referralType');
        $referral->original_referral = $original ? [
            'referral_id' => $original->getKey(),
            'hospital_id' => $original->hospital_id,
            'hospital' => $original->hospital,
            'status' => $original->status,
        ] : null;

        /**
         * ===================================
         * AGE CALCULATION
         * ===================================
         */
        $patient = $referral->patient ?? null;

        if ($patient && $patient->date_of_birth) {

            try {

                if (is_numeric($patient->date_of_birth)) {

                    $patient->age_details = [
                        'years' => 0,
                        'months' => 0,
                        'days' => 0,
                        'string' => 'Invalid Date Data'
                    ];

                } else {

                    $dob = \Carbon\Carbon::parse($patient->date_of_birth);
                    $diff = $dob->diff(now());

                    $patient->age_details = [
                        'years'  => $diff->y,
                        'months' => $diff->m,
                        'days'   => $diff->d,
                        'string' => "{$diff->y}y {$diff->m}m {$diff->d}d"
                    ];
                }

            } catch (\Exception $e) {

                $patient->age_details = [
                    'string' => 'Unknown'
                ];
            }
        }

        return response()->json([
            'data' => $referral,
            'statusCode' => 200,
        ]);
    }

    public function getHospitalLettersByReferralId($id)
    {
        $user = auth()->user();
        if (
            !$user->can('View Referral')
        ) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        // 1. Find the referral
        $referral = Referral::find($id);

        if (!$referral) {
            return response()->json([
                'message' => 'Referral not found',
                'statusCode' => 404
            ], 404);
        }

        // Follow every generation, but never cross a patient or medical-history case.
        $referrals = app(\App\Services\TransferReferralService::class)->chain($referral);
        $referrals->load([
            'patient.geographicalLocation',
            'patient.patientList',
            'patient.files',
            'reason',
            'hospital.referralType',
            'hospitalLetters.followups',
            'hospitalLetters.printedBy',
            'hospitalLetters.transferredReferral.hospital.referralType',
            'hospitalLetters.transferredReferral.referralLetters.printedBy',
        ]);

        if ($referrals->isEmpty()) {
            return response()->json([
                'message' => 'No related referrals found',
                'statusCode' => 404
            ], 404);
        }

        // 4. Build merged response
        $patient   = $referrals->first()->patient;
        if ($patient && $patient->date_of_birth) {
            if (is_numeric($patient->date_of_birth)) {
                $patient->age_details = [
                    'years'  => 0,
                    'months' => 0,
                    'days'   => 0,
                    'string' => "Invalid Date Data"
                ];
            } else {
                try {
                    $dob = \Carbon\Carbon::parse($patient->date_of_birth);
                    $now = \Carbon\Carbon::now();
                    $diff = $dob->diff($now);
        
                    $patient->age_details = [
                        'years'  => $diff->y,
                        'months' => $diff->m,
                        'days'   => $diff->d,
                        'string' => "{$diff->y}y {$diff->m}m {$diff->d}d"
                    ];
                } catch (\Exception $e) {
                    $patient->age_details = [
                        'years'  => 0,
                        'months' => 0,
                        'days'   => 0,
                        'string' => "Unknown"
                    ];
                }
            }
        }

        $reason    = $referrals->first()->reason;
        $hospitals = $referrals->pluck('hospital')->unique('hospital_id')->values();
        $letters   = $referrals->pluck('hospitalLetters')->flatten(1)->values();
        foreach ($letters as $letter) {
            if ($letter->outcome !== 'Transferred') continue;
            $transfer = app(\App\Services\TransferReferralService::class)->transferredReferral($letter);
            $document = $transfer?->referralLetters;
            $letter->setAttribute('transfer_letter', $document ? [
                'referral_id' => $transfer->getKey(),
                'referral_letter_id' => $document->getKey(),
                'is_printed' => (bool) $document->is_printed,
                'printed_at' => $document->printed_at,
                'printed_by' => $document->printedBy,
                'print_count' => (int) $document->print_count,
                'last_printed_language' => $document->last_printed_language,
            ] : null);
        }
        $referralArr = $referrals->map(function ($r) {
            return [
                'referral_id'        => $r->referral_id,
                'parent_referral_id' => $r->parent_referral_id,
                'hospital_id'        => $r->hospital_id,
                'hospital'           => $r->hospital,
                'status'             => $r->status,
                'created_at'         => $r->created_at,
                'updated_at'         => $r->updated_at,
            ];
        });

        $result = [
            'referral_number'  => $referrals->first()->referral_number,
            'status'           => $referrals->pluck('status')->unique()->join(', '),
            'patient'          => $patient,
            'reason'           => $reason,
            'hospitals'        => $hospitals,
            'referrals'        => $referralArr,
            'hospital_letters' => $letters,
        ];

        return response()->json([
            'data'       => $result,
            'statusCode' => 200
        ]);
    }

    public function getReferralsByHospitalId(int $hospitalId, int $billFileId)
    {
        $user = auth()->user();

        // Permission check
        if (!$user->can('View Referral')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        // Fetch referrals that have NOT been billed in this bill file
        $referrals = DB::table('referrals')
            ->join("patients", "patients.patient_id", '=', 'referrals.patient_id')
            ->join("reasons", "reasons.reason_id", '=', 'referrals.reason_id')
            ->join("hospitals", "hospitals.hospital_id", '=', 'referrals.hospital_id')
            ->leftJoin("bills", function ($join) use ($billFileId) {
                $join->on("bills.referral_id", '=', "referrals.referral_id")
                    ->where("bills.bill_file_id", '=', $billFileId);
            })
            ->select(
                "referrals.referral_id",
                "referrals.referral_number",
                "patients.name as patient_name"
            )
            ->where("hospitals.hospital_id", '=', $hospitalId)
            ->whereNull("bills.referral_id") // exclude referrals already billed in this file
            ->get();

        return response()->json([
            'data' => $referrals,
            'statusCode' => 200,
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * @OA\Put(
     *     path="/api/referrals/{referral_id}",
     *     summary="Update referral",
     *     tags={"referrals"},
     *      @OA\Parameter(
     *         name="referral_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *      ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                    @OA\Property(property="patient_id", type="integer"),
     *                    @OA\Property(property="reason_id", type="integer"),
     *                    @OA\Property(property="status"),
     *                 )
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    public function update(Request $request, int $id)
    {
        $user = auth()->user();
        if (!$user->can('Update Referral')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $data = $request->validate([
            'patient_id' => ['required', 'numeric'],
            'reason_id' => ['required', 'numeric'],
            'hospital_id' => ['nullable', 'numeric']
        ]);

        $referral = Referral::findOrFail($id);
        $referral->update([
            'patient_id' => $data['patient_id'],
            'reason_id' => $data['reason_id'],
            'hospital_id' => $data['hospital_id'] ?? null,
            'created_by' => Auth::id(),
        ]);

        if ($referral) {
            return response([
                'data' => $referral,
                'message' => 'Referral updated successfully.',
                'statusCode' => 201,
            ], 201);
        } else {
            return response([
                'message' => 'Internal server error',
                'statusCode' => 500,
            ], 500);
        }
    }

    public function chooseHospitalAndConfirmReferral(Request $request, int $id)
    {
        $user = auth()->user();
        if (!$user->can('Update Referral')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $data = $request->validate([
            'hospital_id' => ['required', 'numeric']
        ]);

        $referral = Referral::findOrFail($id);
        $referral->update([
            'hospital_id' => $data['hospital_id'],
            'status' => 'Confirmed',
            'confirmed_by' => Auth::id(),
        ]);

        if ($referral) {
            return response([
                'data' => $referral,
                'message' => 'Referral confirmed successfully.',
                'statusCode' => 201,
            ], 201);
        } else {
            return response([
                'message' => 'Internal server error',
                'statusCode' => 500,
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * @OA\Delete(
     *     path="/api/referrals/{referral_id}",
     *     summary="Delete referral",
     *     tags={"referrals"},
     *     @OA\Parameter(
     *         name="referral_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function destroy(int $id)
    {
        $user = auth()->user();
        if (!$user->can('Delete Referral')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $referral = Referral::withTrashed()->find($id);

        if (!$referral) {
            return response([
                'message' => 'Referral not found',
                'statusCode' => 404,
            ]);
        }

        $referral->delete();

        return response([
            'message' => 'Referral blocked successfully',
            'statusCode' => 200,
        ], 200);

    }

    /**
     * Unblock
     */
    /**
     * @OA\Patch(
     *     path="/api/referrals/unBlock/{referral_id}",
     *     summary="Unblock referral",
     *     tags={"referrals"},
     *     @OA\Parameter(
     *         name="referral_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function unBlockReferral(int $id)
    {
        $referral = Referral::withTrashed()->find($id);

        if (!$referral) {
            return response([
                'message' => 'Referral not found',
                'statusCode' => 404,
            ], 404);
        }

        $referral->restore($id);

        return response([
            'message' => 'Referral unblocked successfully',
            'statusCode' => 200,
        ], 200);
    }

    public function getReferralsWithBills($referral_id)
    {
        $user = auth()->user();
        if (!$user->can('View Referral')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $referrals = Referral::with('bills')
            ->where('referral_id', $referral_id)
            ->first();

        if (!$referrals) {
            return response()->json(['message' => 'No referrals with bills found'], 404);
        }
        // Append full image URL
        if ($referrals->referral_letter_file) {
            $referrals->documentUrl = asset('storage/' . $referrals->referral_letter_file);
        } else {
            $referrals->documentUrl = null;
        }

        $referrals->bills = $referrals->bills ?? [];
        return response()->json($referrals);
    }

    public function getReferralById(int $referral_id)
    {
        $user = auth()->user();
        if (!$user->can('View Referral')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403
            ], 403);
        }

        $referral = DB::table('referrals')
            ->join("patients", "patients.patient_id", '=', 'referrals.patient_id')
            ->join("reasons", "reasons.reason_id", '=', 'referrals.reason_id')
            ->select(
                "referrals.*",

                "patients.name as patient_name",
                "patients.date_of_birth",
                "patients.gender",
                "patients.phone",

                "reasons.referral_reason_name"
            )
            ->where('referrals.referral_id', '=', $referral_id)
            ->first();

        if ($referral) {
            return response([
                'data' => $referral,
                'statusCode' => 200,
            ], 200);
        } else {
            return response([
                'message' => 'Referral not found',
                'statusCode' => 404,
            ], 200);
        }
    }

}
