<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TelemedicineReport extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'telemedicine_reports';
    protected $primaryKey = 'telemedicine_report_id';

    protected $fillable = [
        'telemedicine_request_id',
        'telemedicine_appointment_id',
        'recorded_by',
        'report_text',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'file_size' => 'integer',
    ];

    protected $appends = ['file_url'];

    public function request()
    {
        return $this->belongsTo(TelemedicineRequest::class, 'telemedicine_request_id', 'telemedicine_request_id');
    }

    public function appointment()
    {
        return $this->belongsTo(TelemedicineAppointment::class, 'telemedicine_appointment_id', 'telemedicine_appointment_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return route('telemedicine.reports.file', [
            'requestId' => $this->telemedicine_request_id,
            'reportId' => $this->telemedicine_report_id,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['*'])->logOnlyDirty();
    }
}
