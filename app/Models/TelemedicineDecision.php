<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TelemedicineDecision extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'telemedicine_decisions';
    protected $primaryKey = 'telemedicine_decision_id';

    protected $fillable = [
        'telemedicine_request_id',
        'telemedicine_appointment_id',
        'decided_by',
        'decision',
        'comments',
        'referral_id',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(TelemedicineRequest::class, 'telemedicine_request_id', 'telemedicine_request_id');
    }

    public function appointment()
    {
        return $this->belongsTo(TelemedicineAppointment::class, 'telemedicine_appointment_id', 'telemedicine_appointment_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function referral()
    {
        return $this->belongsTo(Referral::class, 'referral_id', 'referral_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*'])->logOnlyDirty();
    }
}
