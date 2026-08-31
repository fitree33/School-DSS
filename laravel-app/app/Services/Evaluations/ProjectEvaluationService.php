<?php

namespace App\Services\Evaluations;

use App\Models\AuditLog;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationFramework;
use App\Models\EvaluationScore;
use App\Models\EvaluationStatus;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectEvaluation;
use App\Models\ProjectEvaluationResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProjectEvaluationService
{
    public function __construct(private readonly EvaluationScoreCalculator $calculator) {}

    public function create(
        User $actor,
        Project $project,
        array $attributes,
    ): ProjectEvaluation {
        return DB::transaction(function () use ($actor, $project, $attributes): ProjectEvaluation {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $this->lockFiscalYear($project);
            Gate::forUser($actor)->authorize('create', [ProjectEvaluation::class, $project]);

            $framework = EvaluationFramework::query()
                ->lockForUpdate()
                ->findOrFail((int) $attributes['evaluation_framework_id']);
            $evaluationDate = CarbonImmutable::parse($attributes['evaluated_at'])->toDateString();
            $this->ensureFrameworkIsAvailable($framework, $project, $evaluationDate, true);

            $criteria = $this->lockActiveCriteria($framework);
            $scores = $this->validatedScores($criteria, $attributes['scores']);
            $totals = $this->calculator->calculate($criteria, $scores);
            $round = (int) ProjectEvaluation::query()
                ->where('project_id', $project->id)
                ->where('evaluator_id', $actor->id)
                ->max('round') + 1;

            $evaluation = ProjectEvaluation::create([
                'project_id' => $project->id,
                'evaluator_id' => $actor->id,
                'evaluation_framework_id' => $framework->id,
                'round' => max(1, $round),
                ...$totals,
                'comment' => $attributes['comment'] ?? null,
                'evaluated_at' => $evaluationDate,
            ]);
            $this->createScores($evaluation, $scores);

            AuditLog::record('project_evaluation.created', $evaluation, [], [
                'project_id' => $project->id,
                'evaluation_framework_id' => $framework->id,
                'evaluator_id' => $actor->id,
                'round' => $evaluation->round,
                ...$totals,
            ]);

            return $this->load($evaluation->refresh());
        }, 3);
    }

    public function update(
        User $actor,
        ProjectEvaluation $evaluation,
        array $attributes,
    ): ProjectEvaluation {
        return DB::transaction(function () use ($actor, $evaluation, $attributes): ProjectEvaluation {
            $project = Project::query()->lockForUpdate()->findOrFail($evaluation->project_id);
            $this->lockFiscalYear($project);
            $evaluation = ProjectEvaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
            $evaluation->setRelation('project', $project);
            Gate::forUser($actor)->authorize('update', $evaluation);

            $framework = EvaluationFramework::query()
                ->lockForUpdate()
                ->findOrFail($evaluation->evaluation_framework_id);
            $evaluationDate = CarbonImmutable::parse($attributes['evaluated_at'])->toDateString();
            $this->ensureFrameworkIsAvailable($framework, $project, $evaluationDate, false);

            $criteria = $this->lockActiveCriteria($framework);
            $scores = $this->validatedScores($criteria, $attributes['scores']);
            $totals = $this->calculator->calculate($criteria, $scores);
            $oldValues = $evaluation->only([
                'total_score',
                'maximum_score',
                'percentage',
                'weighted_percentage',
                'comment',
                'evaluated_at',
            ]);

            $evaluation->update([
                ...$totals,
                'comment' => $attributes['comment'] ?? null,
                'evaluated_at' => $evaluationDate,
            ]);
            $this->replaceDraftScores($evaluation, $scores);

            AuditLog::record('project_evaluation.updated', $evaluation, $oldValues, [
                ...$evaluation->only([
                    'total_score',
                    'maximum_score',
                    'percentage',
                    'weighted_percentage',
                    'comment',
                    'evaluated_at',
                ]),
                'score_count' => count($scores),
            ]);

            return $this->load($evaluation->refresh());
        }, 3);
    }

    public function finalize(
        User $actor,
        ProjectEvaluation $evaluation,
        array $attributes,
    ): ProjectEvaluation {
        return DB::transaction(function () use ($actor, $evaluation, $attributes): ProjectEvaluation {
            $project = Project::query()->lockForUpdate()->findOrFail($evaluation->project_id);
            $this->lockFiscalYear($project);
            $evaluation = ProjectEvaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
            $evaluation->setRelation('project', $project);
            Gate::forUser($actor)->authorize('finalize', $evaluation);

            $framework = EvaluationFramework::query()
                ->lockForUpdate()
                ->findOrFail($evaluation->evaluation_framework_id);
            $criteria = $this->lockActiveCriteria($framework);
            $storedScores = EvaluationScore::query()
                ->where('evaluation_id', $evaluation->id)
                ->orderBy('criteria_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('criteria_id');
            $scoreInput = $criteria->map(function (EvaluationCriterion $criterion) use ($storedScores): array {
                /** @var EvaluationScore|null $score */
                $score = $storedScores->get($criterion->id);

                return [
                    'evaluation_criterion_id' => $criterion->id,
                    'score' => $score?->score,
                    'comment' => $score?->comment,
                ];
            })->all();
            $scores = $this->validatedScores($criteria, $scoreInput);
            $totals = $this->calculator->calculate($criteria, $scores);
            $status = EvaluationStatus::query()
                ->where('code', $attributes['status'])
                ->lockForUpdate()
                ->first();

            if (! $status) {
                throw new RuntimeException("Required evaluation status [{$attributes['status']}] is not configured.");
            }

            $finalizedAt = now();
            $oldProjectStatusId = $project->evaluation_status_id;
            $evaluation->update([
                ...$totals,
                'finalized_by' => $actor->id,
                'finalized_at' => $finalizedAt,
            ]);

            $result = ProjectEvaluationResult::create([
                'project_id' => $project->id,
                'project_evaluation_id' => $evaluation->id,
                'evaluation_framework_id' => $framework->id,
                'evaluation_status_id' => $status->id,
                ...$totals,
                'scores_snapshot' => $this->scoreSnapshot($criteria, $storedScores),
                'decision_note' => $attributes['decision_note'],
                'finalized_by' => $actor->id,
                'finalized_at' => $finalizedAt,
            ]);
            $project->update(['evaluation_status_id' => $status->id]);

            AuditLog::record('project_evaluation.finalized', $result, [
                'project_evaluation_id' => $evaluation->id,
                'evaluation_status_id' => $oldProjectStatusId,
            ], [
                'project_id' => $project->id,
                'project_evaluation_id' => $evaluation->id,
                'evaluation_status_id' => $status->id,
                'status' => $status->code,
                'decision_note' => $result->decision_note,
                'finalized_by' => $actor->id,
                'finalized_at' => $finalizedAt->toISOString(),
                ...$totals,
            ]);

            return $this->load($evaluation->refresh());
        }, 3);
    }

    private function lockFiscalYear(Project $project): ?FiscalYear
    {
        if ($project->fiscal_year_id === null) {
            return null;
        }

        $fiscalYear = FiscalYear::query()->lockForUpdate()->findOrFail($project->fiscal_year_id);
        $project->setRelation('fiscalYear', $fiscalYear);

        return $fiscalYear;
    }

    private function ensureFrameworkIsAvailable(
        EvaluationFramework $framework,
        Project $project,
        string $evaluationDate,
        bool $requireActive,
    ): void {
        if ($requireActive && ! $framework->is_active) {
            throw ValidationException::withMessages([
                'evaluation_framework_id' => ['The selected evaluation framework is inactive.'],
            ]);
        }

        if ($framework->fiscal_year_id !== null
            && (int) $framework->fiscal_year_id !== (int) $project->fiscal_year_id) {
            throw ValidationException::withMessages([
                'evaluation_framework_id' => ['The selected evaluation framework is not available for this fiscal year.'],
            ]);
        }

        if (($framework->effective_from && $evaluationDate < $framework->effective_from->toDateString())
            || ($framework->effective_to && $evaluationDate > $framework->effective_to->toDateString())) {
            throw ValidationException::withMessages([
                'evaluated_at' => ['The evaluation date is outside the framework effective period.'],
            ]);
        }
    }

    /** @return Collection<int, EvaluationCriterion> */
    private function lockActiveCriteria(EvaluationFramework $framework): Collection
    {
        $criteria = EvaluationCriterion::query()
            ->where('evaluation_framework_id', $framework->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($criteria->isEmpty()) {
            throw ValidationException::withMessages([
                'scores' => ['The selected framework has no active criteria.'],
            ]);
        }

        return $criteria;
    }

    /**
     * @param  Collection<int, EvaluationCriterion>  $criteria
     * @param  array<int, array<string, mixed>>  $scores
     * @return array<int, array{evaluation_criterion_id: int, score: int|float|string, comment: string|null}>
     */
    private function validatedScores(Collection $criteria, array $scores): array
    {
        $submitted = collect($scores);
        $submittedIds = $submitted
            ->pluck('evaluation_criterion_id')
            ->map(fn ($id): int => (int) $id);
        $expectedIds = $criteria->pluck('id')->map(fn ($id): int => (int) $id);

        if ($submittedIds->duplicates()->isNotEmpty()
            || $submittedIds->sort()->values()->all() !== $expectedIds->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'scores' => ['Submit exactly one score for every active criterion in the selected framework.'],
            ]);
        }

        $criteriaById = $criteria->keyBy('id');

        return $submitted->values()->map(function (array $score, int $index) use ($criteriaById): array {
            /** @var EvaluationCriterion $criterion */
            $criterion = $criteriaById->get((int) $score['evaluation_criterion_id']);
            $value = $score['score'];

            if (! is_numeric($value)
                || (float) $value < 0
                || (float) $value > (float) $criterion->max_score) {
                throw ValidationException::withMessages([
                    "scores.{$index}.score" => ["The score must be between 0 and {$criterion->max_score}."],
                ]);
            }

            return [
                'evaluation_criterion_id' => $criterion->id,
                'score' => $value,
                'comment' => $score['comment'] ?? null,
            ];
        })->all();
    }

    private function createScores(ProjectEvaluation $evaluation, array $scores): void
    {
        foreach ($scores as $score) {
            EvaluationScore::create([
                'evaluation_id' => $evaluation->id,
                'criteria_id' => $score['evaluation_criterion_id'],
                'score' => $score['score'],
                'comment' => $score['comment'],
            ]);
        }
    }

    private function replaceDraftScores(ProjectEvaluation $evaluation, array $scores): void
    {
        $stored = EvaluationScore::query()
            ->where('evaluation_id', $evaluation->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('criteria_id');

        foreach ($scores as $score) {
            /** @var EvaluationScore|null $model */
            $model = $stored->get($score['evaluation_criterion_id']);
            $attributes = [
                'score' => $score['score'],
                'comment' => $score['comment'],
            ];

            if ($model) {
                $model->update($attributes);
            } else {
                EvaluationScore::create([
                    'evaluation_id' => $evaluation->id,
                    'criteria_id' => $score['evaluation_criterion_id'],
                    ...$attributes,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, EvaluationCriterion>  $criteria
     * @param  Collection<int, EvaluationScore>  $scores
     * @return array<int, array<string, mixed>>
     */
    private function scoreSnapshot(Collection $criteria, Collection $scores): array
    {
        return $criteria->map(function (EvaluationCriterion $criterion) use ($scores): array {
            /** @var EvaluationScore $score */
            $score = $scores->get($criterion->id);

            return [
                'evaluation_criterion_id' => $criterion->id,
                'name' => $criterion->name,
                'description' => $criterion->description,
                'max_score' => $criterion->max_score,
                'weight' => $criterion->weight,
                'sort_order' => $criterion->sort_order,
                'evaluation_method' => $criterion->evaluation_method,
                'evaluation_tools' => $criterion->evaluation_tools,
                'score' => $score->score,
                'comment' => $score->comment,
            ];
        })->values()->all();
    }

    private function load(ProjectEvaluation $evaluation): ProjectEvaluation
    {
        return $evaluation->load([
            'project.department:id,name',
            'project.fiscalYear:id,year,is_locked',
            'project.evaluationStatus:id,code,name,color,is_terminal',
            'evaluator:id,name',
            'finalizer:id,name',
            'framework' => fn ($query) => $query->withExists('evaluations'),
            'framework.fiscalYear:id,year,is_locked',
            'framework.criteria',
            'scores.criterion',
            'result.status:id,code,name,color,is_terminal',
            'result.finalizer:id,name',
        ]);
    }
}
