<?php

namespace App\Http\Controllers\API\Patients;

use App\Http\Controllers\Controller;
use App\Http\Helpers\Helper;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\PatientHistory;
use App\Models\Referral;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Services\PatientHistoryWorkflowService;
use App\Services\ReasonResolver;
use App\Support\Pagination;
use App\Support\SuperAdminAccess;

/**
 * @OA\Tag(
 *     name="Patient History",
 *     description="API Endpoints for managing patient histories"
 * )
 */

/**
 * @OA\Schema(
 *     schema="Patient History",
 *     type="object",
 *     required={"patient_id"},
 *
 *     @OA\Property(property="patient_histories_id", type="integer", example=1),
 *     @OA\Property(property="patient_id", type="integer", example=1),
 *     @OA\Property(property="referring_doctor", type="string", example="Dr. John Doe"),
 *     @OA\Property(property="file_number", type="string", example="F12345"),
 *     @OA\Property(property="referring_date", type="string", format="date"),
 *     @OA\Property(property="history_of_presenting_illness", type="string"),
 *     @OA\Property(property="physical_findings", type="string"),
 *     @OA\Property(property="investigations", type="string"),
 *     @OA\Property(property="management_done", type="string"),
 *     @OA\Property(property="board_comments", type="string"),
 *     @OA\Property(property="history_file", type="string"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class PatientHistoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * @OA\Get(
     *     path="/api/patient-histories",
     *     tags={"Patient Histories"},
     *     summary="Get all patient histories",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="List of patient histories",
     *
     *         @OA\JsonContent(
     *             type="array",
     *
     *             @OA\Items(ref="#/components/schemas/Patient History")
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        if (! SuperAdminAccess::allowed($user, 'View Patient History')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $search = trim((string) $request->input('search', ''));
        $histories = PatientHistory::with('patient.geographicalLocation', 'diagnoses', 'reason')
            ->when($search !== '', function ($query) use ($search): void {
                $term = mb_strtolower($search);
                $query->whereHas('patient', function ($patientQuery) use ($term): void {
                    $patientQuery->whereRaw('LOWER(name) LIKE ?', [$term.'%'])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$term.'%'])
                        ->orWhereRaw('LOWER(matibabu_card) LIKE ?', [$term.'%']);
                });
            })
            ->when($request->filled('status'), function ($query) use ($request): void {
                $query->where('status', $request->input('status'));
            })
            ->when($request->filled('date_from'), function ($query) use ($request): void {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            })
            ->when($request->filled('date_to'), function ($query) use ($request): void {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            })
            ->latest('patient_histories_id')
            ->paginate(Pagination::perPage($request, 25));

        return response()->json([
            'status' => true,
            'data' => $histories->items(),
            'meta' => Pagination::meta($histories),
            'message' => 'Patient histories retrieved successfully',
            'statusCode' => 200,
        ]);
    }

    // public function getPatientToBeAssignedToMedicalBoard(Request $request)
    // {
    //     $user = auth()->user();

    //     // Permission check
    //     if (!$user->canAny(['View Patient', 'View History'])) {
    //         return response()->json(['message' => 'Forbidden', 'statusCode' => 403], 403);
    //     }

    //     $patientListId = $request->input('patient_list_id');

    //     $patients = Patient::query()
    //         // 1. MUST have a latest history with status 'reviewed'
    //         ->whereHas('latestHistory', function ($q) {
    //             $q->where('status', 'reviewed');
    //         })
    //         // 2. Filter logic for lists
    //         ->when($patientListId, function ($q) use ($patientListId) {
    //             // If we provide a list ID, exclude patients already IN THAT SPECIFIC list
    //             $q->whereDoesntHave('patientList', function ($query) use ($patientListId) {
    //                 $query->where('patient_list_id', $patientListId);
    //             });
    //         })
    //         /* NOTE: I removed the ->when(!$patientListId) block because
    //         it was likely hiding all your patients who are already assigned somewhere.
    //         */
    //         ->with(['latestHistory' => function ($q) {
    //             $q->where('status', 'reviewed')->with(['diagnoses', 'reason']);
    //         }])
    //         ->latest()
    //         ->get();

    //     $result = $patients->map(function ($patient) {
    //         return [
    //             'patient_id' => $patient->patient_id,
    //             'name' => $patient->name,
    //             'phone' => $patient->phone,
    //             'latest_history_id' => $patient->latestHistory->patient_histories_id ?? null,
    //             'latest_history_status' => $patient->latestHistory->status ?? null,
    //         ];
    //     });

    //     return response()->json([
    //         'status' => true,
    //         'data' => $result->values(),
    //         'message' => 'Patients retrieved successfully',
    //         'statusCode' => 200
    //     ]);
    // }

    public function getPatientToBeAssignedToMedicalBoard(Request $request)
    {
        $user = auth()->user();

        // Permission check
        if (! $user->canAny(['View Patient', 'View History'])) {
            return response()->json(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        // Orodha ya emails za data entry
        $dataEntryEmails = [
            'medicalboard@mohz.go.tz',
            'hospital@mohz.go.tz',
            'mkurugenzi@mohz.go.tz',
            'dguser@mohz.go.tz',
        ];

        $isDataEntryUser = in_array($user->email, $dataEntryEmails);
        $patientListId = $request->input('patient_list_id');

        $query = Patient::query()
            // 1. MUST have a latest history with status 'reviewed'
            ->whereHas('latestHistory', function ($q) {
                $q->where('status', 'reviewed');
            })
            // 2. Filter logic kwa ajili ya lists
            ->when($patientListId, function ($q) use ($patientListId) {
                $q->whereDoesntHave('patientList', function ($query) use ($patientListId) {
                    $query->where('patient_list_id', $patientListId);
                });
            });

        // --- LOGIC YA KUTENGANISHA DATA (Kama ulivyoomba) ---
        if ($isDataEntryUser) {
            // Data Entry anaona tu wagonjwa walioingizwa na timu ya Data Entry
            $query->whereHas('creator', function ($q) use ($dataEntryEmails) {
                $q->whereIn('email', $dataEntryEmails);
            });
        } else {
            // Real Users hawaoni data za Data Entry
            $query->whereHas('creator', function ($q) use ($dataEntryEmails) {
                $q->whereNotIn('email', $dataEntryEmails);
            });
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $term = mb_strtolower($search);
            $query->where(function ($query) use ($term): void {
                $query->whereRaw('LOWER(name) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(matibabu_card) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(zan_id) LIKE ?', [$term.'%']);
            });
        }

        $patients = $query->with(['latestHistory' => function ($q) {
            $q->where('status', 'reviewed')->with(['diagnoses', 'reason']);
        }])
            ->latest()
            ->paginate(Pagination::perPage($request, 25));

        $result = collect($patients->items())->map(function ($patient) {
            return [
                'patient_id' => $patient->patient_id,
                'name' => $patient->name,
                'phone' => $patient->phone,
                'latest_history_id' => $patient->latestHistory->patient_histories_id ?? null,
                'latest_history_status' => $patient->latestHistory->status ?? null,
            ];
        });

        return response()->json([
            'status' => true,
            'data' => $result->values(),
            'meta' => Pagination::meta($patients),
            'message' => 'Patients retrieved successfully',
            'statusCode' => 200,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/patient-histories",
     *     tags={"Patient Histories"},
     *     summary="Create a new patient history",
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *             required={"patient_id"},
     *
     *             @OA\Property(property="patient_id", type="integer"),
     *             @OA\Property(property="reason_id", type="integer"),
     *             @OA\Property(property="case_type", type="string"),
     *             @OA\Property(property="file_number", type="string"),
     *             @OA\Property(property="referring_date", type="string", format="date"),
     *             @OA\Property(property="history_of_presenting_illness", type="string"),
     *             @OA\Property(property="physical_findings", type="string"),
     *             @OA\Property(property="investigations", type="string"),
     *             @OA\Property(property="management_done", type="string"),
     *             @OA\Property(property="board_comments", type="string"),
     *             @OA\Property(property="diagnosis_ids", type="array", @OA\Items(type="integer")),
     *             @OA\Property(property="history_file", type="string", format="binary")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Patient history created successfully"),
     *     @OA\Response(response=422, description="Validation failed"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        if (! $user->can('Create Patient History')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'patient_id' => 'required|exists:patients,patient_id',
            'reason_id' => 'nullable|numeric|exists:reasons,reason_id',
            'custom_reason' => 'nullable|string|max:255',
            'case_type' => 'nullable|in:Routine,Emergency',
            'file_number' => 'nullable|string',
            'referring_date' => 'nullable|string',
            'history_of_presenting_illness' => 'nullable|string',
            'physical_findings' => 'nullable|string',
            'investigations' => 'nullable|string',
            'management_done' => 'nullable|string',
            'diagnosis_ids' => 'nullable|array',
            'diagnosis_ids.*' => 'exists:diagnoses,diagnosis_id',
            'history_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
        ]);

        if (! $request->filled('reason_id') && trim((string) $request->input('custom_reason')) === '') {
            $validator->errors()->add('reason_id', 'Select a referral reason or enter a custom reason.');
        }

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'statusCode' => 422,
            ], 422);
        }

        try {
            // Extract only allowed data
            $data = $request->only([
                'patient_id',
                'reason_id',
                'file_number',
                'case_type',
                'referring_date',
                'history_of_presenting_illness',
                'physical_findings',
                'investigations',
                'management_done',
            ]);

            $data['created_by'] = auth()->id();
            $data['referring_doctor'] = trim(
                ($user->first_name ?? '').' '.($user->middle_name ?? '').' '.($user->last_name ?? '')
            );

            /*
            |--------------------------------------------------------------------------
            | BLOCK CREATION BASED ON REFERRAL OR LAST HISTORY STATUS
            |--------------------------------------------------------------------------
            */

            // 1️⃣ Check latest referral
            $latestReferral = \App\Models\Referral::where('patient_id', $data['patient_id'])
                ->latest('created_at')
                ->first();

            $blockedReferralStatuses = [
                'Pending',
                'Confirmed',
                'Death',
                'Transferred',
                'Requested',
            ];

            if ($latestReferral && in_array($latestReferral->status, $blockedReferralStatuses)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create patient history. Last referral status is "'.$latestReferral->status.'".',
                    'statusCode' => 409,
                ], 409);
            }

            // 2. Always check the latest patient history
            $lastHistory = \App\Models\PatientHistory::where('patient_id', $data['patient_id'])
                ->latest('created_at')
                ->first();

            $blockedHistoryStatuses = [
                'confirmed',
                'rejected',
            ];

            if ($lastHistory && in_array(strtolower($lastHistory->status), $blockedHistoryStatuses)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create patient history. Last patient history status is "'.$lastHistory->status.'".',
                    'statusCode' => 409,
                ], 409);
            }

            $data['reason_id'] = app(ReasonResolver::class)->resolve(
                $request->input('reason_id'),
                $request->input('custom_reason')
            );

            // Normalize referring_date
            if (empty($data['referring_date']) || strtolower($data['referring_date']) === 'default') {
                $data['referring_date'] = null;
            } else {
                try {
                    $data['referring_date'] = \Carbon\Carbon::parse($data['referring_date'])->format('Y-m-d');
                } catch (\Exception $e) {
                    $data['referring_date'] = null;
                }
            }

            // Handle optional file upload
            if ($request->hasFile('history_file')) {
                $file = $request->file('history_file');
                $fileName = 'history_'.date('Ymd_His').'.'.$file->getClientOriginalExtension();
                $file->move(public_path('uploads/historyFiles/'), $fileName);
                $data['history_file'] = 'uploads/historyFiles/'.$fileName;
            }

            // Create patient history
            $history = PatientHistory::create($data);

            // Attach diagnoses if provided
            if ($request->filled('diagnosis_ids')) {
                $diagnosisIds = collect($request->diagnosis_ids)->mapWithKeys(function ($id) {
                    return [$id => ['added_by' => 'doctor']];
                })->toArray();

                $history->diagnoses()->syncWithoutDetaching($diagnosisIds);
            }

            return response()->json([
                'status' => true,
                'data' => $history->load('patient', 'diagnoses', 'reason'),
                'message' => 'Patient history created successfully,',
                'statusCode' => 201,
            ], 201);

        } catch (\Exception $e) {
            return Helper::serverError($e, 'Patient history creation failed.');
        }
    }

    /**
     * @OA\Get(
     *     path="/api/patient-histories/{id}",
     *     tags={"Patient Histories"},
     *     summary="Get a patient history by ID",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Patient history found"),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    // public function show($id)
    // {
    //     $user = auth()->user();

    //     if (!$user->canAny(['View Patient History', 'View History'])) {
    //         return response()->json([
    //             'message' => 'Forbidden',
    //             'statusCode' => 403
    //         ], 403);
    //     }

    //     $history = PatientHistory::with([
    //         'patient.geographicalLocation',
    //         'diagnoses',
    //         'boardDiagnoses',
    //         'reason',
    //         'boardReason',
    //         'patient.creator' => function ($query) {
    //             $query->with(['hospitals' => function ($q) {
    //                 $q->select('hospitals.hospital_id', 'hospitals.hospital_name');
    //             }]);
    //         },
    //     ])->find($id);

    //     if (!$history) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Patient history not found',
    //             'statusCode' => 404
    //         ], 404);
    //     }

    //     if ($history->patient && $history->patient->creator) {
    //         $creator = $history->patient->creator;
    //         $firstHospital = $creator->hospitals->first();

    //         $history->hospital = $firstHospital ? $firstHospital->hospital_name : null;
    //         $history->hospital_role = $firstHospital ? $firstHospital->pivot->role : null;
    //     } else {
    //         $history->hospital = null;
    //         $history->hospital_role = null;
    //     }

    //     return response()->json([
    //         'status' => true,
    //         'data' => $history,
    //         'message' => 'Patient history retrieved successfully',
    //         'statusCode' => 200
    //     ]);
    // }
    public function show(int $id)
    {
        $user = auth()->user();

        if (
            ! SuperAdminAccess::allowed($user) &&
            ! $user->canAny(['View Patient History', 'View History'])
        ) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

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
                        ]);
                    },
                    'creator' => function ($q) {
                        $q->with(['hospitals' => function ($h) {
                            $h->select('hospitals.hospital_id', 'hospitals.hospital_name');
                        }]);
                    },
                ]);
            },
            'diagnoses',
            'boardDiagnoses',
            'reason',
            'boardReason',
        ])
            ->where('patient_histories_id', $id)
            ->first();

        if (! $history) {
            return response()->json([
                'status' => false,
                'message' => 'Patient history not found',
                'statusCode' => 404,
            ], 404);
        }

        // --- HOSPITAL LOGIC ---
        if ($history->patient && $history->patient->creator) {
            $creator = $history->patient->creator;
            $firstHospital = $creator->hospitals->first();

            $history->hospital = $firstHospital ? $firstHospital->hospital_name : null;
            $history->hospital_role = $firstHospital ? $firstHospital->pivot->role : null;
        } else {
            $history->hospital = null;
            $history->hospital_role = null;
        }

        // --- AGE CALCULATION LOGIC ---
        $patient = $history->patient;

        if ($patient && $patient->date_of_birth) {
            if (is_numeric($patient->date_of_birth)) {
                $patient->age_details = [
                    'years' => 0, 'months' => 0, 'days' => 0,
                    'string' => 'Invalid Date Data',
                ];
            } else {
                try {
                    $dob = \Carbon\Carbon::parse($patient->date_of_birth);
                    $now = \Carbon\Carbon::now();
                    $diff = $dob->diff($now);

                    $patient->age_details = [
                        'years' => $diff->y,
                        'months' => $diff->m,
                        'days' => $diff->d,
                        'string' => "{$diff->y}y {$diff->m}m {$diff->d}d",
                    ];
                } catch (\Exception $e) {
                    $patient->age_details = ['string' => 'Unknown'];
                }
            }
        }

        return response()->json([
            'status' => true,
            'data' => $history,
            'message' => 'Patient history retrieved successfully',
            'statusCode' => 200,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/patient-histories/update/{id}",
     *     tags={"Patient Histories"},
     *     summary="Update patient history",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="reason_id", type="integer"),
     *             @OA\Property(property="referring_doctor", type="string"),
     *             @OA\Property(property="file_number", type="string"),
     *             @OA\Property(property="referring_date", type="string", format="date"),
     *             @OA\Property(property="history_of_presenting_illness", type="string"),
     *             @OA\Property(property="physical_findings", type="string"),
     *             @OA\Property(property="investigations", type="string"),
     *             @OA\Property(property="management_done", type="string"),
     *             @OA\Property(property="board_comments", type="string"),
     *             @OA\Property(property="diagnosis_ids", type="array", @OA\Items(type="integer")),
     *             @OA\Property(property="history_file", type="string", format="binary")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Updated successfully"),
     *     @OA\Response(response=422, description="Validation failed"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();

        if (! $user->can('Update Patient History')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $history = PatientHistory::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'patient_id' => 'nullable|exists:patients,patient_id', // optional if changing patient
            'reason_id' => 'nullable|numeric|exists:reasons,reason_id',
            'custom_reason' => 'nullable|string|max:255',
            // 'referring_doctor' => 'nullable|string',
            'file_number' => 'nullable|string',
            'referring_date' => 'nullable|string',
            'history_of_presenting_illness' => 'nullable|string',
            'physical_findings' => 'nullable|string',
            'investigations' => 'nullable|string',
            'management_done' => 'nullable|string',
            'diagnosis_ids' => 'nullable|array',
            'diagnosis_ids.*' => 'exists:diagnoses,diagnosis_id',
            'history_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'statusCode' => 422,
            ], 422);
        }

        try {
            $data = $request->only([
                'patient_id',
                'reason_id',
                'case_type',
                'referring_doctor',
                'file_number',
                'referring_date',
                'history_of_presenting_illness',
                'physical_findings',
                'investigations',
                'management_done',
            ]);

            if ($request->filled('reason_id') || trim((string) $request->input('custom_reason')) !== '') {
                $data['reason_id'] = app(ReasonResolver::class)->resolve(
                    $request->input('reason_id'),
                    $request->input('custom_reason')
                );
            } else {
                unset($data['reason_id']);
            }

            // Normalize referring_date
            if (empty($data['referring_date']) || strtolower($data['referring_date']) === 'default') {
                $data['referring_date'] = null;
            } else {
                try {
                    $data['referring_date'] = \Carbon\Carbon::parse($data['referring_date'])->format('Y-m-d');
                } catch (\Exception $e) {
                    $data['referring_date'] = null;
                }
            }

            // Handle file upload
            if ($request->hasFile('history_file')) {
                // Delete old file if exists
                if ($history->history_file && file_exists(public_path($history->history_file))) {
                    unlink(public_path($history->history_file));
                }

                $file = $request->file('history_file');
                $fileName = 'history_'.date('Ymd_His').'.'.$file->getClientOriginalExtension();
                $file->move(public_path('uploads/historyFiles/'), $fileName);
                $data['history_file'] = 'uploads/historyFiles/'.$fileName;
            }

            // Update patient history
            $history->update($data);

            // Sync diagnoses if provided
            if ($request->filled('diagnosis_ids')) {
                $history->diagnoses()->sync($request->diagnosis_ids);
            }

            return response()->json([
                'status' => true,
                'data' => $history->load('patient', 'diagnoses', 'reason'),
                'message' => 'Patient history updated successfully',
                'statusCode' => 200,
            ]);

        } catch (\Exception $e) {
            return Helper::serverError($e, 'Patient history update failed.');
        }
    }

    /**
     * @OA\Put(
     *     path="/api/patient-histories/{id}/medical-board",
     *     operationId="updateByMedicalBoard",
     *     tags={"Patient Histories"},
     *     summary="Update patient history by Medical Board",
     *     description="Allows a Medical Board member to update patient history status, comments, reason, and diagnoses. Automatically creates a referral if board diagnoses are provided.",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Patient history ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="string", enum={"pending","reviewed","Requested","approved","confirmed","rejected"}, example="reviewed", description="Workflow status"),
     *             @OA\Property(property="board_comments", type="string", example="Patient requires additional investigation", description="Comments by medical board"),
     *             @OA\Property(property="board_reason_id", type="integer", example=2, description="Reason ID associated with the board update"),
     *             @OA\Property(
     *                 property="board_diagnosis_ids",
     *                 type="array",
     *                 description="List of diagnosis IDs assigned by the medical board",
     *
     *                 @OA\Items(type="integer", example=5)
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Patient history updated and referral created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="patient_histories_id", type="integer", example=1),
     *                 @OA\Property(property="patient_id", type="integer", example=10),
     *                 @OA\Property(property="status", type="string", example="reviewed"),
     *                 @OA\Property(property="board_comments", type="string", example="Patient requires additional investigation"),
     *                 @OA\Property(property="board_reason_id", type="integer", example=2),
     *                 @OA\Property(property="diagnoses", type="array",
     *
     *                     @OA\Items(
     *
     *                         @OA\Property(property="diagnosis_id", type="integer", example=5),
     *                         @OA\Property(property="diagnosis_name", type="string", example="Hypertension")
     *                     )
     *                 ),
     *                 @OA\Property(property="patient", type="object",
     *                     @OA\Property(property="patient_id", type="integer", example=10),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="phone", type="string", example="255712345678")
     *                 )
     *             ),
     *             @OA\Property(property="message", type="string", example="Patient history updated and referral created successfully by Medical Board"),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - user not a Medical Board member",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Forbidden"),
     *             @OA\Property(property="statusCode", type="integer", example=403)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="errors", type="object"),
     *             @OA\Property(property="statusCode", type="integer", example=422)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Update failed"),
     *             @OA\Property(property="error", type="string"),
     *             @OA\Property(property="statusCode", type="integer", example=500)
     *         )
     *     )
     * )
     */
    public function updateByMedicalBoardWithReferralCreation(Request $request, $id)
    {
        $user = auth()->user();
        $history = PatientHistory::findOrFail($id);

        if (! $user->hasRole('ROLE MEDICAL BOARD MEMBER')) {
            return response()->json(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        $validator = Validator::make($request->all(), [
            'board_comments' => 'required|string',
            'board_reason_id' => 'nullable|numeric|exists:reasons,reason_id',
            'custom_reason' => 'nullable|string|max:255',
            'board_diagnosis_ids' => 'required|array',
            'board_diagnosis_ids.*' => 'exists:diagnoses,diagnosis_id',
            'patient_file' => 'nullable|file|mimes:pdf,jpg,png,doc,docx|max:5000',
            'description' => 'nullable|string',
            // NEW: Flag to determine if a referral record is actually needed
            'create_referral_record' => 'required|boolean',
        ]);

        if (! $request->filled('board_reason_id') && trim((string) $request->input('custom_reason')) === '') {
            $validator->errors()->add('board_reason_id', 'Select a board reason or enter a custom reason.');
        }

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors(), 'statusCode' => 422], 422);
        }

        $createReferral = $request->boolean('create_referral_record');

        try {
            DB::beginTransaction();
            $history = PatientHistory::query()
                ->lockForUpdate()
                ->findOrFail($id);
            $workflow = app(PatientHistoryWorkflowService::class);
            $boardReasonId = app(ReasonResolver::class)->resolve(
                $request->input('board_reason_id'),
                $request->input('custom_reason')
            );
            $fromStatus = $history->status;
            $beforeSnapshot = $workflow->snapshot($history, []);
            $referral = null;

            // 1. Always Update Board findings (Uchunguzi/Maamuzi)
            $history->update([
                'board_comments' => $request->board_comments,
                'board_reason_id' => $boardReasonId,
            ]);

            // 2. Sync diagnoses to the history (added_by medical_board)
            if ($request->filled('board_diagnosis_ids')) {
                $boardDiagnoses = collect($request->board_diagnosis_ids)->mapWithKeys(function ($diagId) {
                    return [$diagId => ['added_by' => 'medical_board']];
                })->toArray();
                $history->boardDiagnoses()->syncWithoutDetaching($boardDiagnoses);
            }

            // 3. Conditional Referral Creation
            // Only creates the record if the board determines an external referral is necessary
            if ($createReferral) {
                $today = now()->format('Y-m-d');
                $count = Referral::whereDate('created_at', $today)->count() + 1;
                $referralNumber = 'REF-'.$today.'-'.str_pad($count, 4, '0', STR_PAD_LEFT);

                $referral = Referral::create([
                    'patient_id' => $history->patient_id,
                    'reason_id' => $boardReasonId,
                    'status' => 'Requested',
                    'referral_number' => $referralNumber,
                    'created_by' => $user->id,
                ]);

                $referral->diagnoses()->sync($request->board_diagnosis_ids);
            }

            // 4. Standard Workflow Progress
            // Move status to 'requested' so the DG can see the Maamuzi in their list
            $this->applyStatusUpdate($history, 'requested', $request->board_comments, $user);

            // 5. Handle File Uploads
            if ($request->hasFile('patient_file')) {
                $file = $request->file('patient_file');
                $extension = $file->getClientOriginalExtension();
                $newFileName = 'patient_file_'.date('His_dmY').'_'.uniqid().'.'.$extension;
                $file->move(public_path('uploads/patientFiles/'), $newFileName);

                \App\Models\PatientFile::create([
                    'patient_id' => $history->patient_id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => 'uploads/patientFiles/'.$newFileName,
                    'file_type' => $extension,
                    'description' => $request->description,
                    'uploaded_by' => $user->id,
                ]);
            }

            $workflow->record(
                $history,
                'medical_board_referral_decision',
                $fromStatus,
                $history->status,
                $beforeSnapshot,
                [
                    'create_referral_record' => $createReferral,
                    'referral_id' => $referral?->referral_id,
                ],
                $referral ? $workflow->referralTreeSnapshotIds($referral->referral_id) : [],
            );

            DB::commit();

            return response()->json([
                'status' => true,
                'data' => $history->load(['patient', 'diagnoses', 'reason']),
                'message' => $createReferral
                            ? 'Referral created and sent to DG for approval'
                            : 'Medical evaluation (Maamuzi) sent to DG for approval',
                'statusCode' => 200,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return Helper::serverError($e, 'Medical Board update failed.');
        }
    }

    public function updateByMedicalBoardWithoutReferralCreation(Request $request, $id)
    {
        $user = auth()->user();
        $history = PatientHistory::findOrFail($id);

        if (! $user->hasRole('ROLE MEDICAL BOARD MEMBER')) {
            return response()->json(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        $validator = Validator::make($request->all(), [
            'board_comments' => 'required|string',
            'board_reason_id' => 'nullable|numeric|exists:reasons,reason_id',
            'custom_reason' => 'nullable|string|max:255',
            'board_diagnosis_ids' => 'required|array',
            'board_diagnosis_ids.*' => 'exists:diagnoses,diagnosis_id',
            'patient_file' => 'nullable|file|mimes:pdf,jpg,png,doc,docx|max:5000',
            'description' => 'nullable|string',
            'create_referral_record' => 'required|boolean',
        ]);

        if (! $request->filled('board_reason_id') && trim((string) $request->input('custom_reason')) === '') {
            $validator->errors()->add('board_reason_id', 'Select a board reason or enter a custom reason.');
        }

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors(), 'statusCode' => 422], 422);
        }

        try {
            DB::beginTransaction();
            $history = PatientHistory::query()
                ->lockForUpdate()
                ->findOrFail($id);
            $workflow = app(PatientHistoryWorkflowService::class);
            $boardReasonId = app(ReasonResolver::class)->resolve(
                $request->input('board_reason_id'),
                $request->input('custom_reason')
            );
            $fromStatus = $history->status;

            $referral = Referral::query()
                ->where('patient_id', $history->patient_id)
                ->whereNotIn('status', ['Closed', 'Cancelled', 'Expired', 'BoardedOut'])
                ->latest('referral_id')
                ->lockForUpdate()
                ->first();
            $beforeReferralIds = $referral
                ? $workflow->referralTreeSnapshotIds($referral->referral_id)
                : [];
            $beforeSnapshot = $workflow->snapshot($history, $beforeReferralIds);

            // 1. Update Board fields in History
            $history->update([
                'board_comments' => $request->board_comments,
                'board_reason_id' => $boardReasonId,
            ]);

            // 2. Sync Board Diagnoses (Sync ensures removals are processed)
            $boardDiagnoses = collect($request->board_diagnosis_ids)->mapWithKeys(function ($diagId) {
                return [$diagId => ['added_by' => 'medical_board']];
            })->toArray();
            $history->boardDiagnoses()->sync($boardDiagnoses);

            // 3. Synchronize the referral decision.
            // A recommendation-only submission must not create a referral. If
            // the doctor later checks the option during edit, create the
            // referral at that point. Existing active referrals are reused so
            // repeated edits never create duplicates.
            $createReferral = $request->boolean('create_referral_record');
            $referralWasDeleted = false;
            // If Mkurugenzi already approved this history before the board
            // enabled the referral option, the new referral must enter the DG
            // queue directly. Otherwise it remains Requested, while the
            // referral list intentionally hides Requested records.
            $alreadyApprovedByMkurugenzi = $history->status === 'approved'
                && filled($history->mkurugenzi_tiba_comments);
            $newReferralStatus = $alreadyApprovedByMkurugenzi ? 'Pending' : 'Requested';
            if ($createReferral) {
                if (! $referral) {
                    $today = now()->format('Y-m-d');
                    $count = Referral::whereDate('created_at', $today)->count() + 1;
                    $referralNumber = 'REF-'.$today.'-'.str_pad($count, 4, '0', STR_PAD_LEFT);

                    $referral = Referral::create([
                        'patient_id' => $history->patient_id,
                        'reason_id' => $boardReasonId,
                        'status' => $newReferralStatus,
                        'referral_number' => $referralNumber,
                        'created_by' => $user->id,
                    ]);
                } else {
                    $referralUpdates = [
                        'reason_id' => $boardReasonId,
                    ];

                    // Repair a referral created by the previous edit flow if
                    // the history has already been approved by Mkurugenzi.
                    if ($alreadyApprovedByMkurugenzi && $referral->status === 'Requested') {
                        $referralUpdates['status'] = 'Pending';
                    }

                    $referral->update($referralUpdates);
                }

                $referral->diagnoses()->sync($request->board_diagnosis_ids);
            } elseif ($referral) {
                // The board explicitly changed an existing referral into a
                // recommendation-only outcome. This is intentionally a hard
                // delete, including the referral's dependent records.
                $this->permanentlyDeleteReferralTree($referral);
                $referral = null;
                $referralWasDeleted = true;
            }

            // 5. Handle Patient File (Replace logic)
            if ($request->hasFile('patient_file')) {
                $file = $request->file('patient_file');
                $extension = $file->getClientOriginalExtension();
                $newFileName = 'patient_file_'.date('His_dmY').'_'.uniqid().'.'.$extension;
                $file->move(public_path('uploads/patientFiles/'), $newFileName);

                // Update existing or create new file record
                \App\Models\PatientFile::updateOrCreate(
                    [
                        'patient_id' => $history->patient_id,
                        'uploaded_by' => $user->id,
                        'description' => $request->description, // Matches specific update
                    ],
                    [
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => 'uploads/patientFiles/'.$newFileName,
                        'file_type' => $extension,
                    ]
                );
            }

            $eventReferralIds = $referral
                ? $workflow->referralTreeSnapshotIds($referral->referral_id)
                : $beforeReferralIds;
            $workflow->record(
                $history,
                'medical_board_referral_decision',
                $fromStatus,
                $history->status,
                $beforeSnapshot,
                [
                    'create_referral_record' => $createReferral,
                    'referral_id' => $referral?->referral_id,
                    'referral_deleted' => $referralWasDeleted,
                ],
                $eventReferralIds,
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $history->load(['patient.referrals', 'boardDiagnoses', 'boardReason']),
                'message' => $createReferral
                    ? ($referral->wasRecentlyCreated
                        ? ($referral->status === 'Pending'
                            ? 'Referral created and queued for DG confirmation'
                            : 'Referral created and sent to Mkurugenzi Tiba for approval')
                        : 'Referral details updated successfully')
                    : ($referralWasDeleted
                        ? 'Recommendation saved; the referral and all related records were permanently deleted'
                        : 'Recommendation saved; no referral record was created'),
                'referral_deleted' => $referralWasDeleted,
                'referral_status' => $referral?->status,
                'statusCode' => 200,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return Helper::serverError($e, 'Medical Board update failed.');
        }
    }

    /**
     * Permanently delete a referral and every record that depends on it.
     *
     * Referral records use soft deletes, but this operation is explicitly
     * destructive because the Medical Board has changed the case to a
     * recommendation-only outcome. Dependent rows are removed first so this
     * also works on databases where the foreign keys are not cascading.
     */
    private function permanentlyDeleteReferralTree(Referral $referral): int
    {
        $referralIds = collect([$referral->getKey()]);
        $frontier = $referralIds->all();

        // Include any child referrals created from this referral (for example,
        // a referral letter can create a linked child referral).
        while (! empty($frontier)) {
            $children = DB::table('referrals')
                ->whereIn('parent_referral_id', $frontier)
                ->pluck('referral_id')
                ->diff($referralIds)
                ->values();

            if ($children->isEmpty()) {
                break;
            }

            $referralIds = $referralIds->merge($children)->unique()->values();
            $frontier = $children->all();
        }

        $ids = $referralIds->values()->all();

        // Bills and their dependent items/payment allocations must be removed
        // before the referral can be deleted.
        $billIds = DB::table('bills')
            ->whereIn('referral_id', $ids)
            ->pluck('bill_id');

        if ($billIds->isNotEmpty()) {
            $paymentIds = DB::table('bill_payments')
                ->whereIn('bill_id', $billIds)
                ->pluck('payment_id')
                ->unique()
                ->values();

            DB::table('bill_payments')->whereIn('bill_id', $billIds)->delete();
            DB::table('bill_items')->whereIn('bill_id', $billIds)->delete();
            DB::table('bills')->whereIn('bill_id', $billIds)->delete();

            // A payment may be shared by another bill. Remove it only when no
            // remaining allocation references it.
            if ($paymentIds->isNotEmpty()) {
                DB::table('payments')
                    ->whereIn('payment_id', $paymentIds)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('bill_payments')
                            ->whereColumn('bill_payments.payment_id', 'payments.payment_id');
                    })
                    ->delete();
            }
        }

        // Hospital letters own follow-up records, so remove follow-ups first.
        $letterIds = DB::table('hospital_letters')
            ->whereIn('referral_id', $ids)
            ->pluck('letter_id');

        if ($letterIds->isNotEmpty()) {
            DB::table('followups')->whereIn('letter_id', $letterIds)->delete();
        }

        DB::table('hospital_letters')->whereIn('referral_id', $ids)->delete();
        DB::table('referral_letters')->whereIn('referral_id', $ids)->delete();
        DB::table('treatments')->whereIn('referral_id', $ids)->delete();
        DB::table('referral_flights')->whereIn('referral_id', $ids)->delete();
        DB::table('diagnosis_referral')->whereIn('referral_id', $ids)->delete();

        // Delete children before their parents because parent_referral_id is a
        // self-referencing foreign key.
        foreach ($referralIds->reverse() as $referralId) {
            DB::table('referrals')->where('referral_id', $referralId)->delete();
        }

        return count($ids);
    }

    public function getMedicalBoardUpdate($id)
    {
        try {
            $user = auth()->user();

            // 1. Fetch history with specific board-added relations using your table column names
            $history = PatientHistory::with([
                'patient:patient_id',
                'boardDiagnoses' => function ($query) {
                    $query->wherePivot('added_by', 'medical_board')
                        ->select('diagnoses.diagnosis_id', 'diagnoses.diagnosis_name', 'diagnoses.diagnosis_code');
                },
                'boardReason:reason_id,referral_reason_name',
                'referrals' => function ($query) {
                    $query->whereNotIn('status', ['Closed', 'Cancelled', 'BoardedOut', 'Expired'])
                        ->latest('referral_id');
                },
            ])->findOrFail($id);

            // 2. Fetch the specific file uploaded by this board member
            $patientFile = \App\Models\PatientFile::where('patient_id', $history->patient_id)
                ->where('uploaded_by', $user->id)
                ->latest()
                ->first();

            // 3. Structure the response for your React/Vite UI
            $boardData = [
                'patient_histories_id' => $history->patient_histories_id,
                'board_comments' => $history->board_comments,
                'board_reason_id' => $history->board_reason_id,

                // Full Reason Object for the dropdown/select initial value
                'board_reason' => $history->boardReason ? [
                    'reason_id' => $history->boardReason->reason_id,
                    'referral_reason_name' => $history->boardReason->referral_reason_name,
                ] : null,

                // The Raw ID for basic form state
                // 'board_reason_id'     => $history->board_reason_id,

                // Full Diagnosis Objects (useful for multi-select tags/chips)
                'board_diagnoses' => $history->boardDiagnoses->map(function ($diagnosis) {
                    return [
                        'diagnosis_id' => $diagnosis->diagnosis_id,
                        'diagnosis_name' => $diagnosis->diagnosis_name,
                        'diagnosis_code' => $diagnosis->diagnosis_code,
                    ];
                }),

                // Just the IDs (useful for setting the initial state of a checkbox list)
                // 'board_diagnosis_ids' => $history->boardDiagnoses->pluck('diagnosis_id'),

                // Patient File Information
                'patient_file' => [
                    'name' => $patientFile ? $patientFile->file_name : null,
                    'url' => $patientFile ? asset($patientFile->file_path) : null,
                    'type' => $patientFile ? $patientFile->file_type : null,
                ],
                'referrals' => $history->referrals->values(),
            ];

            return response()->json([
                'success' => true,
                'data' => $boardData,
                'statusCode' => 200,
            ]);

        } catch (\Exception $e) {
            return Helper::serverError($e, 'Medical Board data retrieval failed.');
        }
    }

    public function updateByMkurugenzi(Request $request, $id)
    {
        $user = auth()->user();

        // if (!$user->hasRole('ROLE MKURUGENZI TIBA','ROLE SUPERVISOR')) {
        if (! $user->hasAnyRole(['ROLE MKURUGENZI TIBA', 'ROLE SUPERVISOR'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action for your role.',
                'statusCode' => 403,
            ], 403);
        }

        // Load history + patient + referrals that are "Requested"
        $history = PatientHistory::with([
            'patient',
            'referrals' => function ($q) {
                $q->where('status', 'Requested');
            },
        ])->find($id);

        // history not found
        if (! $history) {
            return response()->json([
                'status' => false,
                'message' => 'Patient history not found.',
                'statusCode' => 404,
            ], 404);
        }

        // Validation
        $validator = Validator::make($request->all(), [
            'mkurugenzi_tiba_comments' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
                'statusCode' => 422,
            ], 422);
        }

        try {
            DB::beginTransaction();
            $history = PatientHistory::query()
                ->lockForUpdate()
                ->findOrFail($id);
            $referral = Referral::query()
                ->where('patient_id', $history->patient_id)
                ->where('status', 'Requested')
                ->latest('referral_id')
                ->lockForUpdate()
                ->first();
            $workflow = app(PatientHistoryWorkflowService::class);
            $fromStatus = $history->status;
            $beforeReferralIds = $referral
                ? $workflow->referralTreeSnapshotIds($referral->referral_id)
                : [];
            $beforeSnapshot = $workflow->snapshot($history, $beforeReferralIds);

            // Check if comments are empty BEFORE we assign the new ones.
            $isFirstTime = empty($history->mkurugenzi_tiba_comments);

            // Save Mkurugenzi Tiba comments
            $history->mkurugenzi_tiba_comments = $request->mkurugenzi_tiba_comments;
            $history->mkurugenzi_tiba_id = $user->id;
            $history->save();

            // --- UPDATED LOGIC ---
            // Only update the referral status if this is the FIRST time adding comments
            // if ($isFirstTime && $referral) {
            //     // 3 Update referral status
            //     $referral->status = 'Pending';
            //     $referral->save();

            //     // 4 Apply overall history status update
            //     $this->applyStatusUpdate(
            //         $history,
            //         'approved',
            //         $request->mkurugenzi_tiba_comments,
            //         $user
            //     );
            // }
            if ($isFirstTime) {

                if ($referral) {
                    // ✅ NORMAL REFERRAL FLOW
                    $referral->status = 'Pending';
                    $referral->save();

                    $this->applyStatusUpdate(
                        $history,
                        'approved',
                        $request->mkurugenzi_tiba_comments,
                        $user
                    );

                } else {
                    // ✅ NO REFERRAL FLOW (IMPORTANT)
                    $this->applyStatusUpdate(
                        $history,
                        'approved',
                        $request->mkurugenzi_tiba_comments,
                        $user
                    );
                }
            }

            $eventReferralIds = $referral
                ? $workflow->referralTreeSnapshotIds($referral->referral_id)
                : $beforeReferralIds;
            $workflow->record(
                $history,
                'mkurugenzi_tiba_decision',
                $fromStatus,
                $history->status,
                $beforeSnapshot,
                [
                    'referral_id' => $referral?->referral_id,
                    'referral_status' => $referral?->status,
                    'first_approval' => $isFirstTime,
                ],
                $eventReferralIds,
            );

            DB::commit();

            // 5 Return full response
            return response()->json([
                'status' => true,
                'data' => [
                    'history' => $history->load('patient', 'diagnoses', 'reason'),
                    'referral' => $referral ? $referral->load('diagnoses', 'reason') : null,
                ],
                'message' => 'Referral approved updated successfully',
                'statusCode' => 200,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return Helper::serverError($e, 'Referral approval update failed.');
        }
    }

    public function getMkurugenziComments($id)
    {
        $user = auth()->user();

        // if (!$user->hasRole('ROLE MKURUGENZI TIBA')) {
        if (! $user->hasAnyRole(['ROLE MKURUGENZI TIBA', 'ROLE SUPERVISOR'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action for your role.',
                'statusCode' => 403,
            ], 403);
        }

        // Find the history and only select the columns we need for performance
        $history = PatientHistory::select('patient_histories_id', 'mkurugenzi_tiba_comments', 'mkurugenzi_tiba_id', 'updated_at')
            ->with('mkurugenzi:id,first_name,last_name') // Assuming you have a 'mkurugenzi' relationship on the model
            ->find($id);

        if (! $history) {
            return response()->json([
                'success' => false,
                'message' => 'Patient history not found.',
                'statusCode' => 404,
            ], 404);
        }

        // Verify if the logged-in user is the one who actually wrote the comment
        if ($history->mkurugenzi_tiba_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You did not comment on this patient history.',
                'statusCode' => 403,
            ], 403);
        }

        // Check if comments actually exist yet
        if (! $history->mkurugenzi_tiba_comments) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No comments have been provided by Mkurugenzi Tiba yet.',
                'statusCode' => 200,
            ], 200);
        }

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'data' => [
                'patient_history_id' => $history->patient_histories_id,
                'mkurugenzi_tiba_comments' => $history->mkurugenzi_tiba_comments,
                'commented_on' => $history->updated_at->format('d M Y H:i'),
                'human_date' => $history->updated_at->diffForHumans(),
            ],
        ], 200);
    }

    /**
     * @OA\Delete(
     *     path="/api/patient-histories/{id}",
     *     tags={"Patient Histories"},
     *     summary="Delete patient history",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Deleted successfully"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function destroy($id)
    {
        $user = auth()->user();
        if (! $user->can('Delete Patient History')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $history = PatientHistory::findOrFail($id);
        $history->delete();

        return response()->json([
            'status' => true,
            'message' => 'Patient history deleted successfully',
            'statusCode' => 200,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/patient-histories/unblock/{id}",
     *     tags={"Patient Histories"},
     *     summary="Unblock a patient history",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Unblocked successfully"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function unblock($id)
    {
        $user = auth()->user();
        if (! $user->can('Update Patient History')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $history = PatientHistory::withTrashed()->findOrFail($id);
        $history->restore();

        return response()->json([
            'status' => true,
            'message' => 'Patient history unblocked successfully',
            'statusCode' => 200,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/patient-histories/update-status/{id}",
     *     tags={"Patient Histories"},
     *     summary="Update patient history status",
     *     description="Allows authorized roles to update the status of a patient history record, add comments, and track reviewers.",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Patient history ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="status",
     *                 type="string",
     *                 enum={"pending","reviewed","requested","approved","confirmed","rejected"},
     *                 description="New status to set"
     *             ),
     *             @OA\Property(
     *                 property="comment",
     *                 type="string",
     *                 description="Optional comment for the reviewer"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Status updated successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Invalid status transition",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized action for your role",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Patient history not found",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function updateStatus(Request $request, $id)
    {
        $user = Auth::user();
        $validator = \Validator::make($request->all(), [
            'status' => 'required|string|in:pending,reviewed,assigned,requested,approved,confirmed,rejected',
            'comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $newStatus = $validated['status'];
        $comment = $validated['comment'] ?? null;

        try {
            $workflow = app(PatientHistoryWorkflowService::class);
            $history = DB::transaction(function () use ($id, $user, $newStatus, $comment, $workflow) {
                $history = PatientHistory::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (! $this->isValidTransition($history->status, $newStatus)) {
                    throw new \RuntimeException("Invalid status transition from {$history->status} to {$newStatus}.");
                }

                $fromStatus = $history->status;
                $beforeSnapshot = $workflow->snapshot($history, []);

                switch ($newStatus) {
                    case 'reviewed':
                        if (! $user->hasAnyRole(['ROLE MKURUGENZI TIBA', 'ROLE SUPERVISOR'])) {
                            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Unauthorized action for your role.');
                        }
                        $history->mkurugenzi_tiba_id = $user->id;
                        $history->mkurugenzi_tiba_comments = $comment;
                        break;

                    case 'assigned':
                        if (! $user->hasAnyRole(['ROLE MEDICAL BOARD MEMBER'])) {
                            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Unauthorized action for your role.');
                        }
                        break;

                    case 'requested':
                        if (! $user->hasRole('ROLE MEDICAL BOARD MEMBER')) {
                            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Unauthorized action for your role.');
                        }
                        $history->board_comments = $comment;
                        break;

                    case 'approved':
                        if (! $user->hasAnyRole(['ROLE MKURUGENZI TIBA', 'ROLE SUPERVISOR'])) {
                            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Unauthorized action for your role.');
                        }
                        $history->board_comments = $comment;
                        break;

                    case 'confirmed':
                        if (! $user->hasRole('ROLE DIRECTOR GENERAL')) {
                            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Unauthorized action for your role.');
                        }
                        $history->dg_id = $user->id;
                        $history->dg_comments = $comment;
                        break;

                    case 'rejected':
                        if (! $user->hasAnyRole(['ROLE MKURUGENZI TIBA', 'ROLE SUPERVISOR', 'ROLE MEDICAL BOARD MEMBER', 'ROLE DIRECTOR GENERAL'])) {
                            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Unauthorized action for your role.');
                        }
                        $history->board_comments = $comment;
                        break;
                }

                $history->status = $newStatus;
                $history->save();
                $workflow->record(
                    $history,
                    'status_transition',
                    $fromStatus,
                    $newStatus,
                    $beforeSnapshot,
                    ['status_change' => true],
                    [],
                );

                return $history;
            });
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'statusCode' => 403,
            ], 403);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'statusCode' => 400,
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => "Status updated successfully to {$newStatus}.",
            'data' => $history,
        ]);
    }

    /**
     * Define allowed workflow transitions
     */
    private function isValidTransition($current, $next)
    {
        $allowed = [
            'pending' => ['reviewed'],
            'reviewed' => ['assigned', 'requested'], // Director can assign to board or request info
            'assigned' => ['requested', 'approved'], // Board's primary actions
            'requested' => ['reviewed', 'approved'],  // Path after info is provided
            'approved' => ['confirmed', 'rejected'], // Moves to DG for final say
            'confirmed' => [],
            'rejected' => [],
        ];

        return in_array($next, $allowed[$current] ?? []);
    }

    private function unauthorized()
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized action for your role.',
            'statusCode' => 403,
        ], 403);
    }

    private function applyStatusUpdate(PatientHistory $history, $newStatus, $comment = null, $user = null)
    {
        $user = $user ?? auth()->user();

        // Ensure only valid status transitions
        if (! $this->isValidTransition($history->status, $newStatus)) {
            throw new \Exception("Invalid status transition from {$history->status} to {$newStatus}.");
        }

        switch ($newStatus) {
            case 'reviewed':
                $history->mkurugenzi_tiba_id = $user->id;
                $history->mkurugenzi_tiba_comments = $comment;
                break;

            case 'assigned':
                $history->board_comments = $comment;
                break;

            case 'requested':
                $history->board_comments = $comment;
                break;

            case 'approved':
                $history->mkurugenzi_tiba_comments = $comment;
                break;

            case 'confirmed':
                $history->dg_id = $user->id;
                $history->dg_comments = $comment;
                break;

            case 'rejected':
                $history->board_comments = $comment;
                break;
        }

        $history->status = $newStatus;
        $history->save();

        return $history;
    }
}
