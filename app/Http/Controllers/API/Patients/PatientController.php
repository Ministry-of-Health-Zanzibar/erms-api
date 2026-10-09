<?php

namespace App\Http\Controllers\API\Patients;

use App\Http\Controllers\Controller;
use App\Http\Helpers\Helper;
use App\Mail\NewPatientRecordNotification;
use App\Models\Patient;
use App\Models\PatientFile;
use App\Services\MatibabuService;
use App\Services\PatientHistoryWorkflowService;
use App\Services\ReasonResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use App\Support\Pagination;
use App\Support\SuperAdminAccess;

class PatientController extends Controller
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
     *     path="/api/patients",
     *     summary="Get all patients",
     *     tags={"Patients"},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object",
     *
     *                     @OA\Property(property="patient_id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="date_of_birth", type="string", format="date-time"),
     *                     @OA\Property(property="gender", type="string"),
     *                     @OA\Property(property="phone", type="string"),
     *                     @OA\Property(property="location", type="string"),
     *                     @OA\Property(property="job", type="string"),
     *                     @OA\Property(property="position", type="string"),
     *                     @OA\Property(property="patient_list_id", type="integer"),
     *                     @OA\Property(property="created_by", type="integer", example=1),
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

        if (! $user->can('View Patient')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        // Orodha ya emails za Data Entry
        $dataEntryEmails = [
            'medicalboard@mohz.go.tz',
            'hospital@mohz.go.tz',
            'mkurugenzi@mohz.go.tz',
            'dguser@mohz.go.tz',
        ];

        $isDataEntryUser = in_array($user->email, $dataEntryEmails);

        // Mahusiano (Relations)
        $relations = [
            'patientList',
            'files',
            'insurances',
            'geographicalLocation',
            'referrals.reason',
            'referrals.hospital',
            'referrals.creator',
            'creator' => function ($query) {
                $query->with(['hospitals' => function ($q) {
                    $q->select('hospitals.hospital_id', 'hospitals.hospital_name');
                }]);
            },
        ];

        // Table views request only the columns they render. Other consumers keep
        // the original detailed response, so existing forms and flows are intact.
        $query = $request->boolean('summary')
            ? Patient::query()->select([
                'patient_id',
                'name',
                'matibabu_card',
                'zan_id',
                'date_of_birth',
                'gender',
                'phone',
                'location_id',
                'position',
                'job',
                'deleted_at',
                'created_by',
                'created_at',
                DB::raw('(SELECT location_name FROM geographical_locations gl WHERE gl.location_id = patients.location_id LIMIT 1) as location'),
            ])
            : Patient::with($relations);

        /**
         * 1. KAMA NI ADMIN: Anaona kila kitu (hata zilizofutwa)
         */
        if ($user->hasAnyRole(['ROLE ADMIN'])) {
            $query->withTrashed();
        }
        /**
         * 2. KAMA NI DATA ENTRY: Anaona data za timu nzima ya Data Entry
         */
        elseif ($isDataEntryUser) {
            $query->whereHas('creator', function ($q) use ($dataEntryEmails) {
                $q->whereIn('email', $dataEntryEmails);
            });
        }
        /**
         * 3. KAMA NI HOSPITAL USER WA KAWAIDA: Anaona zake tu
         */
        elseif ($user->hasAnyRole(['ROLE HOSPITAL USER', 'ROLE MEDICAL BOARD MEMBER'])) {
            $query->where('created_by', $user->id);
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

        $query->when($request->filled('date_from'), function ($query) use ($request): void {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        })->when($request->filled('date_to'), function ($query) use ($request): void {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        });

        $sort = in_array($request->input('sort'), ['created_at', 'name', 'patient_id'], true)
            ? $request->input('sort')
            : 'created_at';
        $direction = strtolower((string) $request->input('direction', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $patients = $query
            ->orderBy($sort, $direction)
            ->orderBy('patient_id', 'desc')
            ->paginate(Pagination::perPage($request, 25));

        return response([
            'data' => $patients->items(),
            'meta' => Pagination::meta($patients),
            'statusCode' => 200,
        ], 200);
    }

    public function patientsHistories(Request $request)
    {
        $user = auth()->user();

        // Orodha ya emails za data entry
        $dataEntryEmails = [
            'medicalboard@mohz.go.tz',
            'hospital@mohz.go.tz',
            'mkurugenzi@mohz.go.tz',
            'dguser@mohz.go.tz',
        ];

        if (
            ! SuperAdminAccess::allowed($user) &&
            ! $user->canAny(['View Patient', 'View History'])
        ) {
            return response(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        $isMkurugenzi = $user->hasRole('ROLE MKURUGENZI TIBA');
        $isDataEntryUser = in_array($user->email, $dataEntryEmails);
        $isSuperAdmin = SuperAdminAccess::allowed($user);

        // Resolve the latest history once and join it to the patient list.
        // The previous correlated subquery was repeated in the filter and
        // ordering expressions for every patient row.
        $latestHistoryIds = DB::table('patient_histories')
            ->selectRaw('MAX(patient_histories_id)')
            ->whereNull('deleted_at')
            ->groupBy('patient_id');
        $latestHistoryQuery = DB::table('patient_histories as latest_histories')
            ->select(
                'latest_histories.patient_id',
                DB::raw('latest_histories.status as latest_status_text'),
            )
            ->whereIn('latest_histories.patient_histories_id', $latestHistoryIds);

        $query = Patient::query()
            ->with(['latestHistory', 'creator'])
            ->join('users', 'users.id', '=', 'patients.created_by')
            ->joinSub($latestHistoryQuery, 'latest_history', function ($join): void {
                $join->on('latest_history.patient_id', '=', 'patients.patient_id');
            })
            ->whereHas('patientHistories');

        // --- LOGIC MPYA YA KUTENGANISHA DATA ---
        if ($isSuperAdmin) {
            // Super Admin must be able to review every patient history,
            // including records created by hospital and medical-board users.
        } elseif ($isDataEntryUser) {
            // Data Entry Users wanaona tu data zilizoundwa na wenzao
            $query->whereIn('users.email', $dataEntryEmails);
        } else {
            // Real Users hawaoni data za Data Entry Users
            $query->whereNotIn('users.email', $dataEntryEmails);
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $term = mb_strtolower($search);
            $query->where(function ($query) use ($term): void {
                $query->whereRaw('LOWER(patients.name) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(patients.phone) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(patients.matibabu_card) LIKE ?', [$term.'%'])
                    ->orWhereRaw('LOWER(patients.zan_id) LIKE ?', [$term.'%']);
            });
        }

        if ($request->filled('status')) {
            $query->where('latest_history.latest_status_text', $request->input('status'));
        }

        $patients = $query
            ->select(
                'patients.*',
                DB::raw("(SELECT h.hospital_name FROM hospital_user hu JOIN hospitals h ON h.hospital_id = hu.hospital_id WHERE hu.user_id = users.id ORDER BY hu.hospital_id LIMIT 1) as hospital"),
                DB::raw("(SELECT hu.role FROM hospital_user hu WHERE hu.user_id = users.id ORDER BY hu.hospital_id LIMIT 1) as hospital_role"),
                'latest_history.latest_status_text',
            )
            ->orderByRaw("
                CASE
                    WHEN latest_history.latest_status_text = 'pending' THEN 1
                    WHEN latest_history.latest_status_text = 'requested' THEN ".($isMkurugenzi ? '2' : '4')."
                    WHEN latest_history.latest_status_text = 'reviewed' THEN ".($isMkurugenzi ? '3' : '2')."
                    WHEN latest_history.latest_status_text = 'assigned' THEN ".($isMkurugenzi ? '4' : '3')."
                    WHEN latest_history.latest_status_text = 'approved' THEN 5
                    WHEN latest_history.latest_status_text = 'confirmed' THEN 6
                    WHEN latest_history.latest_status_text = 'boarded_out' THEN 6
                    WHEN latest_history.latest_status_text = 'rejected' THEN 7
                    ELSE 8
                END ASC
            ")
            ->orderBy('patients.patient_id', 'desc')
            ->paginate(Pagination::perPage($request, 25));

        return response(
            [
                'data' => $patients->items(),
                'meta' => Pagination::meta($patients),
                'statusCode' => 200,
            ],
            200
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * @OA\Post(
     *     path="/api/patients",
     *     summary="Create patient",
     *     tags={"Patients"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *                 required={"name","patient_list_id"},
     *
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="matibabu_card", type="string"),
     *                 @OA\Property(property="zan_id", type="string"),
     *                 @OA\Property(property="date_of_birth", type="string", format="date"),
     *                 @OA\Property(property="gender", type="string"),
     *                 @OA\Property(property="phone", type="string"),
     *                 @OA\Property(property="location", type="string"),
     *                 @OA\Property(property="job", type="string"),
     *                 @OA\Property(property="position", type="string"),
     *                 @OA\Property(property="patient_list_id", type="integer"),
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional patient file (PDF, Image, Doc, etc.)"
     *                 ),
     *                 @OA\Property(
     *                     property="description",
     *                     type="string",
     *                     description="Optional file description"
     *                 ),
     *                 @OA\Property(property="insurance_provider_name", type="string", description="Insurance provider name"),
     *                 @OA\Property(property="card_number", type="string", description="Insurance card number"),
     *                 @OA\Property(property="valid_until", type="string", format="date", description="Insurance valid until date"),
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Patient created successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Patient created successfully."),
     *             @OA\Property(property="statusCode", type="integer", example=201)
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=500, description="Internal Server Error")
     * )
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (! $user->can('Create Patient')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        // Normalize boolean from Angular ("true"/"false" → true/false)
        $request->merge([
            'has_insurance' => filter_var($request->has_insurance, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = Validator::make($request->all(), [
            'name' => ['required', 'string'],
            'matibabu_card' => ['nullable', 'string'],
            'zan_id' => ['nullable', 'string'],
            'date_of_birth' => ['required', 'string'],
            'gender' => ['required', 'string'],
            'phone' => ['nullable', 'string'],
            'location_id' => ['nullable', 'string', 'exists:geographical_locations,location_id'],
            'job' => ['nullable', 'string'],
            'position' => ['nullable', 'string'],

            // Make patient_list_id optional
            'patient_list_id' => ['nullable', 'numeric', 'exists:patient_lists,patient_list_id'],

            'patient_file.*' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xlsx'],
            'description' => ['nullable', 'string'],

            // Optional insurance validation
            'has_insurance' => ['required', 'boolean'],
            'insurance_provider_name' => ['nullable', 'string'],
            'card_number' => ['nullable', 'string'],
            'valid_until' => ['nullable', 'string'],
        ]);

        if ($data->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $data->errors(),
                'statusCode' => 422,
            ], 422);
        }

        $patientList = null;

        // If a patient_list_id is given, validate and check capacity
        if ($request->filled('patient_list_id')) {
            $patientList = \App\Models\PatientList::find($request->patient_list_id);

            if (! $patientList) {
                return response()->json([
                    'message' => "Invalid Patient List ID: {$request->patient_list_id}",
                    'statusCode' => 404,
                ], 404);
            }

            // Check patient count limit using pivot
            $existingCount = \App\Models\Patient::whereHas('patientList', function ($query) use ($patientList) {
                $query->where('patient_lists.patient_list_id', $patientList->patient_list_id);
            })->count();

            if ($existingCount >= $patientList->no_of_patients) {
                return response()->json([
                    'message' => "The Medical Board (ID: {$patientList->patient_list_id}) already reached its patient limit ({$patientList->no_of_patients}).",
                    'statusCode' => 422,
                ], 422);
            }
        }

        // Create the patient (patient_list_id optional)
        $patient = \App\Models\Patient::create([
            'name' => $request['name'],
            'matibabu_card' => $request['matibabu_card'],
            'zan_id' => $request['zan_id'],
            'date_of_birth' => $request['date_of_birth'],
            'gender' => $request['gender'],
            'phone' => $request['phone'],
            'location_id' => $request['location_id'],
            'job' => $request['job'],
            'position' => $request['position'],
            // use optional() helper instead of nullsafe operator
            'patient_list_id' => optional($patientList)->patient_list_id,
            'created_by' => Auth::id(),
        ]);

        // Attach to list via pivot only if list provided
        if ($patientList) {
            $patient->patientList()->attach($patientList->patient_list_id);
        }

        // Optional Insurance creation
        if ($request->filled('has_insurance') && $request->boolean('has_insurance') === true) {
            $insuranceProvider = $request->insurance_provider_name ?: null;
            $cardNumber = $request->card_number ?: null;
            $validUntil = $request->valid_until ?: null;

            // Only create if not already existing for that patient
            $existingInsurance = \App\Models\Insurance::where('patient_id', $patient->patient_id)->first();

            if (! $existingInsurance) {
                \App\Models\Insurance::create([
                    'patient_id' => $patient->patient_id,
                    'insurance_provider_name' => $insuranceProvider,
                    'card_number' => $cardNumber,
                    'valid_until' => $validUntil,
                ]);
            }
        }

        // File Upload
        if ($request->hasFile('patient_file')) {
            $files = $request->file('patient_file');
            if (! is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                $extension = $file->getClientOriginalExtension();
                $newFileName = 'patient_file_'.date('h-i-s_a_d-m-Y').'.'.$extension;
                $file->move(public_path('uploads/patientFiles/'), $newFileName);
                $filePath = 'uploads/patientFiles/'.$newFileName;

                \App\Models\PatientFile::create([
                    'patient_id' => $patient->patient_id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $filePath,
                    'file_type' => $file->getClientMimeType(),
                    'description' => $request->input('description') ?? null,
                    'uploaded_by' => Auth::id(),
                ]);
            }
        }

        // Response
        return response([
            'data' => $patient->load(['files', 'insurances']),
            'message' => $patientList
                ? 'Patient created successfully and linked to patient list.'
                : 'Patient created successfully (not linked to any patient list).',
            'statusCode' => 201,
        ], 201);
    }

    public function storePatientAndHistory(Request $request)
    {
        $user = auth()->user();

        // 1. Authorization
        if (! $user->can('Create Patient')) {
            return response(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        // --- LOGIC MPYA YA VALIDATION ---
        $isDataEntry = ($user->email === 'hospital@mohz.go.tz');
        $requirement = $isDataEntry ? 'nullable' : 'required';

        $request->merge([
            'has_insurance' => filter_var($request->has_insurance, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        // 3. Robust Validation - Imetumika $requirement kwa fields husika
        $validator = Validator::make($request->all(), [
            'name' => [$requirement, 'string', 'max:255'],
            'matibabu_card' => [$requirement, 'string', 'max:50'], // Hii itaruhusu nullable kama ni hospital user
            'zan_id' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => [$requirement, 'string'],
            'gender' => [$requirement, 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'location_id' => ['nullable', 'exists:geographical_locations,location_id'],
            'job' => ['nullable', 'string'],
            'position' => ['nullable', 'string'],

            'file_number' => ['nullable', 'string'],
            'referring_date' => ['nullable', 'string'],
            'reason_id' => ['nullable', 'numeric', 'exists:reasons,reason_id'],
            'custom_reason' => ['nullable', 'string', 'max:255'],
            'case_type' => ['required', 'in:Emergency,Routine'],
            'history_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
            'diagnosis_ids' => ['nullable', 'array'],
            'diagnosis_ids.*' => ['exists:diagnoses,diagnosis_id'],

            'has_insurance' => ['required', 'boolean'],
            'insurance_provider_name' => ['nullable', 'string'],
            'card_number' => ['nullable', 'string'],
            'valid_until' => ['nullable', 'string'],
        ]);

        if (! $request->filled('reason_id') && trim((string) $request->input('custom_reason')) === '') {
            $validator->errors()->add('reason_id', 'Select a referral reason or enter a custom reason.');
        }

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors(), 'statusCode' => 422], 422);
        }

        if (! $isDataEntry) {
            // Endelea na logic iliyobaki kama ilivyokuwa...
            if (! $this->isPatientEligible($request->matibabu_card)) {
                return response()->json(['message' => 'Patient has an active referral process.', 'statusCode' => 403], 200);
            }
        }

        DB::beginTransaction();
        try {
            $reasonId = app(ReasonResolver::class)->resolve(
                $request->input('reason_id'),
                $request->input('custom_reason')
            );

            // 5. UPSERT PATIENT
            if ($isDataEntry) {
                // 5a. KWA DATA ENTRY: Tengeneza mgonjwa mpya kila wakati (Hata kama kadi inafanana au ni null)
                $patient = \App\Models\Patient::create([
                    'matibabu_card' => $request->matibabu_card,
                    'name' => $request->name,
                    'zan_id' => $request->zan_id,
                    'date_of_birth' => $request->date_of_birth,
                    'gender' => $request->gender,
                    'phone' => $request->phone,
                    'location_id' => $request->location_id,
                    'job' => $request->job,
                    'position' => $request->position,
                    'created_by' => Auth::id(),
                ]);
            } else {
                // 5b. KWA WATUMIAJI WENGINE: Tafuta kadi, kama ipo update, kama haipo tengeneza
                $patient = \App\Models\Patient::updateOrCreate(
                    ['matibabu_card' => $request->matibabu_card],
                    [
                        'name' => $request->name,
                        'zan_id' => $request->zan_id,
                        'date_of_birth' => $request->date_of_birth,
                        'gender' => $request->gender,
                        'phone' => $request->phone,
                        'location_id' => $request->location_id,
                        'job' => $request->job,
                        'position' => $request->position,
                        'created_by' => Auth::id(),
                    ]
                );
            }

            // ... (Kodi nyingine zote zinabaki vilevile)
            $doctorName = trim(($user->first_name ?? '').' '.($user->middle_name ?? '').' '.($user->last_name ?? ''));

            $patientHistory = \App\Models\PatientHistory::create([
                'patient_id' => $patient->patient_id,
                'referring_doctor' => $doctorName,
                'file_number' => $request->file_number,
                'referring_date' => $request->referring_date,
                'reason_id' => $reasonId,
                'case_type' => $request->case_type,
                'history_of_presenting_illness' => $request->history_of_presenting_illness,
                'physical_findings' => $request->physical_findings,
                'investigations' => $request->investigations,
                'management_done' => $request->management_done,
                // Hospital submissions go straight to the Medical Board queue.
                // That queue only includes patients whose latest history is reviewed.
                'status' => 'reviewed',
            ]);

            if ($request->filled('diagnosis_ids')) {
                $diagnosisData = collect($request->diagnosis_ids)->mapWithKeys(function ($id) {
                    return [$id => ['added_by' => 'doctor']];
                })->toArray();
                $patientHistory->diagnoses()->sync($diagnosisData);
            }

            if ($request->boolean('has_insurance')) {
                \App\Models\Insurance::updateOrCreate(
                    ['patient_id' => $patient->patient_id],
                    [
                        'insurance_provider_name' => $request->insurance_provider_name,
                        'card_number' => $request->card_number,
                        'valid_until' => $request->valid_until,
                    ]
                );
            }

            if ($request->hasFile('history_file')) {
                $file = $request->file('history_file');
                $fileName = 'history_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                $file->move(public_path('uploads/historyFiles'), $fileName);
                $patientHistory->update(['history_file' => 'uploads/historyFiles/'.$fileName]);
            }

            DB::commit();

            if (! $isDataEntry) {
                // NOTIFICATIONS
                try {
                    $directors = \App\Models\User::role('ROLE MKURUGENZI TIBA')
                        ->where('email', '!=', 'mkurugenzi@mohz.go.tz')
                        ->get();

                    // Use the Notification facade's route method for the external email
                    Notification::route('mail', 'talhiyay@gmail.com')
                        ->notify(new NewPatientRecordNotification($patient, $patientHistory));

                    // Notify the internal directors
                    Notification::send($directors, new NewPatientRecordNotification($patient, $patientHistory));
                } catch (\Exception $e) {
                    \Log::error('Notification failed: '.$e->getMessage());
                }
            }

            return response([
                'data' => ['patient' => $patient, 'history' => $patientHistory],
                'message' => 'Record processed successfully',
                'statusCode' => 201,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return Helper::serverError($e, 'Failed to process the patient record.');
        }
    }

    /**
     * Register a patient with their history and automatically process the
     * history workflow up to the "Mkurugenzi Tiba" approval stage (status = 'approved').
     *
     * A Referral record is also created (status = 'Requested') so that the next
     * step (Director General confirmation = 'confirmed') can be completed manually.
     */
    public function storePatientAndHistoryAutoApproved(Request $request)
    {
        $user = auth()->user();

        // 1. Authorization
        if (! $user->can('Create Patient')) {
            return response(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        // 2. Normalize boolean from Angular ("true"/"false" → true/false)
        $request->merge([
            'has_insurance' => filter_var($request->has_insurance, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        // 3. Validation - same rules as a normal registration
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'matibabu_card' => ['nullable', 'string', 'max:50'],
            'zan_id' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['required', 'string'],
            'gender' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'location_id' => ['nullable', 'exists:geographical_locations,location_id'],
            'job' => ['nullable', 'string'],
            'position' => ['nullable', 'string'],

            'file_number' => ['nullable', 'string'],
            'referring_date' => ['nullable', 'string'],
            'reason_id' => ['nullable', 'numeric', 'exists:reasons,reason_id'],
            'custom_reason' => ['nullable', 'string', 'max:255'],
            'case_type' => ['required', 'in:Emergency,Routine'],
            'history_of_presenting_illness' => ['nullable', 'string'],
            'physical_findings' => ['nullable', 'string'],
            'investigations' => ['nullable', 'string'],
            'management_done' => ['nullable', 'string'],
            'history_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
            'diagnosis_ids' => ['nullable', 'array'],
            'diagnosis_ids.*' => ['exists:diagnoses,diagnosis_id'],

            'has_insurance' => ['required', 'boolean'],
            'insurance_provider_name' => ['nullable', 'string'],
            'card_number' => ['nullable', 'string'],
            'valid_until' => ['nullable', 'string'],
        ]);

        if (! $request->filled('reason_id') && trim((string) $request->input('custom_reason')) === '') {
            $validator->errors()->add('reason_id', 'Select a referral reason or enter a custom reason.');
        }

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors(), 'statusCode' => 422], 422);
        }

        DB::beginTransaction();
        try {
            $reasonId = app(ReasonResolver::class)->resolve(
                $request->input('reason_id'),
                $request->input('custom_reason')
            );

            // 4. Create the patient
            $patient = \App\Models\Patient::create([
                'name' => $request->name,
                'matibabu_card' => $request->matibabu_card,
                'zan_id' => $request->zan_id,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'phone' => $request->phone,
                'location_id' => $request->location_id,
                'job' => $request->job,
                'position' => $request->position,
                'created_by' => Auth::id(),
            ]);

            // 5. Build referring doctor name
            $doctorName = trim(($user->first_name ?? '').' '.($user->middle_name ?? '').' '.($user->last_name ?? ''));

            // 6. Create the patient history (status starts at 'pending')
            $patientHistory = \App\Models\PatientHistory::create([
                'patient_id' => $patient->patient_id,
                'referring_doctor' => $doctorName,
                'file_number' => $request->file_number,
                'referring_date' => $request->referring_date,
                'reason_id' => $reasonId,
                'case_type' => $request->case_type,
                'history_of_presenting_illness' => $request->history_of_presenting_illness,
                'physical_findings' => $request->physical_findings,
                'investigations' => $request->investigations,
                'management_done' => $request->management_done,
                'status' => 'pending',
            ]);

            // 7. Attach doctor diagnoses
            if ($request->filled('diagnosis_ids')) {
                $diagnosisData = collect($request->diagnosis_ids)->mapWithKeys(function ($id) {
                    return [$id => ['added_by' => 'doctor']];
                })->toArray();
                $patientHistory->diagnoses()->sync($diagnosisData);
            }

            // 8. Handle history file upload
            if ($request->hasFile('history_file')) {
                $file = $request->file('history_file');
                $fileName = 'history_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                $file->move(public_path('uploads/historyFiles'), $fileName);
                $patientHistory->update(['history_file' => 'uploads/historyFiles/'.$fileName]);
            }

            // 9. Handle insurance
            if ($request->boolean('has_insurance')) {
                \App\Models\Insurance::updateOrCreate(
                    ['patient_id' => $patient->patient_id],
                    [
                        'insurance_provider_name' => $request->insurance_provider_name,
                        'card_number' => $request->card_number,
                        'valid_until' => $request->valid_until,
                    ]
                );
            }

            // ------------------------------------------------------------------
            // 10. AUTO-PROCESS WORKFLOW UP TO MKURUGENZI TIBA APPROVAL
            //     pending -> reviewed -> assigned -> approved
            //     The last step (DG confirmation = 'confirmed') is left MANUAL.
            // ------------------------------------------------------------------
            $autoComment = 'Auto-approved by the system during registration (Mkurugenzi Tiba stage).';
            $workflow = app(PatientHistoryWorkflowService::class);
            $workflowBeforeSnapshot = $workflow->snapshot($patientHistory, []);
            $workflowFromStatus = $patientHistory->status;

            // pending -> reviewed
            $patientHistory->update([
                'status' => 'reviewed',
                'mkurugenzi_tiba_id' => $user->id,
                'mkurugenzi_tiba_comments' => 'Reviewed automatically during registration.',
            ]);

            // reviewed -> assigned
            $patientHistory->update([
                'status' => 'assigned',
            ]);

            // assigned -> approved (Mkurugenzi Tiba approval)
            $patientHistory->update([
                'status' => 'approved',
                'mkurugenzi_tiba_comments' => $autoComment,
            ]);

            // ------------------------------------------------------------------
            // 11. CREATE REFERRAL RECORD (status 'Requested') ready for DG manual confirmation
            // ------------------------------------------------------------------
            $today = now()->format('Y-m-d');
            $count = \App\Models\Referral::whereDate('created_at', $today)->count() + 1;
            $referralNumber = 'REF-'.$today.'-'.str_pad($count, 4, '0', STR_PAD_LEFT);

            $referral = \App\Models\Referral::create([
                'patient_id' => $patient->patient_id,
                'patient_histories_id' => $patientHistory->patient_histories_id,
                'reason_id' => $reasonId,
                'status' => 'Requested',
                'referral_number' => $referralNumber,
                'created_by' => $user->id,
            ]);

            if ($request->filled('diagnosis_ids')) {
                $referral->diagnoses()->sync($request->diagnosis_ids);
            }

            $workflow->record(
                $patientHistory,
                'auto_approved_registration',
                $workflowFromStatus,
                $patientHistory->status,
                $workflowBeforeSnapshot,
                ['referral_id' => $referral->referral_id],
                $workflow->referralTreeSnapshotIds($referral->referral_id),
            );

            DB::commit();

            return response([
                'data' => [
                    'patient' => $patient,
                    'history' => $patientHistory->load('diagnoses', 'reason'),
                    'referral' => $referral->load('diagnoses', 'reason'),
                ],
                'message' => 'Patient registered and history auto-approved up to Mkurugenzi Tiba. Awaiting manual DG confirmation.',
                'statusCode' => 201,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return Helper::serverError($e, 'Failed to process the patient record.');
        }
    }


    public function updatePatientAndHistory(Request $request, $patient_id)
    {
        $user = auth()->user();

        if (! $user->can('Update Patient')) {
            return response(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        // --- LOGIC MPYA YA VALIDATION (Kama ilivyo kwenye Store) ---
        $isDataEntry = ($user->email === 'hospital@mohz.go.tz');
        $requirement = $isDataEntry ? 'nullable' : 'required';

        $request->merge([
            'has_insurance' => filter_var($request->has_insurance, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        // Validation - Inatumia $requirement kwa fields husika
        $validator = Validator::make($request->all(), [
            'name' => [$requirement, 'string', 'max:255'],
            'matibabu_card' => [$requirement, 'string', 'max:50'],
            'zan_id' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => [$requirement, 'string'],
            'gender' => [$requirement, 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'location_id' => ['nullable', 'exists:geographical_locations,location_id'],
            'job' => ['nullable', 'string'],
            'position' => ['nullable', 'string'],

            'file_number' => ['nullable', 'string'],
            'referring_date' => ['nullable', 'string'],
            'reason_id' => ['nullable', 'numeric', 'exists:reasons,reason_id'],
            'custom_reason' => ['nullable', 'string', 'max:255'],
            'case_type' => ['required', 'in:Emergency,Routine'],
            'history_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
            'diagnosis_ids' => ['nullable', 'array'],
            'diagnosis_ids.*' => ['exists:diagnoses,diagnosis_id'],
            'history_of_presenting_illness' => ['nullable', 'string'],
            'physical_findings' => ['nullable', 'string'],
            'investigations' => ['nullable', 'string'],
            'management_done' => ['nullable', 'string'],

            'has_insurance' => ['required', 'boolean'],
            'insurance_provider_name' => ['nullable', 'string'],
            'card_number' => ['nullable', 'string'],
            'valid_until' => ['nullable', 'string'],
        ]);

        if (! $request->filled('reason_id') && trim((string) $request->input('custom_reason')) === '') {
            $validator->errors()->add('reason_id', 'Select a referral reason or enter a custom reason.');
        }

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors(), 'statusCode' => 422], 422);
        }

        DB::beginTransaction();
        try {
            $reasonId = app(ReasonResolver::class)->resolve(
                $request->input('reason_id'),
                $request->input('custom_reason')
            );

            // 1. Tafuta mgonjwa
            $patient = \App\Models\Patient::findOrFail($patient_id);

            // 2. Update taarifa za mgonjwa
            $patient->update([
                'name' => $request->name,
                'matibabu_card' => $request->matibabu_card,
                'zan_id' => $request->zan_id,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'phone' => $request->phone,
                'location_id' => $request->location_id,
                'job' => $request->job,
                'position' => $request->position,
            ]);

            // 3. Tafuta historia ya hivi karibuni (Latest History)
            $patientHistory = \App\Models\PatientHistory::where('patient_id', $patient_id)
                ->latest('patient_histories_id')
                ->first();

            if (! $patientHistory) {
                return response(['message' => 'No medical history found for this patient to update.', 'statusCode' => 404], 404);
            }

            // 4. Update historia
            $patientHistory->update([
                'file_number' => $request->file_number,
                'referring_date' => $request->referring_date,
                'reason_id' => $reasonId,
                'case_type' => $request->case_type,
                'history_of_presenting_illness' => $request->history_of_presenting_illness,
                'physical_findings' => $request->physical_findings,
                'investigations' => $request->investigations,
                'management_done' => $request->management_done,
            ]);

            // 5. Update Diagnoses
            if ($request->filled('diagnosis_ids')) {
                $diagnosisData = collect($request->diagnosis_ids)->mapWithKeys(function ($id) {
                    return [$id => ['added_by' => 'doctor']];
                })->toArray();
                $patientHistory->diagnoses()->sync($diagnosisData);
            }

            // 6. Update au Create Insurance
            if ($request->boolean('has_insurance')) {
                \App\Models\Insurance::updateOrCreate(
                    ['patient_id' => $patient->patient_id],
                    [
                        'insurance_provider_name' => $request->insurance_provider_name,
                        'card_number' => $request->card_number,
                        'valid_until' => $request->valid_until,
                    ]
                );
            }

            // 7. Handle File Upload (Replace old file if exists)
            if ($request->hasFile('history_file')) {
                if ($patientHistory->history_file && file_exists(public_path($patientHistory->history_file))) {
                    @unlink(public_path($patientHistory->history_file));
                }
                $file = $request->file('history_file');
                $fileName = 'history_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
                $file->move(public_path('uploads/historyFiles'), $fileName);
                $patientHistory->update(['history_file' => 'uploads/historyFiles/'.$fileName]);
            }

            DB::commit();

            return response([
                'data' => [
                    'patient' => $patient->load('geographicalLocation'),
                    'history' => $patientHistory->load(['diagnoses', 'reason']),
                ],
                'message' => 'Patient and medical history updated successfully',
                'statusCode' => 200,
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return Helper::serverError($e, 'Failed to update the patient record.');
        }
    }

    public function showForUpdate($patient_id)
    {
        $user = auth()->user();

        if (! $user->can('View Patient')) {
            return response(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        try {
            // 1. Eager Load kila kitu.
            // Tunatumia latest('patient_histories_id') kupata ya mwisho kuingizwa.
            $patient = \App\Models\Patient::with([
                'insurance',
                'patientFiles',
                'geographicalLocation',
                'patientHistories' => function ($query) {
                    $query->latest('patient_histories_id')
                        ->with(['diagnoses', 'reason'])
                        ->limit(1);
                },
            ])->findOrFail($patient_id);

            // Kuchukua historia ya kwanza (ambayo ni latest kutokana na query hapo juu)
            $history = $patient->patientHistories->first();

            return response([
                'data' => [
                    'patient' => [
                        'patient_id' => $patient->patient_id,
                        'name' => $patient->name,
                        'matibabu_card' => $patient->matibabu_card,
                        'zan_id' => $patient->zan_id,
                        'date_of_birth' => $patient->date_of_birth,
                        'gender' => $patient->gender,
                        'phone' => $patient->phone,
                        'job' => $patient->job,
                        'position' => $patient->position,
                        'location_details' => $patient->geographicalLocation,
                    ],
                    // Ikiwa historia haipo, tunarudisha null badala ya kutoa Error
                    'history' => $history ? [
                        'history_id' => $history->patient_histories_id,
                        'file_number' => $history->file_number,
                        'referring_date' => $history->referring_date,
                        'case_type' => $history->case_type,
                        'history_of_presenting_illness' => $history->history_of_presenting_illness,
                        'physical_findings' => $history->physical_findings,
                        'investigations' => $history->investigations,
                        'management_done' => $history->management_done,
                        'history_file' => $history->history_file ? asset($history->history_file) : null,
                        'reason_details' => $history->reason,
                        'diagnoses' => $history->diagnoses,
                    ] : null,

                    'insurance' => $patient->insurance ? [
                        'has_insurance' => true,
                        'insurance_id' => $patient->insurance->insurance_id,
                        'insurance_provider_name' => $patient->insurance->insurance_provider_name,
                        'card_number' => $patient->insurance->card_number,
                        'valid_until' => $patient->insurance->valid_until,
                        'created_at' => $patient->insurance->created_at,
                    ] : ['has_insurance' => false],

                    'patient_summary_file' => $patient->patientFiles->sortByDesc('id')->map(function ($file) {
                        return [
                            'id' => $file->id,
                            'file_name' => $file->file_name,
                            'file_path' => asset($file->file_path),
                            'description' => $file->description,
                        ];
                    })->first(), // Hapa tunachukua faili la kwanza baada ya kupanga kwa ID (Latest)
                ],
                'statusCode' => 200,
            ], 200);
        } catch (\Exception $e) {
            return Helper::serverError($e, 'Error retrieving patient data.');
        }
    }

    /**
     * Display the specified resource.
     */
    /**
     * @OA\Get(
     *     path="/api/patients/{patientId}",
     *     summary="Find patient by ID",
     *     tags={"Patients"},
     *
     *     @OA\Parameter(
     *         name="patientId",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="patient_id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="date_of_birth", type="string", format="date-time"),
     *                 @OA\Property(property="gender", type="string"),
     *                 @OA\Property(property="phone", type="string"),
     *                 @OA\Property(property="location", type="string"),
     *                 @OA\Property(property="job", type="string"),
     *                 @OA\Property(property="position", type="string"),
     *                 @OA\Property(property="patient_list_id", type="integer"),
     *                 @OA\Property(property="created_by", type="integer", example=1),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-10T10:44:31.000000Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-10T10:44:31.000000Z"),
     *                 @OA\Property(property="deleted_at", type="string", format="date-time", nullable=true, example=null)
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     )
     * )
     */
    public function show($id)
    {
        $user = auth()->user();
        if (! $user->can('View Patient')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $patient = Patient::with([
            'patientList',          // patient list info
            'files',                // all patient files
            'insurances',
            'geographicalLocation',
            'referrals.reason',     // referrals + reason
            'referrals.hospital',   // referrals + hospital
            'referrals.creator',    // referral created by user
        ])->where('patient_id', (int) $id)
            ->get();

        if (! $patient) {
            return response([
                'message' => 'Patient not found',
                'statusCode' => 404,
            ], 404);
        } else {
            return response([
                'data' => $patient,
                'statusCode' => 200,
            ], 200);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * @OA\Put(
     *     path="/api/patients/update/{patientId}",
     *     summary="Update patient",
     *     tags={"Patients"},
     *
     *     @OA\Parameter(
     *         name="patientId",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="matibabu_card", type="string"),
     *                 @OA\Property(property="zan_id", type="string"),
     *                 @OA\Property(property="date_of_birth", type="string", format="date"),
     *                 @OA\Property(property="gender", type="string"),
     *                 @OA\Property(property="phone", type="string"),
     *                 @OA\Property(property="location", type="string"),
     *                 @OA\Property(property="job", type="string"),
     *                 @OA\Property(property="position", type="string"),
     *                 @OA\Property(property="patient_list_id", type="integer"),
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Optional new file to attach"
     *                 ),
     *                 @OA\Property(
     *                     property="description",
     *                     type="string",
     *                     description="Optional file description"
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Patient updated successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Patient updated successfully."),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Patient not found"),
     *     @OA\Response(response=500, description="Internal Server Error")
     * )
     */
    public function updatePatient(Request $request, int $id)
    {
        $user = auth()->user();

        // Authorization
        if (! $user->can('Update Patient')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        // Normalize input
        $request->merge([
            'has_insurance' => filter_var($request->has_insurance, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        // Validation
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string'],
            'matibabu_card' => ['nullable', 'string'],
            'zan_id' => ['nullable', 'string'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'location_id' => ['nullable', 'string', 'exists:geographical_locations,location_id'],
            'job' => ['nullable', 'string'],
            'position' => ['nullable', 'string'],
            'patient_list_id' => ['numeric', 'exists:patient_lists,patient_list_id'],
            'patient_file.*' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xlsx'],
            'description' => ['nullable', 'string'],
            'has_insurance' => ['nullable', 'boolean'],
            'insurance_provider_name' => ['nullable', 'string'],
            'card_number' => ['nullable', 'string'],
            'valid_until' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
                'statusCode' => 422,
            ], 422);
        }

        // Find patient
        $patient = Patient::findOrFail($id);

        // Check capacity for each list
        $patientList = \App\Models\PatientList::find($request->patient_list_id);

        if (! $patientList) {
            return response()->json([
                'message' => "Invalid Patient List ID: {$request->patient_list_id}",
                'statusCode' => 404,
            ], 404);
        }

        $existingCount = \App\Models\Patient::whereHas('patientList', function ($query) use ($patientList) {
            $query->where('patient_lists.patient_list_id', $patientList->patient_list_id);
        })->count();

        if ($existingCount >= $patientList->no_of_patients) {
            return response()->json([
                'message' => "The Medical Board (ID: {$patientList->patient_list_id}) already reached its patient limit ({$patientList->no_of_patients}).",
                'statusCode' => 422,
            ], 422);
        }

        // Update patient basic info
        $patient->update($request->only([
            'name',
            'matibabu_card',
            'zan_id',
            'date_of_birth',
            'gender',
            'phone',
            'location_id',
            'job',
            'position',
        ]));

        // Sync pivot table with new patient lists
        $patient->patientList()->sync($request->patient_list_id);

        // Handle file uploads
        if ($request->hasFile('patient_file')) {
            $files = $request->file('patient_file');
            if (! is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                $extension = $file->getClientOriginalExtension();
                $newFileName = 'patient_file_'.date('h-i-s_a_d-m-Y').'.'.$extension;
                $file->move(public_path('uploads/patientFiles/'), $newFileName);

                PatientFile::create([
                    'patient_id' => $patient->patient_id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => 'uploads/patientFiles/'.$newFileName,
                    'file_type' => $file->getClientMimeType(),
                    'description' => $request->input('description'),
                    'uploaded_by' => Auth::id(),
                ]);
            }
        }

        // Handle insurance
        if ($request->has('has_insurance')) {
            if ($request->boolean('has_insurance')) {
                \App\Models\Insurance::updateOrCreate(
                    ['patient_id' => $patient->patient_id],
                    [
                        'insurance_provider_name' => $request->insurance_provider_name ?: null,
                        'card_number' => $request->card_number ?: null,
                        'valid_until' => $request->valid_until ?: null,
                    ]
                );
            } else {
                \App\Models\Insurance::where('patient_id', $patient->patient_id)->delete();
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $patient->load(['files', 'insurances', 'patientLists']),
            'message' => 'Patient updated successfully.',
            'statusCode' => 200,
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * @OA\Delete(
     *     path="/api/patients/{patientId}",
     *     summary="Delete patient",
     *     tags={"Patients"},
     *
     *     @OA\Parameter(
     *         name="patientId",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="string")
     *     ),
     *
     *      @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function destroy(int $id)
    {
        $user = auth()->user();
        if (! $user->can('Delete Patient')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $patient = Patient::withTrashed()->find($id);

        if (! $patient) {
            return response([
                'message' => 'Patient not found',
                'statusCode' => 404,
            ], 404);
        }

        $patient->delete();

        return response([
            'message' => 'Patient blocked successfully',
            'statusCode' => 200,
        ], 200);
    }

    /**
     * Unblock
     */
    /**
     * @OA\Patch(
     *     path="/api/patients/unblock/{patientId}",
     *     summary="Unblock patient",
     *     tags={"Patients"},
     *
     *     @OA\Parameter(
     *         name="patientId",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *      @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\Header(
     *             header="Cache-Control",
     *             description="Cache control header",
     *
     *             @OA\Schema(type="string", example="no-cache, private")
     *         ),
     *
     *         @OA\Header(
     *             header="Content-Type",
     *             description="Content type header",
     *
     *             @OA\Schema(type="string", example="application/json; charset=UTF-8")
     *         ),
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="statusCode", type="integer")
     *         )
     *     )
     * )
     */
    public function unBlockPatient(int $id)
    {

        $patient = Patient::withTrashed()->find($id);

        if (! $patient) {
            return response([
                'message' => 'Patient not found',
                'statusCode' => 404,
            ], 404);
        }

        $patient->restore($id);

        return response([
            'message' => 'Patient unbocked successfully',
            'statusCode' => 200,
        ], 200);
    }

    public function getAllPatientsWithInsurance(int $patient_id)
    {
        $patient = Patient::with('insurances')
            ->where('patient_id', $patient_id)
            ->first();

        if (! $patient) {
            return response()->json([
                'message' => 'Patient not found',
                'statusCode' => 404,
            ], 404);
        }

        if ($patient->patient_list_id) {
            $patient->documentUrl = asset('storage/'.$patient->patient_list_id);
        } else {
            $patient->documentUrl = null;
        }
        $patient->insurances = $patient->insurances ?? [];

        return response([
            'data' => $patient,
            'statusCode' => 200,
        ], 200);
    }

    public function getAllPatients(Request $request)
    {
        $user = auth()->user();

        if (! $user->can('View Patient')) {
            return response([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $query = Patient::with([
            'patientList',
            'files',
            'geographicalLocation',
            'referrals.reason',
            'referrals.hospital',
            'referrals.creator',
        ])
            ->where(function ($query): void {
                $query->whereDoesntHave('referrals') // patients with no referrals
                    ->orWhereHas('referrals', function ($referralQuery): void {
                        $referralQuery->whereIn('status', ['Cancelled', 'Expired', 'Closed', 'Pending']);
                    });
            });

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

        $patients = $query
            ->latest('patient_id')
            ->paginate(Pagination::perPage($request, 25));

        return response([
            'data' => $patients->items(),
            'meta' => Pagination::meta($patients),
            'statusCode' => 200,
        ], 200);
    }

    /**
     * @OA\Get(
     *     path="/api/patients/histories/{patientId}",
     *     summary="Get medical histories of a patient by ID",
     *     tags={"Patients"},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Patient ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer", example=5)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Patient medical histories retrieved successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="patient",
     *                     type="object",
     *                     @OA\Property(property="patient_id", type="integer", example=5),
     *                     @OA\Property(property="name", type="string", example="Jane Doe"),
     *                     @OA\Property(property="gender", type="string", example="Female"),
     *                     @OA\Property(property="date_of_birth", type="string", format="date", example="1995-09-10")
     *                 ),
     *                 @OA\Property(
     *                     property="medical_histories",
     *                     type="array",
     *
     *                     @OA\Items(
     *                         type="object",
     *
     *                         @OA\Property(property="patient_histories_id", type="integer", example=1),
     *                         @OA\Property(property="referring_doctor", type="string", example="Dr. Ali"),
     *                         @OA\Property(property="file_number", type="string", example="FILE123"),
     *                         @OA\Property(property="referring_date", type="string", format="date", example="2025-03-15"),
     *                         @OA\Property(property="history_of_presenting_illness", type="string", example="Severe headache and nausea."),
     *                         @OA\Property(property="physical_findings", type="string", example="BP: 120/80, HR: 75 bpm"),
     *                         @OA\Property(property="investigations", type="string", example="CT scan, Blood tests"),
     *                         @OA\Property(property="management_done", type="string", example="Pain management and follow-up"),
     *                         @OA\Property(property="board_comments", type="string", example="Monitor condition"),
     *                         @OA\Property(property="created_at", type="string", format="date-time", example="2025-03-15T08:20:00Z")
     *                     )
     *                 )
     *             ),
     *             @OA\Property(property="statusCode", type="integer", example=200)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Forbidden"),
     *             @OA\Property(property="statusCode", type="integer", example=403)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Patient not found",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="message", type="string", example="Patient not found"),
     *             @OA\Property(property="statusCode", type="integer", example=404)
     *         )
     *     )
     * )
     */
    public function getMedicalHistory($patient_id)
    {
        $user = auth()->user();

        if (! $user->can('View Patient')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $patient = Patient::with([
            'patientHistories' => function ($q) {
                $q->latest();
            },
            'patientHistories.reason',
        ])->where('patient_id', $patient_id)->first();

        if (! $patient) {
            return response()->json([
                'message' => 'Patient not found',
                'statusCode' => 404,
            ], 404);
        }

        return response()->json([
            'data' => [
                'patient' => $patient,
                'medical_histories' => $patient->patientHistories,
            ],
            'statusCode' => 200,
        ]);
    }

    /**
     * Standalone helper to validate the CHFID/Matibabu card math.
     */
    private function isValidMatibabuCard($chfid): bool
    {
        // 1. Basic format check
        if (empty($chfid) || strlen($chfid) !== 12 || ! ctype_digit($chfid)) {
            return false;
        }

        // 2. Logic: First 11 digits MOD 7 should equal the 12th digit
        $lastDigit = (int) substr($chfid, -1);
        $firstPart = substr($chfid, 0, 11);

        // Using bcmod to handle large numbers accurately
        return (int) bcmod($firstPart, '7') === $lastDigit;
    }

    private function isPatientEligible($card)
    {
        $patient = \App\Models\Patient::where('matibabu_card', $card)->first();

        // If patient doesn't exist, they are eligible (it's a new registration)
        if (! $patient) {
            return true;
        }

        // 1. Block if the latest history is still being processed
        $activeHistory = \App\Models\PatientHistory::where('patient_id', $patient->patient_id)
            ->whereIn('status', ['pending', 'reviewed', 'assigned', 'requested', 'approved'])
            ->latest()
            ->first();

        if ($activeHistory) {
            return false;
        }

        // 2. Check the status of the absolute LATEST referral
        $latestReferral = \App\Models\Referral::where('patient_id', $patient->patient_id)
            ->latest()
            ->first();

        if ($latestReferral) {
            // Eligibility depends ONLY on the latest referral status
            return in_array($latestReferral->status, ['Closed', 'Cancelled']);
        }

        // 3. Path B: If NO referrals exist, check if the latest history was rejected
        $latestHistory = \App\Models\PatientHistory::where('patient_id', $patient->patient_id)
            ->latest()
            ->first();

        if ($latestHistory && $latestHistory->status === 'rejected') {
            return true;
        }

        return false;
    }

    /**
     * Search for an eligible patient by Matibabu Card.
     * Criteria:
     * 1. Status in referral table is 'Closed' or 'Cancelled'
     * 2. Latest patient history status is 'rejected'
     */
    public function searchByMatibabu(Request $request)
    {
        $user = auth()->user();

        if (! $user->can('View Patient')) {
            return response(['message' => 'Forbidden', 'statusCode' => 403], 403);
        }

        $validator = Validator::make($request->all(), [
            'matibabu_card' => ['required', 'string'],
        ]);

        // NEW MANUAL CHECKSUM VALIDATION
        if (! $this->isValidMatibabuCard($request->matibabu_card)) {
            return response()->json([
                'message' => 'The Matibabu card number is invalid.',
                'success' => false,
                'statusCode' => 403,
            ], 200);
        }

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors(), 'statusCode' => 422], 422);
        }

        $card = $request->matibabu_card;
        $patient = Patient::where('matibabu_card', $card)->first();

        if (! $patient) {
            try {
                $imisPatient = app(MatibabuService::class)->enquireInsuree($card);

                // Safe fallback check: Extract payload data if nested under a 'data' array key wrapper
                $imisData = isset($imisPatient['data']) ? $imisPatient['data'] : $imisPatient;

                // Normalize gender representation from codes to verbose names
                $genderMap = ['M' => 'Male', 'F' => 'Female'];
                $genderRaw = strtoupper($imisData['gender'] ?? '');

                // Translate openIMIS format directly to your local ERIS data attributes layout
                $mappedPatient = [
                    'patient_id' => null, // Unregistered locally
                    'name' => $imisData['insureeName'] ?? trim(($imisData['firstName'] ?? '').' '.($imisData['lastName'] ?? '')),
                    'matibabu_card' => $imisData['chfid'] ?? $card,
                    'zhsfid' => $imisData['zhsfid'] ?? null,
                    'zan_id' => (($imisData['source_of_id'] ?? '') === 'Z') ? ($imisData['id'] ?? null) : null,
                    'date_of_birth' => $imisData['dob'] ?? null,
                    'gender' => $genderMap[$genderRaw] ?? $imisData['gender'],
                    'phone' => ! empty($imisData['phone']) ? preg_replace('/\D/', '', $imisData['phone']) : null,
                    'location_id' => $imisData['shehia'] ?? null,
                    'job' => null,
                    'position' => null,
                ];

                return response()->json([
                    'message' => 'Patient found in Matibabu/openIMIS',
                    'success' => true,
                    'statusCode' => 200,
                    'source' => 'matibabu',
                    'data' => $mappedPatient,
                ]);

            } catch (\Exception $e) {
                report($e);

                return response()->json([
                    'message' => 'Patient not found in ERIS or Matibabu',
                    'success' => false,
                    'statusCode' => 404,
                ], 404);
            }
        }

        // Use the helper to determine eligibility based on LATEST records
        $isEligible = $this->isPatientEligible($card);

        if (! $isEligible) {
            return response()->json([
                'message' => 'This patient is not eligible (Latest Referral not Closed/Cancelled or History not Rejected).',
                'success' => false,
                'statusCode' => 403,
            ], 200);
        }

        // If eligible, return the patient with the necessary relationships
        $eligiblePatient = Patient::where('patient_id', $patient->patient_id)
            ->with(['latestHistory', 'referrals', 'geographicalLocation', 'creator'])
            ->first();

        return response()->json([
            'data' => $eligiblePatient,
            'message' => 'Eligible patient retrieved successfully.',
            'success' => true,
            'statusCode' => 200,
        ], 200);
    }
}
