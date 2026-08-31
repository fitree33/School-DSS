<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectEvaluationResult extends Model
{
    protected $fillable = [
        'project_id',
        'project_evaluation_id',
        'evaluation_framework_id',
        'evaluation_status_id',
        'total_score',
        'maximum_score',
        'percentage',
        'weighted_percentage',
        'scores_snapshot',
        'decision_note',
        'finalized_by',
        'finalized_at',
    ];

    protected $casts = [
        'total_score' => 'decimal:2',
        'maximum_score' => 'decimal:2',
        'percentage' => 'decimal:2',
        'weighted_percentage' => 'decimal:2',
        'scores_snapshot' => 'array',
        'finalized_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function evaluation()
    {
        return $this->belongsTo(ProjectEvaluation::class, 'project_evaluation_id');
    }

    public function framework()
    {
        return $this->belongsTo(EvaluationFramework::class, 'evaluation_framework_id');
    }

    public function status()
    {
        return $this->belongsTo(EvaluationStatus::class, 'evaluation_status_id');
    }

    public function finalizer()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
