<?php

namespace App\Services;

use App\Models\EvaluationCriterion;
use App\Models\Project;
use Illuminate\Support\Collection;

class DssPortfolioService
{
    public const METHOD = 'SAW weighted sum + budget knapsack v1';

    public function normalizeWeights(Collection $criteria, array $weights): array
    {
        $resolved = $criteria->mapWithKeys(function (EvaluationCriterion $criterion) use ($weights) {
            return [$criterion->id => max(0, (float) ($weights[$criterion->id] ?? $criterion->weight))];
        })->all();

        $sum = array_sum($resolved);

        if ($sum <= 0) {
            $equal = $criteria->isEmpty() ? 0 : 100 / $criteria->count();

            return $criteria->mapWithKeys(fn ($criterion) => [$criterion->id => $equal])->all();
        }

        return collect($resolved)
            ->map(fn (float $weight) => ($weight / $sum) * 100)
            ->all();
    }

    public function rank(Collection $projects, Collection $criteria, array $weights): Collection
    {
        $weights = $this->normalizeWeights($criteria, $weights);

        $ranked = $projects->map(function (Project $project) use ($criteria, $weights) {
            $latestEvaluations = $project->evaluations
                ->whereNull('evaluation_framework_id')
                ->whereNotNull('evaluated_at')
                ->sortByDesc(fn ($evaluation) => sprintf(
                    '%05d-%010d',
                    $evaluation->round,
                    $evaluation->evaluated_at?->timestamp ?? 0
                ))
                ->unique('evaluator_id')
                ->values();

            $criterionScores = [];

            foreach ($criteria as $criterion) {
                $normalizedScores = $latestEvaluations->map(function ($evaluation) use ($criterion) {
                    $score = $evaluation->scores->firstWhere('criteria_id', $criterion->id);

                    if (! $score) {
                        return null;
                    }

                    return min(1, max(0, (float) $score->score / max(0.01, (float) $criterion->max_score)));
                })->filter(fn ($score) => $score !== null);

                $criterionScores[$criterion->id] = $normalizedScores->isEmpty()
                    ? null
                    : round((float) $normalizedScores->avg(), 4);
            }

            $available = collect($criterionScores)->filter(fn ($score) => $score !== null);
            $score = null;

            if ($available->isNotEmpty()) {
                $availableWeight = $available->keys()
                    ->map(fn ($criterionId) => $weights[$criterionId] ?? 0)
                    ->sum();
                $weighted = $available
                    ->map(fn ($value, $criterionId) => $value * ($weights[$criterionId] ?? 0))
                    ->sum();
                $score = $availableWeight > 0 ? round(($weighted / $availableWeight) * 100, 2) : 0;
            }

            $ordered = collect($criterionScores)
                ->filter(fn ($value) => $value !== null)
                ->map(fn ($value, $criterionId) => [
                    'criterion' => $criteria->firstWhere('id', (int) $criterionId),
                    'value' => $value,
                ]);

            $project->setAttribute('portfolio_score', $score);
            $project->setAttribute('criterion_scores', $criterionScores);
            $project->setAttribute('strengths', $ordered->sortByDesc('value')->take(2)->pluck('criterion.name')->filter()->implode(', '));
            $project->setAttribute('weaknesses', $ordered->sortBy('value')->take(2)->pluck('criterion.name')->filter()->implode(', '));
            $project->setAttribute('evaluator_count', $latestEvaluations->count());

            return $project;
        })
            ->sort(function (Project $a, Project $b) {
                if ($a->portfolio_score === null && $b->portfolio_score === null) {
                    return $a->name <=> $b->name;
                }
                if ($a->portfolio_score === null) {
                    return 1;
                }
                if ($b->portfolio_score === null) {
                    return -1;
                }

                return $b->portfolio_score <=> $a->portfolio_score;
            })
            ->values();

        $rank = 0;
        $ranked->each(function (Project $project) use (&$rank) {
            $project->setAttribute('portfolio_rank', $project->portfolio_score === null ? null : ++$rank);
        });

        return $ranked;
    }

    public function optimizeBudget(Collection $rankedProjects, float $budgetLimit): array
    {
        $eligible = $rankedProjects
            ->filter(fn (Project $project) => $project->portfolio_score !== null && (float) $project->budget >= 0)
            ->values();

        if ($budgetLimit <= 0 || $eligible->isEmpty()) {
            return ['ids' => [], 'budget' => 0.0, 'score' => 0.0, 'unit' => 1];
        }

        $unit = max(1, (int) ceil($budgetLimit / 5000));
        $capacity = max(1, (int) floor($budgetLimit / $unit));
        $states = array_fill(0, $capacity + 1, null);
        $states[0] = ['score' => 0.0, 'ids' => [], 'budget' => 0.0];

        foreach ($eligible as $project) {
            $cost = max(1, (int) ceil((float) $project->budget / $unit));

            for ($current = $capacity; $current >= $cost; $current--) {
                $previous = $states[$current - $cost];

                if ($previous === null) {
                    continue;
                }

                $candidateBudget = $previous['budget'] + (float) $project->budget;
                if ($candidateBudget > $budgetLimit) {
                    continue;
                }

                $candidateScore = $previous['score'] + (float) $project->portfolio_score;
                if ($states[$current] === null || $candidateScore > $states[$current]['score']) {
                    $states[$current] = [
                        'score' => $candidateScore,
                        'ids' => [...$previous['ids'], $project->id],
                        'budget' => $candidateBudget,
                    ];
                }
            }
        }

        $best = collect($states)
            ->filter()
            ->sortByDesc('score')
            ->first() ?? ['score' => 0.0, 'ids' => [], 'budget' => 0.0];

        return $best + ['unit' => $unit];
    }
}
