<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationCriterion extends Model
{
    protected $fillable = [
        'evaluation_framework_id',
        'name',
        'description',
        'weight',
        'max_score',
        'sort_order',
        'is_active',
        'evaluation_method',
        'evaluation_tools',
    ];

    public function framework()
    {
        return $this->belongsTo(EvaluationFramework::class, 'evaluation_framework_id');
    }

    protected $casts = [
        'weight' => 'decimal:2',
        'max_score' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scores()
    {
        return $this->hasMany(EvaluationScore::class, 'criteria_id');
    }
}
