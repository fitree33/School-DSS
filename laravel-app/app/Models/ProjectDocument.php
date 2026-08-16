<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'original_name',
        'path',
        'mime_type',
        'size',
        'uploaded_by',
        'checksum',
        'version',
        'processing_status',
        'processed_at',
        'processing_error',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
