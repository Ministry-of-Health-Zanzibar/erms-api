<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatientHistoryConversationStatus extends Model
{
    use HasFactory;

    protected $table = 'patient_history_conversation_statuses';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'read_at',
        'is_notified',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'is_notified' => 'boolean',
    ];

    public function conversation()
    {
        return $this->belongsTo(
            PatientHistoryConversation::class,
            'conversation_id',
            'conversation_id'
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
