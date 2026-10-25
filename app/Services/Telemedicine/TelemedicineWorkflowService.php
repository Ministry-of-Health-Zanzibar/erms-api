<?php

namespace App\Services\Telemedicine;

use App\Mail\ConversationNotification;
use App\Models\PatientHistoryConversation;
use App\Models\PatientHistoryConversationStatus;
use App\Models\Referral;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineWorkflowEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class TelemedicineWorkflowService
{
    public const TYPE_FOLLOW_UP = 'follow_up';
    public const TYPE_NEW_CONSULTATION = 'new_consultation';

    public const STATUS_REGISTERED = 'registered';
    public const STATUS_AWAITING_BILL_VERIFICATION = 'awaiting_bill_verification';
    public const STATUS_APPOINTMENT_REQUESTED = 'appointment_requested';
    public const STATUS_APPOINTMENT_CONFIRMED = 'appointment_confirmed';
    public const STATUS_REPORT_AVAILABLE = 'report_available';
    public const STATUS_REFERRED = 'referred';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const DECISION_COMPLETED_IN_ZBR = 'completed_in_zbr';
    public const DECISION_REFERRAL_REQUIRED = 'referral_required';
    public const DECISION_ADDITIONAL_INFORMATION = 'additional_information';

    private const ACTIVE_STATUSES = [
        self::STATUS_REGISTERED,
        self::STATUS_AWAITING_BILL_VERIFICATION,
        self::STATUS_APPOINTMENT_REQUESTED,
        self::STATUS_APPOINTMENT_CONFIRMED,
        self::STATUS_REPORT_AVAILABLE,
    ];

    private const TRANSITIONS = [
        self::STATUS_REGISTERED => [self::STATUS_AWAITING_BILL_VERIFICATION, self::STATUS_APPOINTMENT_REQUESTED, self::STATUS_CANCELLED],
        self::STATUS_AWAITING_BILL_VERIFICATION => [self::STATUS_APPOINTMENT_REQUESTED, self::STATUS_CANCELLED],
        self::STATUS_APPOINTMENT_REQUESTED => [self::STATUS_APPOINTMENT_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_APPOINTMENT_CONFIRMED => [self::STATUS_APPOINTMENT_REQUESTED, self::STATUS_REPORT_AVAILABLE, self::STATUS_CANCELLED],
        self::STATUS_REPORT_AVAILABLE => [self::STATUS_REFERRED, self::STATUS_CLOSED, self::STATUS_CANCELLED],
        self::STATUS_REFERRED => [],
        self::STATUS_CLOSED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Register a request submitted by DCS, a coordinator, or an authorized
     * hospital user. Duplicate active requests for the same patient/type are
     * rejected by the controller before this method is called.
     */
    public function createManual(array $data, User $actor): TelemedicineRequest
    {
        $request = TelemedicineRequest::create([
            ...$data,
            'reference' => $this->generateReference(),
            'status' => $data['status'] ?? self::STATUS_AWAITING_BILL_VERIFICATION,
            'created_by' => $actor->getKey(),
        ]);

        $this->recordEvent(
            $request,
            'request_registered',
            null,
            $request->status,
            $actor,
            ['request_type' => $request->request_type],
        );

        return $request;
    }

    public function transition(
        TelemedicineRequest $request,
        string $toStatus,
        User $actor,
        string $action,
        array $metadata = [],
    ): TelemedicineRequest {
        $fromStatus = $request->status;
        if ($fromStatus !== $toStatus && ! in_array($toStatus, self::TRANSITIONS[$fromStatus] ?? [], true)) {
            throw new RuntimeException("Invalid telemedicine transition from {$fromStatus} to {$toStatus}.");
        }

        if ($fromStatus !== $toStatus) {
            $request->forceFill(['status' => $toStatus])->save();
        }

        $this->recordEvent($request, $action, $fromStatus, $toStatus, $actor, $metadata);

        return $request->fresh();
    }

    public function recordEvent(
        TelemedicineRequest $request,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?User $actor,
        array $metadata = [],
    ): TelemedicineWorkflowEvent {
        return TelemedicineWorkflowEvent::create([
            'telemedicine_request_id' => $request->getKey(),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actor?->getKey(),
            'metadata' => $metadata,
        ]);
    }

    public function notifyBillVerificationOfficers(TelemedicineRequest $request, User $sender): void
    {
        $officers = User::role('ROLE BILL VERIFICATION OFFICER')->get();
        $message = sprintf(
            'Telemedicine request %s for %s is ready for appointment scheduling by the Bill Verification Officer.',
            $request->reference,
            $request->patient?->name ?: 'the patient',
        );

        foreach ($officers as $officer) {
            $this->sendConversationNotification($request, $sender, $officer, $message);
        }
    }

    public function notifyAppointmentDoctor(TelemedicineRequest $request, User $sender, User $doctor): void
    {
        $appointment = $request->latestAppointment;
        $date = $appointment?->scheduled_at?->format('d M Y, H:i') ?: 'the proposed time';
        $hospital = $appointment?->hospital?->hospital_name ?: 'the selected hospital';
        $message = sprintf(
            'Telemedicine appointment %s for request %s is proposed for %s at %s. Please review the appointment.',
            $request->patient?->name ?: 'the patient',
            $request->reference,
            $date,
            $hospital,
        );

        $this->sendConversationNotification($request, $sender, $doctor, $message);
    }

    public function notifyReportAvailable(TelemedicineRequest $request, User $sender, ?User $doctor): void
    {
        if (! $doctor) {
            return;
        }

        $this->sendConversationNotification(
            $request,
            $sender,
            $doctor,
            sprintf('The telemedicine report for request %s is now available for review.', $request->reference),
        );
    }

    public function notifyAppointmentCoordinator(
        TelemedicineRequest $request,
        User $sender,
        ?User $coordinator,
        string $message,
    ): void {
        if (! $coordinator || (int) $coordinator->getKey() === (int) $sender->getKey()) {
            return;
        }

        $this->sendConversationNotification($request, $sender, $coordinator, $message);
    }

    public function createReferralForDecision(TelemedicineRequest $request, User $actor, ?int $reasonId = null): Referral
    {
        $request->loadMissing('patientHistory', 'referral');
        $reasonId ??= $request->referral?->reason_id ?: $request->patientHistory?->board_reason_id ?: $request->patientHistory?->reason_id;

        if (! $reasonId) {
            throw new RuntimeException('A referral reason is required before creating the referral.');
        }

        $existing = Referral::query()
            ->where('telemedicine_request_id', $request->getKey())
            ->whereNotIn('status', ['Closed', 'Cancelled', 'Expired', 'BoardedOut'])
            ->latest('referral_id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $referralNumber = $this->generateReferralNumber();
        return Referral::create([
            'patient_id' => $request->patient_id,
            'patient_histories_id' => $request->patient_histories_id,
            'parent_referral_id' => $request->referral_id,
            'telemedicine_request_id' => $request->getKey(),
            'referral_number' => $referralNumber,
            'reason_id' => $reasonId,
            'status' => 'Requested',
            'created_by' => $actor->getKey(),
        ]);
    }

    public function requestRelations(): array
    {
        return [
            'patient:patient_id,name,matibabu_card,zan_id,phone,created_by',
            'patientHistory:patient_histories_id,patient_id,reason_id,board_reason_id,status',
            'referral:referral_id,patient_id,patient_histories_id,hospital_id,reason_id,status,referral_number',
            'requestingFacility:hospital_id,hospital_name,hospital_address,referral_type_id',
            'requestingDoctor:id,first_name,middle_name,last_name',
            'creator:id,first_name,middle_name,last_name',
            'documents',
            'latestAppointment.hospital:hospital_id,hospital_name,hospital_address',
            'latestAppointment.coordinator:id,first_name,middle_name,last_name',
            'latestAppointment.assignedDoctor:id,first_name,middle_name,last_name',
            'latestReport',
            'latestDecision',
        ];
    }

    public function activeStatuses(): array
    {
        return self::ACTIVE_STATUSES;
    }

    private function sendConversationNotification(
        TelemedicineRequest $request,
        User $sender,
        User $recipient,
        string $message,
    ): void {
        if (! $request->patient_histories_id
            || ! Schema::hasTable('patient_history_conversations')
            || ! Schema::hasTable('patient_history_conversation_statuses')) {
            return;
        }

        $conversation = PatientHistoryConversation::create([
            'patient_history_id' => $request->patient_histories_id,
            'sender_id' => $sender->getKey(),
            'receiver_id' => $recipient->getKey(),
            'message' => $message,
        ]);

        PatientHistoryConversationStatus::create([
            'conversation_id' => $conversation->getKey(),
            'user_id' => $recipient->getKey(),
            'read_at' => null,
            'is_notified' => false,
        ]);

        if (! $recipient->email) {
            return;
        }

        try {
            Mail::to($recipient->email)->queue(new ConversationNotification(
                $conversation,
                trim($sender->first_name.' '.$sender->middle_name.' '.$sender->last_name),
                $recipient,
            ));
        } catch (\Throwable $exception) {
            Log::warning('Telemedicine notification email could not be queued', [
                'telemedicine_request_id' => $request->getKey(),
                'recipient_id' => $recipient->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function generateReference(): string
    {
        do {
            $reference = 'TM-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (TelemedicineRequest::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }

    private function generateReferralNumber(): string
    {
        $date = now()->format('Y-m-d');
        $count = Referral::withTrashed()->whereDate('created_at', $date)->count() + 1;
        do {
            $number = 'REF-'.$date.'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            $count++;
        } while (Referral::withTrashed()->where('referral_number', $number)->exists());

        return $number;
    }
}
