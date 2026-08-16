<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectExecutionStatus extends Model
{
    protected $fillable = [
        'code',
        'name',
        'color',
        'sort_order',
        'is_terminal',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_terminal' => 'boolean',
    ];

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function fromHistories()
    {
        return $this->hasMany(ProjectExecutionStatusHistory::class, 'from_status_id');
    }

    public function toHistories()
    {
        return $this->hasMany(ProjectExecutionStatusHistory::class, 'to_status_id');
    }
}
