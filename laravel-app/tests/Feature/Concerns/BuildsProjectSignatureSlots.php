<?php

namespace Tests\Feature\Concerns;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\Projects\ProjectSignatureSlotService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Support\Facades\DB;

trait BuildsProjectSignatureSlots
{
    protected function setUpProjectSignatures(): void
    {
        $this->seed([AuthorizationSeeder::class, ProjectStatusSeeder::class]);
        Department::query()->firstOrCreate(['name' => 'Signature fixtures']);
        ProjectCategory::query()->firstOrCreate(['name' => 'Signature fixtures']);
        AcademicYear::query()->firstOrCreate(['year' => 2570]);
    }

    protected function signatureUser(string $role = 'teacher', array $overrides = []): User
    {
        return User::factory()->create(array_replace([
            'role_id' => Role::query()->where('code', $role)->firstOrFail()->id,
            'department_id' => Department::query()->where('name', 'Signature fixtures')->firstOrFail()->id,
            'is_active' => true,
        ], $overrides));
    }

    protected function signatureProject(User $owner, array $overrides = [], bool $initialize = true): Project
    {
        return DB::transaction(function () use ($owner, $overrides, $initialize): Project {
            $project = Project::query()->create(array_replace([
                'name' => 'Project signature fixture',
                'objective' => 'Review project signature assignment.',
                'user_id' => $owner->id,
                'department_id' => $owner->department_id,
                'project_category_id' => ProjectCategory::query()->where('name', 'Signature fixtures')->firstOrFail()->id,
                'academic_year_id' => AcademicYear::query()->where('year', 2570)->firstOrFail()->id,
                'project_status_id' => ProjectStatus::query()->where('code', 'draft')->firstOrFail()->id,
            ], $overrides));

            if ($initialize) {
                app(ProjectSignatureSlotService::class)->initializeInTransaction($project, $owner, 'project_create');
            }

            // Compare persisted snapshots, including database defaults, in
            // dry-run and rollback assertions.
            return $project->refresh();
        });
    }

    protected function signaturePayload(?int $userId, int $revision = 0): array
    {
        return ['assigned_user_id' => $userId, 'assignment_revision' => $revision];
    }
}
