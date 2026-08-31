<?php

namespace App\Policies;

use App\Models\EvaluationFramework;
use App\Models\User;

class EvaluationFrameworkPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active === false ? false : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('evaluations.view');
    }

    public function view(User $user, EvaluationFramework $framework): bool
    {
        return $user->hasPermission('evaluations.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('evaluations.manage_frameworks');
    }

    public function update(User $user, EvaluationFramework $framework): bool
    {
        return $user->hasPermission('evaluations.manage_frameworks')
            && ! $this->isLocked($framework);
    }

    public function createVersion(User $user, EvaluationFramework $framework): bool
    {
        return $user->hasPermission('evaluations.manage_frameworks')
            && ! $this->isLocked($framework);
    }

    public function activate(User $user, EvaluationFramework $framework): bool
    {
        return $user->hasPermission('evaluations.manage_frameworks')
            && ! $this->isLocked($framework);
    }

    public function deactivate(User $user, EvaluationFramework $framework): bool
    {
        return $user->hasPermission('evaluations.manage_frameworks')
            && ! $this->isLocked($framework);
    }

    private function isLocked(EvaluationFramework $framework): bool
    {
        return $framework->fiscalYear?->is_locked === true;
    }
}
