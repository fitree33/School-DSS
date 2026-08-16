<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\EvaluationStatus;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectExecutionStatus;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyV2CoexistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_project_create_sets_v2_defaults_and_execution_history(): void
    {
        $this->seed([ProjectStatusSeeder::class, AuthorizationSeeder::class]);
        [$teacher, $department, $category, $academicYear] = $this->references();

        $this->actingAs($teacher)
            ->post(route('projects.store'), [
                'name' => 'Legacy Form Project',
                'objective' => 'Confirm V2 status compatibility.',
                'budget' => 2500,
                'responsible_person' => '',
                'department_id' => $department->id,
                'project_category_id' => $category->id,
                'academic_year_id' => $academicYear->id,
            ])
            ->assertRedirect();

        $project = Project::query()->where('name', 'Legacy Form Project')->firstOrFail();

        $this->assertSame('not_started', $project->executionStatus->code);
        $this->assertSame('pending', $project->evaluationStatus->code);
        $this->assertDatabaseHas('project_execution_status_histories', [
            'project_id' => $project->id,
            'from_status_id' => null,
            'to_status_id' => $project->project_execution_status_id,
            'changed_by' => $teacher->id,
        ]);
    }

    public function test_legacy_completion_syncs_v2_execution_status_and_history(): void
    {
        $this->seed([ProjectStatusSeeder::class, AuthorizationSeeder::class]);
        [$teacher, $department, $category, $academicYear] = $this->references();
        $notStarted = ProjectExecutionStatus::query()->where('code', 'not_started')->firstOrFail();
        $pending = EvaluationStatus::query()->where('code', 'pending')->firstOrFail();
        $project = Project::create([
            'name' => 'Legacy Completion Project',
            'objective' => 'Confirm V2 completion compatibility.',
            'budget' => 2500,
            'user_id' => $teacher->id,
            'department_id' => $department->id,
            'project_category_id' => $category->id,
            'academic_year_id' => $academicYear->id,
            'project_status_id' => ProjectStatus::query()->where('code', 'approved')->value('id'),
            'project_execution_status_id' => $notStarted->id,
            'evaluation_status_id' => $pending->id,
        ]);

        $this->actingAs($teacher)
            ->post(route('projects.workflow.complete', $project), [
                'success_percent' => 90,
                'quality_score' => 4.25,
                'actual_spent' => 2250,
                'summary' => 'The project was completed.',
            ])
            ->assertRedirect();

        $project->refresh();

        $this->assertSame('completed', $project->status->code);
        $this->assertSame('completed', $project->executionStatus->code);
        $this->assertDatabaseHas('project_execution_status_histories', [
            'project_id' => $project->id,
            'from_status_id' => $notStarted->id,
            'to_status_id' => $project->project_execution_status_id,
            'changed_by' => $teacher->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'project.execution_status_changed',
            'auditable_id' => $project->id,
            'user_id' => $teacher->id,
        ]);
    }

    /**
     * @return array{User, Department, ProjectCategory, AcademicYear}
     */
    private function references(): array
    {
        $department = Department::create(['name' => 'Legacy Department']);
        $category = ProjectCategory::create(['name' => 'Legacy Category']);
        $academicYear = AcademicYear::create(['year' => 2570]);
        $teacher = User::factory()->create([
            'role_id' => Role::query()->where('code', 'teacher')->value('id'),
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        return [$teacher, $department, $category, $academicYear];
    }
}
