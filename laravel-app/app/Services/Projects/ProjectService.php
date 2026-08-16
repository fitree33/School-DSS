<?php

namespace App\Services\Projects;

use App\Models\AuditLog;
use App\Models\EvaluationStatus;
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
use RuntimeException;

class ProjectService
{
    public function create(User $actor, array $attributes): Project
    {
        $draftStatusId = $this->statusId(ProjectStatus::class, 'draft');
        $notStartedStatusId = $this->statusId(ProjectExecutionStatus::class, 'not_started');
        $pendingEvaluationId = $this->statusId(EvaluationStatus::class, 'pending');

        return DB::transaction(function () use (
            $actor,
            $attributes,
            $draftStatusId,
            $notStartedStatusId,
            $pendingEvaluationId,
        ) {
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
        });
    }

    public function update(User $actor, Project $project, array $attributes): Project
    {
        $executionStatusCode = Arr::pull($attributes, 'execution_status');
        $executionComment = Arr::pull($attributes, 'execution_status_comment');
        $evaluationStatusCode = Arr::pull($attributes, 'evaluation_status');

        if ($evaluationStatusCode !== null) {
            Gate::forUser($actor)->authorize('evaluate', $project);
        }

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
            $evaluationStatusCode,
        ) {
            $oldValues = $project->only(array_keys($attributes));
            $fromExecutionStatusId = $project->project_execution_status_id;

            if ($executionStatusCode !== null) {
                $attributes['project_execution_status_id'] = $this->statusId(
                    ProjectExecutionStatus::class,
                    $executionStatusCode,
                );
                $oldValues['project_execution_status_id'] = $fromExecutionStatusId;
            }

            if ($evaluationStatusCode !== null) {
                $attributes['evaluation_status_id'] = $this->statusId(
                    EvaluationStatus::class,
                    $evaluationStatusCode,
                );
                $oldValues['evaluation_status_id'] = $project->evaluation_status_id;
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

    public function delete(Project $project): void
    {
        DB::transaction(function () use ($project): void {
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
}
