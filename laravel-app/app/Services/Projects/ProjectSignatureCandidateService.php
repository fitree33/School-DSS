<?php

namespace App\Services\Projects;

use App\Enums\ProjectSignatureSlotCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ProjectSignatureCandidateService
{
    public function query(ProjectSignatureSlotCode $slotCode): Builder
    {
        return User::query()
            ->select(['id', 'name', 'role_id', 'department_id', 'is_active', 'deleted_at'])
            ->with(['role:id,code,name', 'department:id,name'])
            ->where('is_active', true)
            ->whereHas('role', fn (Builder $roles) => $roles->whereIn('code', $slotCode->eligibleRoleCodes()));
    }

    public function isEligible(User $user, ProjectSignatureSlotCode $slotCode): bool
    {
        return ! $user->trashed()
            && $user->is_active === true
            && in_array($user->role?->code, $slotCode->eligibleRoleCodes(), true);
    }

    public function status(?User $user, ProjectSignatureSlotCode $slotCode, ?int $assignedUserId): string
    {
        if ($assignedUserId === null) {
            return 'unassigned';
        }

        if ($user === null) {
            return 'missing';
        }

        if ($user->trashed()) {
            return 'soft_deleted';
        }

        if ($user->is_active !== true) {
            return 'inactive';
        }

        return $this->isEligible($user, $slotCode) ? 'eligible' : 'ineligible';
    }
}
