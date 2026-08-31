<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $fillable = [
        'year',
        'start_date',
        'end_date',
        'is_active',
        'is_locked',
    ];

    protected $casts = [
        'year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
    ];

    public function schoolBudget()
    {
        return $this->hasOne(SchoolBudget::class);
    }

    public function schoolPlans()
    {
        return $this->hasMany(SchoolPlan::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function evaluationFrameworks()
    {
        return $this->hasMany(EvaluationFramework::class);
    }
}
