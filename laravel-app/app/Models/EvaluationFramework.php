<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EvaluationFramework extends Model
{
    protected $fillable = [
        'code',
        'version',
        'name',
        'description',
        'fiscal_year_id',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function criteria()
    {
        return $this->hasMany(EvaluationCriterion::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function activeCriteria()
    {
        return $this->criteria()->where('is_active', true);
    }

    public function evaluations()
    {
        return $this->hasMany(ProjectEvaluation::class);
    }

    public function results()
    {
        return $this->hasMany(ProjectEvaluationResult::class);
    }

    public function scopeAvailableFor(
        Builder $query,
        ?int $fiscalYearId,
        string $evaluationDate,
    ): Builder {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $fiscalYear) use ($fiscalYearId): void {
                $fiscalYear->whereNull('fiscal_year_id');

                if ($fiscalYearId !== null) {
                    $fiscalYear->orWhere('fiscal_year_id', $fiscalYearId);
                }
            })
            ->where(function (Builder $effectiveFrom) use ($evaluationDate): void {
                $effectiveFrom->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $evaluationDate);
            })
            ->where(function (Builder $effectiveTo) use ($evaluationDate): void {
                $effectiveTo->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $evaluationDate);
            });
    }
}
