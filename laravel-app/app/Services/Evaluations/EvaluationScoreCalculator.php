<?php

namespace App\Services\Evaluations;

use App\Models\EvaluationCriterion;
use Illuminate\Support\Collection;

class EvaluationScoreCalculator
{
    /**
     * @param  Collection<int, EvaluationCriterion>  $criteria
     * @param  array<int, array{evaluation_criterion_id: int, score: int|float|string, comment?: string|null}>  $scores
     * @return array{total_score: float, maximum_score: float, percentage: float, weighted_percentage: float|null}
     */
    public function calculate(Collection $criteria, array $scores): array
    {
        $scoreByCriterion = collect($scores)->keyBy(
            fn (array $score): int => (int) $score['evaluation_criterion_id'],
        );

        $totalScore = round((float) $criteria->sum(
            fn (EvaluationCriterion $criterion): float => (float) $scoreByCriterion
                ->get($criterion->id)['score'],
        ), 2);
        $maximumScore = round((float) $criteria->sum('max_score'), 2);
        $percentage = $maximumScore > 0
            ? round(($totalScore / $maximumScore) * 100, 2)
            : 0.0;

        $hasCompleteWeights = $criteria->isNotEmpty()
            && $criteria->every(fn (EvaluationCriterion $criterion): bool => (float) $criterion->weight > 0);
        $weightedPercentage = null;

        if ($hasCompleteWeights) {
            $totalWeight = (float) $criteria->sum('weight');
            $weightedTotal = (float) $criteria->sum(function (EvaluationCriterion $criterion) use ($scoreByCriterion): float {
                $score = (float) $scoreByCriterion->get($criterion->id)['score'];

                return ($score / (float) $criterion->max_score) * (float) $criterion->weight;
            });
            $weightedPercentage = round(($weightedTotal / $totalWeight) * 100, 2);
        }

        return [
            'total_score' => $totalScore,
            'maximum_score' => $maximumScore,
            'percentage' => $percentage,
            'weighted_percentage' => $weightedPercentage,
        ];
    }
}
