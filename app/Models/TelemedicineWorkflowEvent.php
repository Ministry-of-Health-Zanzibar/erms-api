<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelemedicineWorkflowEvent extends Model
{
    use HasFactory;

    protected $table = 'telemedicine_workflow_events';
    protected $primaryKey = 'telemedicine_workflow_event_id';

    protected $fillable = [
        'telemedicine_request_id',
        'action',
        'from_status',
        'to_status',
        'actor_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function request()
    {
        return $this->belongsTo(TelemedicineRequest::class, 'telemedicine_request_id', 'telemedicine_request_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
