<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectEvaluation extends Model
{
    protected $fillable = [
        'project_id',
        'evaluator_id',
        'round',
        'total_score',
        'comment',
        'evaluated_at',
    ];

    protected $casts = [
        'total_score' => 'decimal:2',
        'evaluated_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function scores()
    {
        return $this->hasMany(EvaluationScore::class, 'evaluation_id');
    }
}
