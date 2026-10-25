<?php

namespace App\Http\Controllers\API\Telemedicine;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\TelemedicineAppointment;
use App\Models\TelemedicineDecision;
use App\Models\TelemedicineReport;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineRequestDocument;
use App\Models\User;
use App\Services\Telemedicine\TelemedicineWorkflowService;
use App\Support\Pagination;
use App\Support\SuperAdminAccess;
use App\Support\TelemedicineAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TelemedicineController extends Controller
{
    public function __construct(private readonly TelemedicineWorkflowService $workflow)
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        if (! TelemedicineAccess::canView($user)) {
            return $this->forbidden();
        }

        $query = TelemedicineRequest::query()
            ->with($this->workflow->requestRelations())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('request_type'), fn ($q) => $q->where('request_type', $request->input('request_type')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = mb_strtolower(trim((string) $request->input('search')));
                $q->where(function ($inner) use ($term): void {
                    $inner->whereRaw('LOWER(reference) LIKE ?', ['%'.$term.'%'])
                        ->orWhereHas('patient', function ($patient) use ($term): void {
                            $patient->whereRaw('LOWER(name) LIKE ?', ['%'.$term.'%'])
                                ->orWhereRaw('LOWER(phone) LIKE ?', ['%'.$term.'%'])
                                ->orWhereRaw('LOWER(matibabu_card) LIKE ?', ['%'.$term.'%']);
                        });
                });
            });

        if (! $this->isPrivilegedQueueUser($user)) {
            if (TelemedicineAccess::isCoordinator($user)) {
                $hospitalIds = $user->hospitals()->pluck('hospitals.hospital_id')->all();
                $query->where(function ($scope) use ($user, $hospitalIds): void {
                    $scope->where('created_by', $user->getKey())
                        ->orWhereHas('appointments', function ($appointments) use ($user, $hospitalIds): void {
                            $appointments->where('telemedicine_coordinator_id', $user->getKey());
                            if ($hospitalIds !== []) {
                                $appointments->orWhereIn('hospital_id', $hospitalIds);
                            }
                        });
                });
            } elseif (TelemedicineAccess::isHospitalDoctor($user)) {
                $query->where(function ($scope) use ($user): void {
                    $scope->where('created_by', $user->getKey())
                        ->orWhere('requesting_doctor_id', $user->getKey())
                        ->orWhereHas('patient', fn ($patient) => $patient->where('created_by', $user->getKey()))
                        ->orWhereHas('appointments', fn ($appointments) => $appointments->where('assigned_doctor_id', $user->getKey()));
                });
            } else {
                // A role with only the read permission does not receive a
                // broad patient-data queue by default.
                $query->where('created_by', $user->getKey());
            }
        }

        $requests = $query
            ->latest('telemedicine_request_id')
            ->paginate(Pagination::perPage($request, 25));

        return response()->json([
            'data' => $requests->items(),
            'meta' => Pagination::meta($requests),
            'statusCode' => 200,
        ]);
    }

    public function show(int $id)
    {
        $user = auth()->user();
        $request = TelemedicineRequest::with([
            ...$this->workflow->requestRelations(),
            'reports.recorder:id,first_name,middle_name,last_name',
            'decisions.decider:id,first_name,middle_name,last_name',
            'decisions.referral',
            'workflowEvents.actor:id,first_name,middle_name,last_name',
        ])->find($id);

        if (! $request || ! TelemedicineAccess::canView($user) || ! TelemedicineAccess::canAccessRequest($user, $request)) {
            return response()->json(['message' => 'Telemedicine request not found', 'statusCode' => 404], 404);
        }

        return response()->json(['data' => $request, 'statusCode' => 200]);
    }

    public function patients(Request $request)
    {
        $user = auth()->user();
        if (! TelemedicineAccess::canCreate($user)) {
            return $this->forbidden();
        }

        $query = Patient::query()
            ->select(['patient_id', 'name', 'matibabu_card', 'zan_id', 'phone', 'date_of_birth', 'gender', 'created_by'])
            ->with(['latestHistory' => function ($history): void {
                $history->select([
                    'patient_histories_id',
                    'patient_id',
                    'status',
                    'reason_id',
                    'board_reason_id',
                ]);
            }])
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = mb_strtolower(trim((string) $request->input('search')));
                $q->where(function ($inner) use ($term): void {
                    $inner->whereRaw('LOWER(name) LIKE ?', ['%'.$term.'%'])
                        ->orWhereRaw('LOWER(phone) LIKE ?', ['%'.$term.'%'])
                        ->orWhereRaw('LOWER(matibabu_card) LIKE ?', ['%'.$term.'%'])
                        ->orWhereRaw('LOWER(zan_id) LIKE ?', ['%'.$term.'%']);
                });
            });

        if (TelemedicineAccess::isHospitalDoctor($user) && ! $this->isPrivilegedQueueUser($user)) {
            $hospitalIds = $user->hospitals()->pluck('hospitals.hospital_id')->all();
            if ($hospitalIds === []) {
                $query->where('created_by', $user->getKey());
            } else {
                $query->where(function ($scope) use ($user, $hospitalIds): void {
                    $scope->where('created_by', $user->getKey())
                        ->orWhereHas('creator', function ($creator) use ($hospitalIds): void {
                            $creator->whereHas('hospitals', fn ($hospitals) => $hospitals->whereIn('hospitals.hospital_id', $hospitalIds));
                        });
                });
            }
        }

        $patients = $query->latest('patient_id')->paginate(Pagination::perPage($request, 15));

        return response()->json([
            'data' => $patients->items(),
            'meta' => Pagination::meta($patients),
            'statusCode' => 200,
        ]);
    }

    /**
     * Register a follow-up or consultation request from the telemedicine
     * workspace. DCS uses this same standalone endpoint so the request does
     * not change the patient-history approval status.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        if (! TelemedicineAccess::canCreate($user)) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'request_type' => ['required', 'in:follow_up,new_consultation'],
            'patient_id' => ['required', 'integer', 'exists:patients,patient_id'],
            'patient_histories_id' => ['nullable', 'integer', 'exists:patient_histories,patient_histories_id'],
            'referral_id' => ['nullable', 'integer', 'exists:referrals,referral_id'],
            'requesting_doctor_id' => ['nullable', 'integer', 'exists:users,id'],
            'requesting_facility_id' => ['nullable', 'integer', 'exists:hospitals,hospital_id'],
            'reason' => ['nullable', 'string', 'max:4000'],
            'clinical_notes' => ['nullable', 'string', 'max:10000'],
            'document_type' => ['nullable', 'string', 'max:80'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'statusCode' => 422,
            ], 422);
        }

        $data = $validator->validated();
        if (TelemedicineAccess::isDcs($user) && empty($data['patient_histories_id'])) {
            return response()->json([
                'message' => 'A patient history is required when DCS requests telemedicine from a case review.',
                'statusCode' => 422,
            ], 422);
        }

        $patient = Patient::with(['latestHistory', 'creator.hospitals'])->find($data['patient_id']);
        if (! $patient || ! $this->canAccessPatient($user, $patient)) {
            return response()->json([
                'message' => 'The selected patient is outside your authorized facility scope.',
                'statusCode' => 404,
            ], 404);
        }

        $history = ! empty($data['patient_histories_id'])
            ? \App\Models\PatientHistory::find($data['patient_histories_id'])
            : null;
        $referral = ! empty($data['referral_id'])
            ? \App\Models\Referral::find($data['referral_id'])
            : null;

        if ($referral) {
            if ((int) $referral->patient_id !== (int) $data['patient_id']) {
                return response()->json(['message' => 'The referral does not belong to the selected patient.', 'statusCode' => 422], 422);
            }
            $data['patient_histories_id'] ??= $referral->patient_histories_id;
            $history ??= $referral->patient_histories_id
                ? \App\Models\PatientHistory::find($referral->patient_histories_id)
                : null;
        }

        // Patient registration remains owned by the existing Patients module.
        // When that module has already created a history, use the latest one
        // as the default context instead of creating a duplicate case record.
        $data['patient_histories_id'] ??= $patient->latestHistory?->patient_histories_id;
        $history ??= $data['patient_histories_id']
            ? \App\Models\PatientHistory::find($data['patient_histories_id'])
            : null;

        if ($history && (int) $history->patient_id !== (int) $data['patient_id']) {
            return response()->json(['message' => 'The patient history does not belong to the selected patient.', 'statusCode' => 422], 422);
        }

        if ($referral && $history && $referral->patient_histories_id
            && (int) $referral->patient_histories_id !== (int) $history->patient_histories_id) {
            return response()->json(['message' => 'The referral and patient history do not belong to the same case.', 'statusCode' => 422], 422);
        }

        if ($data['request_type'] === TelemedicineWorkflowService::TYPE_FOLLOW_UP && ! $history && ! $referral) {
            return response()->json([
                'message' => 'An existing patient history or referral is required for a follow-up request.',
                'statusCode' => 422,
            ], 422);
        }

        $data['requesting_doctor_id'] ??= TelemedicineAccess::isHospitalDoctor($user) ? $user->getKey() : null;
        $data['requesting_facility_id'] ??= $patient->creator?->hospitals?->first()?->hospital_id;
        $data['status'] = TelemedicineAccess::isCoordinator($user) && $data['request_type'] === TelemedicineWorkflowService::TYPE_FOLLOW_UP
            ? TelemedicineWorkflowService::STATUS_REGISTERED
            : TelemedicineWorkflowService::STATUS_AWAITING_BILL_VERIFICATION;

        [$requestModel, $created] = DB::transaction(function () use ($data, $request, $user): array {
            // Serialize registrations for this patient before checking for an
            // active request. This keeps concurrent submissions from creating
            // duplicate workflow records without adding a destructive schema
            // constraint to the existing patient tables.
            Patient::query()
                ->whereKey($data['patient_id'])
                ->lockForUpdate()
                ->first();

            $duplicate = TelemedicineRequest::query()
                ->where('patient_id', $data['patient_id'])
                ->where('request_type', $data['request_type'])
                ->whereIn('status', $this->workflow->activeStatuses())
                ->latest('telemedicine_request_id')
                ->lockForUpdate()
                ->first();
            if ($duplicate) {
                return [$duplicate->load($this->workflow->requestRelations()), false];
            }

            $requestModel = $this->workflow->createManual([
                'request_type' => $data['request_type'],
                'status' => $data['status'],
                'patient_id' => $data['patient_id'],
                'patient_histories_id' => $data['patient_histories_id'] ?? null,
                'referral_id' => $data['referral_id'] ?? null,
                'requesting_doctor_id' => $data['requesting_doctor_id'] ?? null,
                'requesting_facility_id' => $data['requesting_facility_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'clinical_notes' => $data['clinical_notes'] ?? null,
            ], $user);

            foreach ($request->file('documents', []) as $file) {
                $path = $file->store('telemedicine/requests', 'local');
                TelemedicineRequestDocument::create([
                    'telemedicine_request_id' => $requestModel->getKey(),
                    'uploaded_by' => $user->getKey(),
                    'document_type' => $data['document_type'] ?? null,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            return [$requestModel, true];
        });

        if (! $created) {
            return response()->json([
                'message' => 'An active telemedicine request already exists for this patient and request type.',
                'data' => $requestModel,
                'statusCode' => 409,
            ], 409);
        }

        $requestModel->load('patient');
        $this->workflow->notifyBillVerificationOfficers($requestModel, $user);

        return response()->json([
            'message' => 'Telemedicine request registered and sent for appointment coordination.',
            'data' => $requestModel->load($this->workflow->requestRelations()),
            'statusCode' => 201,
        ], 201);
    }

    public function hospitals()
    {
        $user = auth()->user();
        if (! TelemedicineAccess::canSchedule($user)) {
            return $this->forbidden();
        }

        return response()->json([
            'data' => $this->eligibleHospitals()->get([
                'hospital_id',
                'hospital_name',
                'hospital_address',
                'hospital_code',
                'referral_type_id',
            ]),
            'statusCode' => 200,
        ]);
    }

    public function coordinators(int $hospitalId)
    {
        if (! TelemedicineAccess::canSchedule(auth()->user())) {
            return $this->forbidden();
        }
        if (! $this->eligibleHospitals()->whereKey($hospitalId)->exists()) {
            return response()->json(['message' => 'The selected hospital is not an eligible Zanzibar/internal telemedicine facility.', 'statusCode' => 422], 422);
        }

        $users = User::query()
            ->whereHas('hospitals', function ($query) use ($hospitalId): void {
                $query->where('hospitals.hospital_id', $hospitalId);
            })
            ->where(function ($query) use ($hospitalId): void {
                $query->whereHas('roles', fn ($roles) => $roles->where('name', TelemedicineAccess::ROLE_COORDINATOR))
                    ->orWhereHas('hospitals', function ($hospitals) use ($hospitalId): void {
                        $hospitals->where('hospitals.hospital_id', $hospitalId)
                            ->whereRaw("LOWER(COALESCE(hospital_user.role, '')) LIKE ?", ['%coordinator%']);
                    });
            })
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'email']);

        return response()->json(['data' => $users, 'statusCode' => 200]);
    }

    public function doctors(int $hospitalId)
    {
        if (! TelemedicineAccess::canSchedule(auth()->user())) {
            return $this->forbidden();
        }
        if (! $this->eligibleHospitals()->whereKey($hospitalId)->exists()) {
            return response()->json(['message' => 'The selected hospital is not an eligible Zanzibar/internal telemedicine facility.', 'statusCode' => 422], 422);
        }

        $users = User::query()
            ->whereHas('hospitals', function ($query) use ($hospitalId): void {
                $query->where('hospitals.hospital_id', $hospitalId);
            })
            ->where(function ($query) use ($hospitalId): void {
                $query->whereHas('roles', fn ($roles) => $roles->where('name', 'ROLE HOSPITAL USER'))
                    ->orWhereHas('hospitals', function ($hospitals) use ($hospitalId): void {
                        $hospitals->where('hospitals.hospital_id', $hospitalId)
                            ->whereRaw("LOWER(COALESCE(hospital_user.role, '')) LIKE ?", ['%doctor%']);
                    });
            })
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'email']);

        return response()->json(['data' => $users, 'statusCode' => 200]);
    }

    public function schedule(Request $request, int $id)
    {
        $user = auth()->user();
        if (! TelemedicineAccess::canSchedule($user)) {
            return $this->forbidden();
        }

        $telemedicineRequest = TelemedicineRequest::with('patient')->find($id);
        if (! $telemedicineRequest || ! TelemedicineAccess::canAccessRequest($user, $telemedicineRequest)) {
            return response()->json(['message' => 'Telemedicine request not found', 'statusCode' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'hospital_id' => ['required', 'integer'],
            'telemedicine_coordinator_id' => ['required', 'integer', 'exists:users,id'],
            'assigned_doctor_id' => ['required', 'integer', 'exists:users,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors(), 'statusCode' => 422], 422);
        }

        $data = $validator->validated();
        $hospital = $this->eligibleHospitals()->find($data['hospital_id']);
        if (! $hospital) {
            return response()->json(['message' => 'Select an eligible Zanzibar/internal telemedicine hospital.', 'statusCode' => 422], 422);
        }

        $coordinator = $this->coordinatorForHospital((int) $data['telemedicine_coordinator_id'], (int) $hospital->hospital_id);
        if (! $coordinator) {
            return response()->json(['message' => 'Select an available Telemedicine Coordinator assigned to this hospital.', 'statusCode' => 422], 422);
        }

        $doctor = $this->doctorForHospital((int) $data['assigned_doctor_id'], (int) $hospital->hospital_id);
        if (! $doctor) {
            return response()->json(['message' => 'Select a doctor assigned to this hospital.', 'statusCode' => 422], 422);
        }

        if (! in_array($telemedicineRequest->status, [
            TelemedicineWorkflowService::STATUS_REGISTERED,
            TelemedicineWorkflowService::STATUS_AWAITING_BILL_VERIFICATION,
            TelemedicineWorkflowService::STATUS_APPOINTMENT_REQUESTED,
        ], true)) {
            return response()->json(['message' => 'This request is not ready for appointment scheduling.', 'statusCode' => 409], 409);
        }

        $appointment = DB::transaction(function () use ($telemedicineRequest, $hospital, $coordinator, $doctor, $data, $user): TelemedicineAppointment {
            $conflict = TelemedicineAppointment::query()
                ->where('assigned_doctor_id', $doctor->getKey())
                ->where('scheduled_at', $data['scheduled_at'])
                ->where('telemedicine_request_id', '<>', $telemedicineRequest->getKey())
                ->whereNotIn('status', ['cancelled', 'reschedule_requested'])
                ->lockForUpdate()
                ->exists();
            if ($conflict) {
                throw ValidationException::withMessages([
                    'scheduled_at' => 'The selected doctor already has a telemedicine appointment at that time.',
                ]);
            }

            $existing = $telemedicineRequest->appointments()
                ->whereIn('status', ['proposed', 'confirmed'])
                ->latest('telemedicine_appointment_id')
                ->lockForUpdate()
                ->first();
            if ($existing && $existing->status === 'confirmed') {
                throw ValidationException::withMessages(['scheduled_at' => 'A confirmed appointment already exists for this request.']);
            }

            if ($existing) {
                $existing->update([
                    'hospital_id' => $hospital->hospital_id,
                    'telemedicine_coordinator_id' => $coordinator->id,
                    'assigned_doctor_id' => $doctor->id,
                    'scheduled_by' => $user->id,
                    'scheduled_at' => $data['scheduled_at'],
                    'status' => 'proposed',
                    'reschedule_reason' => null,
                ]);
                $appointment = $existing;
            } else {
                $appointment = TelemedicineAppointment::create([
                    'telemedicine_request_id' => $telemedicineRequest->getKey(),
                    'hospital_id' => $hospital->hospital_id,
                    'telemedicine_coordinator_id' => $coordinator->id,
                    'assigned_doctor_id' => $doctor->id,
                    'scheduled_by' => $user->id,
                    'scheduled_at' => $data['scheduled_at'],
                    'status' => 'proposed',
                ]);
            }

            $this->workflow->transition(
                $telemedicineRequest,
                TelemedicineWorkflowService::STATUS_APPOINTMENT_REQUESTED,
                $user,
                'appointment_scheduled',
                ['appointment_id' => $appointment->getKey(), 'hospital_id' => $hospital->hospital_id],
            );

            return $appointment;
        });

        $telemedicineRequest->load($this->workflow->requestRelations());
        $this->workflow->notifyAppointmentDoctor($telemedicineRequest, $user, $doctor);

        return response()->json([
            'message' => 'Telemedicine appointment proposed and the doctor has been notified.',
            'data' => $telemedicineRequest->fresh()->load($this->workflow->requestRelations()),
            'statusCode' => 201,
        ], 201);
    }

    public function appointmentDecision(Request $request, int $id)
    {
        $user = auth()->user();
        if (! TelemedicineAccess::canReviewAppointment($user)) {
            return $this->forbidden();
        }

        $telemedicineRequest = TelemedicineRequest::with(['latestAppointment.assignedDoctor', 'latestAppointment.coordinator', 'patient'])->find($id);
        $appointment = $telemedicineRequest?->latestAppointment;
        if (! $telemedicineRequest || ! $appointment || ! $this->canReviewAppointment($user, $telemedicineRequest, $appointment)) {
            return response()->json(['message' => 'Telemedicine appointment not found', 'statusCode' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'decision' => ['required', 'in:confirm,reschedule,additional_information'],
            'comments' => ['required', 'string', 'max:4000'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors(), 'statusCode' => 422], 422);
        }

        $data = $validator->validated();
        if ($data['decision'] === 'reschedule' && empty($data['scheduled_at'])) {
            return response()->json(['message' => 'A new appointment date and time is required when requesting rescheduling.', 'statusCode' => 422], 422);
        }

        DB::transaction(function () use ($telemedicineRequest, $appointment, $data, $user): void {
            $appointment->doctor_comments = $data['comments'];
            $appointment->reviewed_by = $user->getKey();
            $appointment->reviewed_at = now();

            if ($data['decision'] === 'confirm') {
                $appointment->status = 'confirmed';
                $appointment->confirmed_at = now();
                $appointment->reschedule_reason = null;
                $appointment->save();
                $this->workflow->transition(
                    $telemedicineRequest,
                    TelemedicineWorkflowService::STATUS_APPOINTMENT_CONFIRMED,
                    $user,
                    'appointment_confirmed',
                    ['appointment_id' => $appointment->getKey(), 'comments' => $data['comments']],
                );
                return;
            }

            $appointment->status = $data['decision'] === 'reschedule'
                ? 'reschedule_requested'
                : 'additional_information_requested';
            $appointment->reschedule_reason = $data['decision'] === 'reschedule' ? $data['comments'] : null;
            if (! empty($data['scheduled_at'])) {
                $appointment->scheduled_at = $data['scheduled_at'];
            }
            $appointment->save();

            $this->workflow->transition(
                $telemedicineRequest,
                TelemedicineWorkflowService::STATUS_APPOINTMENT_REQUESTED,
                $user,
                $data['decision'] === 'reschedule' ? 'appointment_reschedule_requested' : 'appointment_information_requested',
                ['appointment_id' => $appointment->getKey(), 'comments' => $data['comments']],
            );
        });

        $telemedicineRequest->load($this->workflow->requestRelations());
        if ($telemedicineRequest->latestAppointment?->coordinator
            && (int) $telemedicineRequest->latestAppointment->coordinator->getKey() !== (int) $user->getKey()) {
            $message = $data['decision'] === 'confirm'
                ? sprintf('Appointment %s was confirmed by the assigned doctor.', $telemedicineRequest->reference)
                : sprintf('Appointment %s requires follow-up: %s', $telemedicineRequest->reference, $data['comments']);
            $this->workflow->notifyAppointmentCoordinator(
                $telemedicineRequest,
                $user,
                $telemedicineRequest->latestAppointment->coordinator,
                $message,
            );
        }

        return response()->json([
            'message' => $data['decision'] === 'confirm'
                ? 'Appointment confirmed.'
                : 'Appointment review saved and the coordinator can follow up.',
            'data' => $telemedicineRequest->fresh()->load($this->workflow->requestRelations()),
            'statusCode' => 200,
        ]);
    }

    public function storeReport(Request $request, int $id)
    {
        $user = auth()->user();
        if (! TelemedicineAccess::canManageReport($user)) {
            return $this->forbidden();
        }

        $telemedicineRequest = TelemedicineRequest::with(['latestAppointment.assignedDoctor', 'latestAppointment.coordinator', 'patient'])->find($id);
        if (! $telemedicineRequest || ! TelemedicineAccess::canAccessRequest($user, $telemedicineRequest)) {
            return response()->json(['message' => 'Telemedicine request not found', 'statusCode' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'report_text' => ['nullable', 'string', 'max:20000'],
            'report_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:20480'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            if (! $request->filled('report_text') && ! $request->hasFile('report_file')) {
                $validator->errors()->add('report_text', 'Enter the consultation report or upload a report file.');
            }
        });
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors(), 'statusCode' => 422], 422);
        }

        if ($telemedicineRequest->status !== TelemedicineWorkflowService::STATUS_APPOINTMENT_CONFIRMED) {
            return response()->json(['message' => 'A confirmed appointment is required before recording a report.', 'statusCode' => 409], 409);
        }

        $appointment = $telemedicineRequest->latestAppointment;
        $file = $request->file('report_file');
        $path = $file?->store('telemedicine/reports', 'local');

        $report = DB::transaction(function () use ($telemedicineRequest, $appointment, $user, $request, $file, $path): TelemedicineReport {
            $report = TelemedicineReport::create([
                'telemedicine_request_id' => $telemedicineRequest->getKey(),
                'telemedicine_appointment_id' => $appointment?->getKey(),
                'recorded_by' => $user->getKey(),
                'report_text' => $request->input('report_text'),
                'original_name' => $file?->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file?->getClientMimeType(),
                'file_size' => $file?->getSize(),
                'recorded_at' => now(),
            ]);

            if ($appointment) {
                $appointment->update(['status' => 'completed']);
            }

            $this->workflow->transition(
                $telemedicineRequest,
                TelemedicineWorkflowService::STATUS_REPORT_AVAILABLE,
                $user,
                'consultation_report_recorded',
                ['report_id' => $report->getKey(), 'appointment_id' => $appointment?->getKey()],
            );

            return $report;
        });

        $telemedicineRequest->load($this->workflow->requestRelations());
        $this->workflow->notifyReportAvailable($telemedicineRequest, $user, $telemedicineRequest->latestAppointment?->assignedDoctor);

        return response()->json([
            'message' => 'Consultation report recorded and the responsible doctor has been notified.',
            'data' => $report->load('recorder:id,first_name,middle_name,last_name'),
            'request' => $telemedicineRequest->fresh()->load($this->workflow->requestRelations()),
            'statusCode' => 201,
        ], 201);
    }

    public function reportFile(int $requestId, int $reportId)
    {
        $report = TelemedicineReport::with('request')->where('telemedicine_request_id', $requestId)->find($reportId);
        if (! $report || ! $report->file_path || ! TelemedicineAccess::canAccessRequest(auth()->user(), $report->request)) {
            return response()->json(['message' => 'Report file not found', 'statusCode' => 404], 404);
        }

        return $this->inlineStoredFile($report->file_path, $report->original_name ?: 'telemedicine-report');
    }

    public function documentFile(int $requestId, int $documentId)
    {
        $document = TelemedicineRequestDocument::with('request')
            ->where('telemedicine_request_id', $requestId)
            ->find($documentId);
        if (! $document || ! TelemedicineAccess::canAccessRequest(auth()->user(), $document->request)) {
            return response()->json(['message' => 'Telemedicine document not found', 'statusCode' => 404], 404);
        }

        return $this->inlineStoredFile($document->file_path, $document->original_name);
    }

    public function recordDecision(Request $request, int $id)
    {
        $user = auth()->user();
        if (! TelemedicineAccess::canRecordDecision($user)) {
            return $this->forbidden();
        }

        $telemedicineRequest = TelemedicineRequest::with([
            'latestAppointment.assignedDoctor',
            'patient',
            'patientHistory',
            'referral',
        ])->find($id);
        if (! $telemedicineRequest || ! TelemedicineAccess::canAccessRequest($user, $telemedicineRequest)) {
            return response()->json(['message' => 'Telemedicine request not found', 'statusCode' => 404], 404);
        }

        $appointment = $telemedicineRequest->latestAppointment;
        if (! $this->canRecordDecision($user, $telemedicineRequest, $appointment)) {
            return response()->json(['message' => 'Only the responsible doctor may record the clinical decision.', 'statusCode' => 403], 403);
        }

        $validator = Validator::make($request->all(), [
            'decision' => ['required', 'in:completed_in_zbr,referral_required,additional_information'],
            'comments' => ['required', 'string', 'max:10000'],
            'reason_id' => ['nullable', 'integer', 'exists:reasons,reason_id'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors(), 'statusCode' => 422], 422);
        }

        $data = $validator->validated();
        $result = DB::transaction(function () use ($telemedicineRequest, $appointment, $user, $data): array {
            $referral = null;
            if ($data['decision'] === TelemedicineWorkflowService::DECISION_REFERRAL_REQUIRED) {
                $referral = $this->workflow->createReferralForDecision($telemedicineRequest, $user, $data['reason_id'] ?? null);
            }

            $decision = TelemedicineDecision::create([
                'telemedicine_request_id' => $telemedicineRequest->getKey(),
                'telemedicine_appointment_id' => $appointment?->getKey(),
                'decided_by' => $user->getKey(),
                'decision' => $data['decision'],
                'comments' => $data['comments'],
                'referral_id' => $referral?->getKey(),
                'decided_at' => now(),
            ]);

            $targetStatus = match ($data['decision']) {
                TelemedicineWorkflowService::DECISION_COMPLETED_IN_ZBR => TelemedicineWorkflowService::STATUS_CLOSED,
                TelemedicineWorkflowService::DECISION_REFERRAL_REQUIRED => TelemedicineWorkflowService::STATUS_REFERRED,
                default => TelemedicineWorkflowService::STATUS_REPORT_AVAILABLE,
            };

            if ($targetStatus === TelemedicineWorkflowService::STATUS_REPORT_AVAILABLE) {
                $telemedicineRequest->update([
                    'final_decision' => $data['decision'],
                    'decision_comments' => $data['comments'],
                    'decision_by' => $user->getKey(),
                    'decision_at' => now(),
                ]);
                $this->workflow->recordEvent(
                    $telemedicineRequest,
                    'decision_recorded',
                    $telemedicineRequest->status,
                    $telemedicineRequest->status,
                    $user,
                    ['decision_id' => $decision->getKey(), 'decision' => $data['decision']],
                );
            } else {
                $telemedicineRequest->update([
                    'final_decision' => $data['decision'],
                    'decision_comments' => $data['comments'],
                    'decision_by' => $user->getKey(),
                    'decision_at' => now(),
                ]);
                $this->workflow->transition(
                    $telemedicineRequest,
                    $targetStatus,
                    $user,
                    'decision_recorded',
                    ['decision_id' => $decision->getKey(), 'decision' => $data['decision'], 'referral_id' => $referral?->getKey()],
                );
            }

            return [$decision, $referral];
        });

        return response()->json([
            'message' => $data['decision'] === TelemedicineWorkflowService::DECISION_REFERRAL_REQUIRED
                ? 'Decision recorded and the existing referral pathway has been started.'
                : ($data['decision'] === TelemedicineWorkflowService::DECISION_COMPLETED_IN_ZBR
                    ? 'Consultation completed in ZBR and the telemedicine pathway is closed.'
                    : 'Additional information requested; the telemedicine pathway remains open.'),
            'data' => $result[0]->load('referral'),
            'request' => $telemedicineRequest->fresh()->load($this->workflow->requestRelations()),
            'statusCode' => 200,
        ]);
    }

    private function eligibleHospitals()
    {
        return Hospital::query()
            ->whereNull('hospitals.deleted_at')
            ->where(function ($query): void {
                $query->whereHas('referralType', function ($type): void {
                    $type->whereRaw('UPPER(referral_type_code) = ?', ['REFTYPE3'])
                        ->orWhereRaw('UPPER(referral_type_name) = ?', ['INTERNAL']);
                })->orWhereRaw("LOWER(COALESCE(hospital_address, '')) LIKE ?", ['%zanzibar%']);
            });
    }

    private function coordinatorForHospital(int $userId, int $hospitalId): ?User
    {
        return User::query()
            ->whereKey($userId)
            ->whereHas('hospitals', fn ($query) => $query->where('hospitals.hospital_id', $hospitalId))
            ->where(function ($query) use ($hospitalId): void {
                $query->whereHas('roles', fn ($roles) => $roles->where('name', TelemedicineAccess::ROLE_COORDINATOR))
                    ->orWhereHas('hospitals', fn ($hospitals) => $hospitals
                        ->where('hospitals.hospital_id', $hospitalId)
                        ->whereRaw("LOWER(COALESCE(hospital_user.role, '')) LIKE ?", ['%coordinator%']));
            })
            ->first();
    }

    private function doctorForHospital(int $userId, int $hospitalId): ?User
    {
        return User::query()
            ->whereKey($userId)
            ->whereHas('hospitals', fn ($query) => $query->where('hospitals.hospital_id', $hospitalId))
            ->where(function ($query) use ($hospitalId): void {
                $query->whereHas('roles', fn ($roles) => $roles->where('name', 'ROLE HOSPITAL USER'))
                    ->orWhereHas('hospitals', fn ($hospitals) => $hospitals
                        ->where('hospitals.hospital_id', $hospitalId)
                        ->whereRaw("LOWER(COALESCE(hospital_user.role, '')) LIKE ?", ['%doctor%']));
            })
            ->first();
    }

    private function canReviewAppointment(User $user, TelemedicineRequest $request, TelemedicineAppointment $appointment): bool
    {
        if (SuperAdminAccess::allowed($user) || TelemedicineAccess::isDcs($user)) {
            return true;
        }

        return (int) $appointment->assigned_doctor_id === (int) $user->getKey()
            || (int) $appointment->telemedicine_coordinator_id === (int) $user->getKey();
    }

    private function canRecordDecision(User $user, TelemedicineRequest $request, ?TelemedicineAppointment $appointment): bool
    {
        if (SuperAdminAccess::allowed($user) || TelemedicineAccess::isDcs($user)) {
            return true;
        }

        return $appointment && (int) $appointment->assigned_doctor_id === (int) $user->getKey();
    }

    private function isPrivilegedQueueUser(User $user): bool
    {
        return SuperAdminAccess::allowed($user)
            || $user->hasAnyRole([
                'ROLE DG',
                'ROLE DIRECTOR GENERAL',
                TelemedicineAccess::ROLE_BILL_VERIFICATION,
                TelemedicineAccess::ROLE_DCS,
            ]);
    }

    private function inlineStoredFile(string $path, string $name)
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return response()->json(['message' => 'Stored file not found', 'statusCode' => 404], 404);
        }

        return response()->file($disk->path($path), [
            'Content-Disposition' => 'inline; filename="'.addcslashes($name, "\\\"").'"',
        ]);
    }

    private function forbidden()
    {
        return response()->json(['message' => 'Forbidden', 'statusCode' => 403], 403);
    }

    private function canAccessPatient(User $user, Patient $patient): bool
    {
        if ($this->isPrivilegedQueueUser($user)) {
            return true;
        }

        if ((int) $patient->created_by === (int) $user->getKey()) {
            return true;
        }

        $hospitalIds = $user->hospitals()->pluck('hospitals.hospital_id')->all();
        if ($hospitalIds === []) {
            return false;
        }

        return $patient->creator?->hospitals()
            ->whereIn('hospitals.hospital_id', $hospitalIds)
            ->exists() === true;
    }
}
