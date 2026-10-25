<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TelemedicineRequest extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'telemedicine_requests';
    protected $primaryKey = 'telemedicine_request_id';

    protected $fillable = [
        'reference',
        'request_type',
        'status',
        'patient_id',
        'patient_histories_id',
        'referral_id',
        'requesting_doctor_id',
        'requesting_facility_id',
        'created_by',
        'reason',
        'clinical_notes',
        'dcs_comments',
        'dcs_decided_by',
        'dcs_decided_at',
        'decision_comments',
        'final_decision',
        'decision_by',
        'decision_at',
    ];

    protected $casts = [
        'dcs_decided_at' => 'datetime',
        'decision_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'patient_id');
    }

    public function patientHistory()
    {
        return $this->belongsTo(PatientHistory::class, 'patient_histories_id', 'patient_histories_id');
    }

    public function referral()
    {
        return $this->belongsTo(Referral::class, 'referral_id', 'referral_id');
    }

    public function requestingDoctor()
    {
        return $this->belongsTo(User::class, 'requesting_doctor_id');
    }

    public function requestingFacility()
    {
        return $this->belongsTo(Hospital::class, 'requesting_facility_id', 'hospital_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dcsDecidedBy()
    {
        return $this->belongsTo(User::class, 'dcs_decided_by');
    }

    public function decisionBy()
    {
        return $this->belongsTo(User::class, 'decision_by');
    }

    public function documents()
    {
        return $this->hasMany(TelemedicineRequestDocument::class, 'telemedicine_request_id', 'telemedicine_request_id');
    }

    public function appointments()
    {
        return $this->hasMany(TelemedicineAppointment::class, 'telemedicine_request_id', 'telemedicine_request_id');
    }

    public function latestAppointment()
    {
        return $this->hasOne(TelemedicineAppointment::class, 'telemedicine_request_id', 'telemedicine_request_id')
            ->latestOfMany('telemedicine_appointment_id');
    }

    public function reports()
    {
        return $this->hasMany(TelemedicineReport::class, 'telemedicine_request_id', 'telemedicine_request_id');
    }

    public function latestReport()
    {
        return $this->hasOne(TelemedicineReport::class, 'telemedicine_request_id', 'telemedicine_request_id')
            ->latestOfMany('telemedicine_report_id');
    }

    public function decisions()
    {
        return $this->hasMany(TelemedicineDecision::class, 'telemedicine_request_id', 'telemedicine_request_id');
    }

    public function latestDecision()
    {
        return $this->hasOne(TelemedicineDecision::class, 'telemedicine_request_id', 'telemedicine_request_id')
            ->latestOfMany('telemedicine_decision_id');
    }

    public function workflowEvents()
    {
        return $this->hasMany(TelemedicineWorkflowEvent::class, 'telemedicine_request_id', 'telemedicine_request_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*'])->logOnlyDirty();
    }
}
