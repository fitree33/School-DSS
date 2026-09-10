<?php

namespace App\Services\Projects;

use App\Models\AuditLog;
use App\Models\EvaluationStatus;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\ProjectExecutionStatus;
use App\Models\ProjectExecutionStatusHistory;
use App\Models\ProjectStatus;
use App\Models\ProjectStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

class ProjectService
{
    public function create(User $actor, array $attributes): Project
    {
        return DB::transaction(
            fn (): Project => $this->createInTransaction($actor, $attributes),
            3,
        );
    }

    /**
     * Create a canonical project as part of a caller-owned transaction.
     *
     * Import confirmation uses this entry point so the project, access rows,
     * histories, imported document association, and audits commit atomically.
     */
    public function createInTransaction(User $actor, array $attributes): Project
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('ProjectService::createInTransaction requires an active database transaction.');
        }

        $draftStatusId = $this->statusId(ProjectStatus::class, 'draft');
        $notStartedStatusId = $this->statusId(ProjectExecutionStatus::class, 'not_started');
        $pendingEvaluationId = $this->statusId(EvaluationStatus::class, 'pending');

        $this->ensureFiscalYearsWritable([$attributes['fiscal_year_id'] ?? null]);

        $project = Project::create(array_merge($attributes, [
            'user_id' => $actor->id,
            'responsible_person' => $attributes['responsible_person'] ?? $actor->name,
            'actual_spent' => 0,
            'project_status_id' => $draftStatusId,
            'project_execution_status_id' => $notStartedStatusId,
            'evaluation_status_id' => $pendingEvaluationId,
        ]));

        ProjectAccess::create([
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'can_view' => true,
            'can_edit' => true,
            'can_delete' => true,
            'granted_by' => $actor->id,
        ]);

        ProjectStatusHistory::create([
            'project_id' => $project->id,
            'from_status_id' => null,
            'to_status_id' => $draftStatusId,
            'changed_by' => $actor->id,
            'comment' => 'สร้างโครงการผ่าน API V2',
        ]);

        ProjectExecutionStatusHistory::create([
            'project_id' => $project->id,
            'from_status_id' => null,
            'to_status_id' => $notStartedStatusId,
            'changed_by' => $actor->id,
            'comment' => 'กำหนดสถานะเริ่มต้น',
        ]);

        AuditLog::record('project.created', $project, [], $project->getAttributes());

        return $project->refresh();
    }

    public function update(User $actor, Project $project, array $attributes): Project
    {
        if (array_key_exists('evaluation_status', $attributes)
            || array_key_exists('evaluation_status_id', $attributes)) {
            throw ValidationException::withMessages([
                'evaluation_status' => ['Evaluation status can only change through the evaluation finalize workflow.'],
            ]);
        }

        $executionStatusCode = Arr::pull($attributes, 'execution_status');
        $executionComment = Arr::pull($attributes, 'execution_status_comment');

        if (array_key_exists('fiscal_year_id', $attributes)
            && ! array_key_exists('school_plan_id', $attributes)
            && (int) $attributes['fiscal_year_id'] !== (int) $project->fiscal_year_id) {
            $attributes['school_plan_id'] = null;
        }

        return DB::transaction(function () use (
            $actor,
            $project,
            $attributes,
            $executionStatusCode,
            $executionComment,
        ) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::forUser($actor)->authorize('update', $project);

            $targetFiscalYearId = array_key_exists('fiscal_year_id', $attributes)
                ? (int) $attributes['fiscal_year_id']
                : null;
            $this->ensureFiscalYearsWritable([
                $project->fiscal_year_id,
                $targetFiscalYearId,
            ]);
            $this->ensureExecutionTransitionIsAllowed($project, $executionStatusCode);

            $oldValues = $project->only(array_keys($attributes));
            $fromExecutionStatusId = $project->project_execution_status_id;

            if ($executionStatusCode !== null) {
                $attributes['project_execution_status_id'] = $this->statusId(
                    ProjectExecutionStatus::class,
                    $executionStatusCode,
                );
                $oldValues['project_execution_status_id'] = $fromExecutionStatusId;
            }

            $project->update($attributes);

            if ($executionStatusCode !== null
                && (int) $fromExecutionStatusId !== (int) $project->project_execution_status_id) {
                ProjectExecutionStatusHistory::create([
                    'project_id' => $project->id,
                    'from_status_id' => $fromExecutionStatusId,
                    'to_status_id' => $project->project_execution_status_id,
                    'changed_by' => $actor->id,
                    'comment' => $executionComment,
                ]);
            }

            AuditLog::record(
                'project.updated',
                $project,
                $oldValues,
                $project->only(array_keys($attributes)),
            );

            return $project->refresh();
        });
    }

    public function delete(User $actor, Project $project): void
    {
        DB::transaction(function () use ($actor, $project): void {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::forUser($actor)->authorize('delete', $project);
            $this->ensureFiscalYearsWritable([$project->fiscal_year_id]);

            AuditLog::record('project.deleted', $project, $project->getAttributes());
            $project->delete();
        });
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function statusId(string $model, string $code): int
    {
        $id = $model::query()->where('code', $code)->value('id');

        if (! $id) {
            throw new RuntimeException("Required project status [{$code}] is not configured.");
        }

        return (int) $id;
    }

    /**
     * @param  array<int, int|string|null>  $fiscalYearIds
     */
    private function ensureFiscalYearsWritable(array $fiscalYearIds): void
    {
        $ids = collect($fiscalYearIds)
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $hasLockedYear = FiscalYear::query()
            ->whereKey($ids->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->contains(fn (FiscalYear $year): bool => $year->is_locked);

        if ($hasLockedYear) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => ['The selected fiscal year is locked and read-only.'],
            ]);
        }
    }

    private function ensureExecutionTransitionIsAllowed(Project $project, ?string $requested): void
    {
        if ($requested === null) {
            return;
        }

        $current = $project->executionStatus()->value('code');
        $allowed = match ($current) {
            'not_started' => ['not_started', 'in_progress'],
            'in_progress' => ['in_progress', 'completed'],
            'completed' => ['completed'],
            default => ['not_started'],
        };

        if (! in_array($requested, $allowed, true)) {
            throw ValidationException::withMessages([
                'execution_status' => ['The requested execution status is not a permitted next step.'],
            ]);
        }
    }
}
