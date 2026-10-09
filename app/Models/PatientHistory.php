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
        'pending' => [
            'stage' => 1,
            'label' => 'Submitted by Hospital',
            'current_holder' => 'Director',
            'description' => 'Medical history submitted and awaiting review',
        ],
        'reviewed' => [
            'stage' => 2,
            'label' => 'Reviewed by Director',
            'current_holder' => 'Medical Board',
            'description' => 'Reviewed and forwarded to medical board',
        ],
        'assigned' => [
            'stage' => 3,
            'label' => 'Assigned to Medical Board Meeting',
            'current_holder' => 'Medical Board',
            'description' => 'Patient officially assigned to a medical board Meeting',
        ],
        'requested' => [
            'stage' => 4,
            'label' => 'Medical Board Meeting Requested A Referral',
            'current_holder' => 'Director',
            'description' => 'Medical board requested additional information for the referral',
        ],
        'approved' => [
            'stage' => 5,
            'label' => 'Approved by Director',
            'current_holder' => 'Director General (DG)',
            'description' => 'Approved and sent to DG for confirmation',
        ],
        'confirmed' => [
            'stage' => 6,
            'label' => 'Confirmed by DG',
            'current_holder' => 'Completed',
            'description' => 'Final approval completed',
        ],
        'rejected' => [
            'stage' => 0,
            'label' => 'Rejected by DG',
            'current_holder' => 'Closed',
            'description' => 'Medical history rejected',
        ],
        'boarded_out' => [
            'stage' => 6,
            'label' => 'Boarded Out',
            'current_holder' => 'Completed',
            'description' => 'Patient completed the process with a boarded-out decision',
        ],
    ];

    public function getStatusTrackingAttribute()
    {
        return self::STATUS_MAP[$this->status] ?? null;
    }

    public function getProgressPercentageAttribute()
    {
        if (!isset(self::STATUS_MAP[$this->status])) {
            return '0%';
        }

        $maxStage = 6;
        $stage = self::STATUS_MAP[$this->status]['stage'];

        return (int) round(($stage / $maxStage) * 100) . '%';
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
