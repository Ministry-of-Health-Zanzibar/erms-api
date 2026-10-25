<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelemedicineRequestDocument extends Model
{
    use HasFactory;

    protected $table = 'telemedicine_request_documents';
    protected $primaryKey = 'telemedicine_request_document_id';

    protected $fillable = [
        'telemedicine_request_id',
        'uploaded_by',
        'document_type',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    protected $appends = ['file_url'];

    public function request()
    {
        return $this->belongsTo(TelemedicineRequest::class, 'telemedicine_request_id', 'telemedicine_request_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getFileUrlAttribute(): string
    {
        return route('telemedicine.documents.file', [
            'requestId' => $this->telemedicine_request_id,
            'documentId' => $this->telemedicine_request_document_id,
        ]);
    }
}
