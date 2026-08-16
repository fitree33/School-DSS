<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectCompletionReport extends Model
{
    protected $fillable = [
        'project_id',
        'reported_by',
        'success_percent',
        'quality_score',
        'actual_spent',
        'summary',
        'problems',
        'suggestions',
        'reported_at',
    ];

    protected $casts = [
        'success_percent' => 'decimal:2',
        'quality_score' => 'decimal:2',
        'actual_spent' => 'decimal:2',
        'reported_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
