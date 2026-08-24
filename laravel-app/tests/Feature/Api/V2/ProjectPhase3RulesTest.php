<?php

namespace Tests\Feature\Api\V2;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\EvaluationStatus;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectExecutionStatus;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectPhase3RulesTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private ProjectCategory $category;

    private AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProjectStatusSeeder::class);
        $this->seed(AuthorizationSeeder::class);
        $this->department = Department::create(['name' => 'Academic Affairs']);
        $this->category = ProjectCategory::create(['name' => 'School Development']);
        $this->academicYear = AcademicYear::create(['year' => 2570, 'is_active' => true]);
    }

    public function test_project_resource_exposes_remaining_and_percentage_without_hiding_overspend(): void
    {
        $owner = $this->user('teacher');
        $fiscalYear = FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $overspent = $this->project($owner, $fiscalYear, [
            'budget' => 100,
            'actual_spent' => 125,
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v2/projects/{$overspent->id}")
            ->assertOk()
            ->assertJsonPath('data.budget_metrics.budget', '100.00')
            ->assertJsonPath('data.budget_metrics.actual_spent', '125.00')
            ->assertJsonPath('data.budget_metrics.remaining', '-25.00')
            ->assertJsonPath('data.budget_metrics.used_percentage', 125);

        $zeroBudgetWithSpend = $this->project($owner, $fiscalYear, [
            'name' => 'Zero budget with spend',
            'budget' => 0,
            'actual_spent' => 10,
        ]);
        $zeroBudgetWithoutSpend = $this->project($owner, $fiscalYear, [
            'name' => 'Zero budget without spend',
            'budget' => 0,
            'actual_spent' => 0,
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v2/projects/{$zeroBudgetWithSpend->id}")
            ->assertOk()
            ->assertJsonPath('data.budget_metrics.remaining', '-10.00')
            ->assertJsonPath('data.budget_metrics.used_percentage', null);

        $this->actingAs($owner)
            ->getJson("/api/v2/projects/{$zeroBudgetWithoutSpend->id}")
            ->assertOk()
            ->assertJsonPath('data.budget_metrics.remaining', '0.00')
            ->assertJsonPath('data.budget_metrics.used_percentage', 0);
    }

    public function test_execution_status_only_allows_normal_forward_transitions_and_completed_cannot_reverse(): void
    {
        $owner = $this->user('teacher');
        $fiscalYear = FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $project = $this->project($owner, $fiscalYear);

        $this->actingAs($owner)
            ->putJson("/api/v2/projects/{$project->id}", [
                'execution_status' => 'completed',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['execution_status']]);

        $this->assertSame('not_started', $project->fresh()->executionStatus->code);

        $this->actingAs($owner)
            ->putJson("/api/v2/projects/{$project->id}", [
                'execution_status' => 'in_progress',
                'execution_status_comment' => 'Started normally.',
            ])
            ->assertOk()
            ->assertJsonPath('data.execution_status.code', 'in_progress');

        $this->actingAs($owner)
            ->putJson("/api/v2/projects/{$project->id}", [
                'execution_status' => 'completed',
                'execution_status_comment' => 'Finished normally.',
            ])
            ->assertOk()
            ->assertJsonPath('data.execution_status.code', 'completed');

        $this->actingAs($owner)
            ->putJson("/api/v2/projects/{$project->id}", [
                'execution_status' => 'in_progress',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['execution_status']]);

        $this->assertSame('completed', $project->fresh()->executionStatus->code);
        $this->assertDatabaseHas('project_execution_status_histories', [
            'project_id' => $project->id,
            'from_status_id' => $this->executionStatus('not_started')->id,
            'to_status_id' => $this->executionStatus('in_progress')->id,
            'comment' => 'Started normally.',
        ]);
        $this->assertDatabaseHas('project_execution_status_histories', [
            'project_id' => $project->id,
            'from_status_id' => $this->executionStatus('in_progress')->id,
            'to_status_id' => $this->executionStatus('completed')->id,
            'comment' => 'Finished normally.',
        ]);
        $this->assertDatabaseCount('project_execution_status_histories', 2);
    }

    public function test_locked_fiscal_year_rejects_v2_project_create_update_execution_and_delete(): void
    {
        $lockedYear = FiscalYear::create([
            'year' => 2570,
            'is_active' => true,
            'is_locked' => true,
        ]);
        $director = $this->user('director');

        $this->actingAs($director)
            ->postJson('/api/v2/projects', $this->validPayload($lockedYear))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['fiscal_year_id']]);

        $project = $this->project($director, $lockedYear);

        $this->actingAs($director)
            ->putJson("/api/v2/projects/{$project->id}", ['name' => 'Blocked rename'])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->actingAs($director)
            ->putJson("/api/v2/projects/{$project->id}", [
                'execution_status' => 'in_progress',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->actingAs($director)
            ->deleteJson("/api/v2/projects/{$project->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertSame('Phase 3 Project', $project->fresh()->name);
        $this->assertSame('not_started', $project->fresh()->executionStatus->code);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'deleted_at' => null,
        ]);
    }

    public function test_legacy_completion_cannot_jump_not_started_to_completed_but_accepts_in_progress(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $owner = $this->user('teacher');
        $project = $this->project($owner, $fiscalYear, [
            'project_status_id' => ProjectStatus::query()->where('code', 'approved')->value('id'),
            'project_execution_status_id' => $this->executionStatus('not_started')->id,
        ]);
        $payload = [
            'success_percent' => 90,
            'quality_score' => 4.5,
            'actual_spent' => 900,
            'summary' => 'Completed according to plan.',
        ];

        $this->actingAs($owner)
            ->post(route('projects.workflow.complete', $project), $payload)
            ->assertForbidden();

        $this->assertSame('approved', $project->fresh()->status->code);
        $this->assertSame('not_started', $project->fresh()->executionStatus->code);
        $this->assertDatabaseMissing('project_completion_reports', ['project_id' => $project->id]);

        $project->update([
            'project_execution_status_id' => $this->executionStatus('in_progress')->id,
        ]);

        $this->actingAs($owner)
            ->from(route('projects.show', $project))
            ->post(route('projects.workflow.complete', $project), $payload)
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('completed', $project->fresh()->status->code);
        $this->assertSame('completed', $project->fresh()->executionStatus->code);
        $this->assertDatabaseHas('project_completion_reports', ['project_id' => $project->id]);
    }

    public function test_locked_fiscal_year_rejects_legacy_workflow_mutation(): void
    {
        $lockedYear = FiscalYear::create([
            'year' => 2570,
            'is_active' => true,
            'is_locked' => true,
        ]);
        $owner = $this->user('teacher');
        $project = $this->project($owner, $lockedYear);

        $this->actingAs($owner)
            ->post(route('projects.workflow.submit', $project))
            ->assertForbidden();

        $this->assertSame('draft', $project->fresh()->status->code);
        $this->assertDatabaseCount('project_status_histories', 0);
    }

    public function test_phase_three_does_not_introduce_activity_or_subactivity_management(): void
    {
        $this->assertFalse(Schema::hasTable('activities'));
        $this->assertFalse(Schema::hasTable('sub_activities'));
        $this->assertFalse(Schema::hasTable('project_activities'));

        $this->actingAs($this->user('director'))
            ->getJson('/api/v2/activities')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        $this->getJson('/api/v2/sub-activities')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    private function user(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
    }

    private function project(
        User $owner,
        FiscalYear $fiscalYear,
        array $overrides = [],
    ): Project {
        return Project::create(array_merge([
            'name' => 'Phase 3 Project',
            'objective' => 'Verify Phase 3 project invariants.',
            'budget' => 1000,
            'actual_spent' => 0,
            'user_id' => $owner->id,
            'department_id' => $this->department->id,
            'project_category_id' => $this->category->id,
            'academic_year_id' => $this->academicYear->id,
            'fiscal_year_id' => $fiscalYear->id,
            'project_status_id' => ProjectStatus::query()->where('code', 'draft')->value('id'),
            'project_execution_status_id' => $this->executionStatus('not_started')->id,
            'evaluation_status_id' => $this->evaluationStatus('pending')->id,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(FiscalYear $fiscalYear): array
    {
        return [
            'name' => 'Locked Fiscal Year Project',
            'objective' => 'This write must be rejected.',
            'budget' => 1000,
            'department_id' => $this->department->id,
            'project_category_id' => $this->category->id,
            'academic_year_id' => $this->academicYear->id,
            'fiscal_year_id' => $fiscalYear->id,
            'start_date' => '2026-10-01',
            'end_date' => '2027-03-31',
        ];
    }

    private function executionStatus(string $code): ProjectExecutionStatus
    {
        return ProjectExecutionStatus::query()->where('code', $code)->firstOrFail();
    }

    private function evaluationStatus(string $code): EvaluationStatus
    {
        return EvaluationStatus::query()->where('code', $code)->firstOrFail();
    }
}
