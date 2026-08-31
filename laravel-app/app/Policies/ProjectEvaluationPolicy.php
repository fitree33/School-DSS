<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectEvaluation;
use App\Models\User;

class ProjectEvaluationPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active === false ? false : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('evaluations.view');
    }

    public function view(User $user, ProjectEvaluation $evaluation): bool
    {
        return $evaluation->evaluation_framework_id !== null
            && $user->hasPermission('evaluations.view')
            && $user->can('view', $evaluation->project);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasPermission('evaluations.create')
            && $user->can('view', $project)
            && ! $this->isLocked($project);
    }

    public function update(User $user, ProjectEvaluation $evaluation): bool
    {
        return $evaluation->evaluation_framework_id !== null
            && $user->hasPermission('evaluations.update')
            && (int) $evaluation->evaluator_id === (int) $user->id
            && $evaluation->finalized_at === null
            && $evaluation->result()->doesntExist()
            && $user->can('view', $evaluation->project)
            && ! $this->isLocked($evaluation->project);
    }

    public function finalize(User $user, ProjectEvaluation $evaluation): bool
    {
        return $evaluation->evaluation_framework_id !== null
            && $user->hasPermission('evaluations.finalize')
            && $evaluation->finalized_at === null
            && $evaluation->result()->doesntExist()
            && $user->can('view', $evaluation->project)
            && ! $this->isLocked($evaluation->project);
    }

    private function isLocked(Project $project): bool
    {
        return $project->fiscalYear?->is_locked === true;
    }
}
