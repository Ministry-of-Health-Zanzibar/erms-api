<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Referral extends Model
{
    use LogsActivity, HasFactory, SoftDeletes;

    protected $primaryKey = 'referral_id';  // Set the primary key to referral_id
    public $incrementing = true;  // Disable auto-increment

    protected $fillable = [
        'patient_id',
        'patient_histories_id',
        'parent_referral_id',
        'referral_number',
        'hospital_id',
        'reason_id',
        'status',
        'confirmed_by',
        'created_by',
    ];

    public function parent()
    {
        return $this->belongsTo(Referral::class, 'parent_referral_id', 'referral_id');
    }

    public function children()
    {
        return $this->hasMany(Referral::class, 'parent_referral_id', 'referral_id');
    }

    /**
     * Referral has many hospital letters
     */
    public function hospitalLetters()
    {
        return $this->hasMany(HospitalLetter::class, 'referral_id', 'referral_id');
    }

    public function referral()
    {
        return $this->belongsTo(Referral::class, 'referral_id', 'referral_id')
                    ->with(['patient', 'reason', 'hospital']);
    }

    /**
     * Referral belongs to a patient
     */
    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'patient_id');
    }

    /**
     * Referral belongs to a hospital
     */
    public function hospital()
    {
        return $this->belongsTo(Hospital::class, 'hospital_id', 'hospital_id');
    }

    /**
     * Referral has a reason
     */
    public function reason()
    {
        return $this->belongsTo(Reason::class, 'reason_id', 'reason_id');
    }

    public function patientHistory()
    {
        return $this->belongsTo(PatientHistory::class, 'patient_histories_id', 'patient_histories_id');
    }

    public function bills()
    {
        return $this->hasMany(Bill::class, 'referral_id', 'referral_id');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function diagnoses()
    {
        return $this->belongsToMany(Diagnosis::class, 'diagnosis_referral', 'referral_id', 'diagnosis_id')->withTimestamps();
    }

    public function referralLetters()
    {
        return $this->hasOne(ReferralLetter::class, 'referral_id', 'referral_id');
    }

    public function boardedOutLetter()
    {
        return $this->hasOne(BoardedOutLetter::class, 'referral_id', 'referral_id');
    }

    public function referralFlights()
    {
        return $this->hasMany(ReferralFlight::class,'referral_id','referral_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*']);
    }
}
