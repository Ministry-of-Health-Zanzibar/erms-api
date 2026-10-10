<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PatientHistory extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'patient_histories';
    protected $appends = ['status_tracking','progress_percentage'];
    protected $primaryKey = 'patient_histories_id';
    public $incrementing = true;
    protected $keyType = 'integer';
    public const INITIAL_STATUS = 'reviewed';
    public const WORKFLOW_STAGE_COUNT = 5;
    protected $attributes = ['status' => self::INITIAL_STATUS];

    protected $fillable = [
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
        'board_comments',
        'board_reason_id',
        'history_file',
        'status',
        'mkurugenzi_tiba_comments',
        'dg_comments',
        'mkurugenzi_tiba_id',
        'dg_id',
    ];

    public const STATUS_MAP = [
        'reviewed' => [
            'stage' => 1,
            'label' => 'Awaiting Medical Board',
            'current_holder' => 'Medical Board',
            'description' => 'Submitted directly to the Medical Board and awaiting a meeting assignment',
        ],
        'assigned' => [
            'stage' => 2,
            'label' => 'Assigned to Board Meeting',
            'current_holder' => 'Medical Board',
            'description' => 'Assigned to a Medical Board meeting and awaiting the board decision',
        ],
        'requested' => [
            'stage' => 3,
            'label' => 'Awaiting DCS Approval',
            'current_holder' => 'DCS',
            'description' => 'Medical Board decision recorded and awaiting DCS approval',
        ],
        'approved' => [
            'stage' => 4,
            'label' => 'Approved by DCS',
            'current_holder' => 'Director General (DG)',
            'description' => 'Approved by DCS and awaiting DG confirmation',
        ],
        'confirmed' => [
            'stage' => 5,
            'label' => 'Confirmed',
            'current_holder' => 'Completed',
            'description' => 'Final approval completed',
        ],
        'rejected' => [
            'stage' => 0,
            'label' => 'Rejected',
            'current_holder' => 'Closed',
            'description' => 'Medical history rejected',
        ],
        'boarded_out' => [
            'stage' => 5,
            'label' => 'Boarded Out',
            'current_holder' => 'Completed',
            'description' => 'Patient completed the process with a boarded-out decision',
        ],
    ];

    public function getStatusTrackingAttribute()
    {
        return self::trackingForStatus($this->status);
    }

    public function getProgressPercentageAttribute()
    {
        return self::progressForStatus($this->status) . '%';
    }

    public static function trackingForStatus(?string $status): ?array
    {
        // Preserve historical records without claiming an initial review took
        // place or silently making them eligible for the Medical Board queue.
        if ($status === 'pending') {
            return [
                'stage' => 0,
                'label' => 'Legacy submission',
                'current_holder' => 'Not in current workflow',
                'description' => 'Older submission awaiting a verified workflow update. No automatic approval has been applied.',
                'is_legacy' => true,
            ];
        }

        return self::STATUS_MAP[$status] ?? null;
    }

    public static function progressForStatus(?string $status): int
    {
        return (int) round(((self::trackingForStatus($status)['stage'] ?? 0) / self::WORKFLOW_STAGE_COUNT) * 100);
    }

    public static function labelForStatus(?string $status): string
    {
        return $status === 'under_review' ? 'Under review' : (self::trackingForStatus($status)['label'] ?? $status ?? 'Unknown status');
    }

    /**
     * Belongs to Patient
     */
    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'patient_id');
    }

    public function reason()
    {
        return $this->belongsTo(Reason::class, 'reason_id', 'reason_id');
    }

    public function boardReason()
    {
        return $this->belongsTo(Reason::class, 'board_reason_id', 'reason_id');
    }

    // Diagnoses
    public function diagnoses()
    {
        return $this->belongsToMany(
            Diagnosis::class,
            'history_diagnosis',
            'patient_histories_id',
            'diagnosis_id'
        )
        ->withPivot('added_by')
        ->wherePivot('added_by', 'doctor');
    }

    // Board diagnoses
    public function boardDiagnoses()
    {
        return $this->belongsToMany(
            Diagnosis::class,
            'history_diagnosis',
            'patient_histories_id',
            'diagnosis_id'
        )
        ->withPivot('added_by')
        ->wherePivot('added_by', 'medical_board');
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'patient_histories_id', 'patient_histories_id');
    }

    /**
     * Conversations in this patient history
     */
    public function conversations()
    {
        return $this->hasMany(PatientHistoryConversation::class, 'patient_history_id', 'patient_histories_id');
    }

    /**
     * Shortcut for your comment retrieval
     */
    public function mkurugenzi()
    {
        return $this->belongsTo(User::class, 'Director_tiba_id');
    }

    public function boardedOutLetters()
    {
        return $this->hasMany(BoardedOutLetter::class, 'patient_histories_id');
    }

    /**
     * Activity log options
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*']);
    }
}
