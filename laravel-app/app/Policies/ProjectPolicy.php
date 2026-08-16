<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active === false ? false : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $project->user_id === $user->id
            || $user->hasPermission('projects.view_all')
            || ($user->department_id
                && $project->department_id === $user->department_id
                && $user->hasPermission('projects.view_department'))
            || $project->hasAccess($user, 'view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        $ownerCanEdit = $project->user_id === $user->id
            && in_array($project->status?->code, ['draft', 'returned'], true);

        return $ownerCanEdit
            || $user->hasPermission('projects.edit_all')
            || ($user->department_id
                && $project->department_id === $user->department_id
                && $user->hasPermission('projects.edit_department'))
            || $project->hasAccess($user, 'edit');
    }

    public function delete(User $user, Project $project): bool
    {
        $ownerCanDelete = $project->user_id === $user->id
            && in_array($project->status?->code, ['draft', 'returned'], true);

        return $ownerCanDelete
            || $user->hasPermission('projects.delete_all')
            || ($user->department_id
                && $project->department_id === $user->department_id
                && $user->hasPermission('projects.delete_department'))
            || $project->hasAccess($user, 'delete');
    }

    public function restore(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.delete_all');
    }

    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }

    public function manageAccess(User $user, Project $project): bool
    {
        return $user->hasPermission('projects.manage_access');
    }

    public function evaluate(User $user, Project $project): bool
    {
        return $this->view($user, $project) && $user->hasPermission('projects.evaluate');
    }

    public function submit(User $user, Project $project): bool
    {
        return $project->user_id === $user->id
            && in_array($project->status?->code, ['draft', 'returned'], true);
    }

    public function screen(User $user, Project $project): bool
    {
        return $user->hasRole('deputy_director')
            && $project->status?->code === 'pending_deputy';
    }

    public function decide(User $user, Project $project): bool
    {
        return $user->hasRole('director')
            && $project->status?->code === 'pending_director';
    }

    public function complete(User $user, Project $project): bool
    {
        return in_array($project->status?->code, ['approved', 'in_progress'], true)
            && ($project->user_id === $user->id
                || $user->hasRole('deputy_director')
                || $user->hasRole('director'));
    }
}
