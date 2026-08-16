<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'from_status_id',
        'to_status_id',
        'changed_by',
        'comment',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function fromStatus()
    {
        return $this->belongsTo(ProjectStatus::class, 'from_status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(ProjectStatus::class, 'to_status_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
