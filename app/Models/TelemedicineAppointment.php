<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TelemedicineAppointment extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'telemedicine_appointments';
    protected $primaryKey = 'telemedicine_appointment_id';

    protected $fillable = [
        'telemedicine_request_id',
        'hospital_id',
        'telemedicine_coordinator_id',
        'assigned_doctor_id',
        'scheduled_by',
        'scheduled_at',
        'status',
        'doctor_comments',
        'reschedule_reason',
        'reviewed_by',
        'reviewed_at',
        'confirmed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(TelemedicineRequest::class, 'telemedicine_request_id', 'telemedicine_request_id');
    }

    public function hospital()
    {
        return $this->belongsTo(Hospital::class, 'hospital_id', 'hospital_id');
    }

    public function coordinator()
    {
        return $this->belongsTo(User::class, 'telemedicine_coordinator_id');
    }

    public function assignedDoctor()
    {
        return $this->belongsTo(User::class, 'assigned_doctor_id');
    }

    public function scheduledBy()
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reports()
    {
        return $this->hasMany(TelemedicineReport::class, 'telemedicine_appointment_id', 'telemedicine_appointment_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*'])->logOnlyDirty();
    }
}
