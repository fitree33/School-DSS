<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationStatus extends Model
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

    public function evaluationResults()
    {
        return $this->hasMany(ProjectEvaluationResult::class);
    }
}
