<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class HospitalLetter extends Model
{
    use LogsActivity, HasFactory, SoftDeletes;

    protected $primaryKey = 'letter_id';
    public $incrementing = true;   // bigIncrements (integer PK)
    protected $keyType = 'integer';

    protected $fillable = [
        'referral_id',
        'content_summary',
        'next_appointment_date',
        'letter_file',
        'outcome',
        'transferred_referral_id',
        'submission_key',
        'is_printed',
        'printed_at',
        'printed_by',
        'print_count',
        'last_printed_language',
    ];

    protected $casts = [
        'is_printed' => 'boolean',
        'printed_at' => 'datetime',
        'print_count' => 'integer',
    ];

    public function referral()
    {
        return $this->belongsTo(Referral::class, 'referral_id', 'referral_id');
    }

    public function followups()
    {
        return $this->hasMany(FollowUp::class, 'letter_id', 'letter_id');
    }

    public function transferredReferral()
    {
        return $this->belongsTo(Referral::class, 'transferred_referral_id', 'referral_id');
    }

    public function printedBy()
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    public function printEvents()
    {
        return $this->hasMany(LetterPrintEvent::class, 'letter_id', 'letter_id')
            ->where('letter_type', 'follow_up');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*']);
    }
}
