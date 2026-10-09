<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoardedOutLetter extends Model
{
    use SoftDeletes;

    protected $table = 'boarded_out_letters';

    protected $fillable = [
        'patient_histories_id',
        'referral_id',
        'receiver',
        'reference_number',
        'reference_date',
        'recommendations',
        'is_printed',
        'printed_at',
        'printed_by',
        'print_count',
        'last_printed_language',
    ];

    protected $casts = [
        'reference_date' => 'date',
        'recommendations' => 'array', // 🔥 auto JSON handling
        'is_printed' => 'boolean',
        'printed_at' => 'datetime',
        'print_count' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function patientHistory()
    {
        return $this->belongsTo(PatientHistory::class, 'patient_histories_id', 'patient_histories_id');
    }

    public function referral()
    {
        return $this->belongsTo(Referral::class, 'referral_id', 'referral_id');
    }

    public function printedBy()
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    public function printEvents()
    {
        return $this->hasMany(LetterPrintEvent::class, 'letter_id', 'id')
            ->where('letter_type', 'boarded_out');
    }
}
