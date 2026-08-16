<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationScore extends Model
{
    protected $fillable = [
        'evaluation_id',
        'criteria_id',
        'score',
        'comment',
    ];

    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function evaluation()
    {
        return $this->belongsTo(ProjectEvaluation::class, 'evaluation_id');
    }

    public function criterion()
    {
        return $this->belongsTo(EvaluationCriterion::class, 'criteria_id');
    }
}
