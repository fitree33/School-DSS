<?php

namespace Tests\Feature\Api\V2;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\EvaluationStatus;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\ProjectCategory;
use App\Models\ProjectExecutionStatus;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\SchoolPlan;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Department $otherDepartment;

    private ProjectCategory $category;

    private AcademicYear $academicYear;

    private FiscalYear $fiscalYear;

    private FiscalYear $otherFiscalYear;

    private SchoolPlan $schoolPlan;

    private ProjectStatus $draftStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProjectStatusSeeder::class);
        $this->seed(AuthorizationSeeder::class);
        $this->department = Department::create(['name' => 'Academic Affairs']);
        $this->otherDepartment = Department::create(['name' => 'Student Affairs']);
        $this->category = ProjectCategory::create(['name' => 'School Development']);
        $this->academicYear = AcademicYear::create(['year' => 2570, 'is_active' => true]);
        $this->fiscalYear = FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $this->otherFiscalYear = FiscalYear::create(['year' => 2571]);
        $this->schoolPlan = SchoolPlan::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'code' => 'PLAN-01',
            'name' => 'Academic Quality Plan',
            'is_active' => true,
        ]);
        $this->draftStatus = ProjectStatus::query()->where('code', 'draft')->firstOrFail();
    }

    public function test_index_is_visibility_scoped_searchable_filterable_and_paginated(): void
    {
        $viewer = $this->user('teacher', $this->department);
        $otherOwner = $this->user('teacher', $this->otherDepartment);

        $visible = $this->project($viewer, [
            'name' => 'Visible Reading Project',
            'project_code' => 'READ-01',
        ]);
        $shared = $this->project($otherOwner, [
            'name' => 'Shared Science Project',
            'department_id' => $this->otherDepartment->id,
            'fiscal_year_id' => $this->otherFiscalYear->id,
            'project_execution_status_id' => $this->executionStatus('completed')->id,
            'evaluation_status_id' => $this->evaluationStatus('passed')->id,
        ]);
        $hidden = $this->project($otherOwner, [
            'name' => 'Hidden Reading Project',
            'department_id' => $this->otherDepartment->id,
        ]);
        ProjectAccess::create([
            'project_id' => $shared->id,
            'user_id' => $viewer->id,
            'can_view' => true,
            'granted_by' => $otherOwner->id,
        ]);

        $pageResponse = $this->actingAs($viewer)
            ->getJson('/api/v2/projects?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'budget',
                    'approval_status',
                    'execution_status',
                    'evaluation_status',
                    'abilities' => ['update', 'delete', 'evaluate'],
                ]],
                'links',
                'meta',
            ]);

        $this->assertStringContainsString('per_page=1', $pageResponse->json('links.next'));

        $searchResponse = $this->actingAs($viewer)
            ->getJson('/api/v2/projects?q=Reading')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $visible->id);

        $this->assertNotContains($hidden->id, $searchResponse->json('data.*.id'));

        $this->actingAs($viewer)
            ->getJson('/api/v2/projects?'.http_build_query([
                'fiscal_year_id' => $this->otherFiscalYear->id,
                'department_id' => $this->otherDepartment->id,
                'execution_status' => 'completed',
                'evaluation_status' => 'passed',
            ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $shared->id);
    }

    public function test_index_filters_are_strictly_validated_with_the_v2_error_contract(): void
    {
        $this->actingAs($this->user('teacher', $this->department))
            ->getJson('/api/v2/projects?execution_status=unknown&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure([
                'message',
                'code',
                'errors' => ['execution_status', 'per_page'],
            ]);
    }

    public function test_search_treats_wildcards_literally_and_preserves_zero(): void
    {
        $viewer = $this->user('teacher', $this->department);
        $percentProject = $this->project($viewer, ['name' => 'Budget Half% Plan']);
        $this->project($viewer, ['name' => 'Budget HalfX Plan']);
        $zeroProject = $this->project($viewer, ['name' => 'Project 0 Baseline']);

        $percentResponse = $this->actingAs($viewer)
            ->getJson('/api/v2/projects?'.http_build_query(['q' => 'Half%']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertSame([$percentProject->id], $percentResponse->json('data.*.id'));

        $zeroResponse = $this->actingAs($viewer)
            ->getJson('/api/v2/projects?'.http_build_query(['q' => '0']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertSame([$zeroProject->id], $zeroResponse->json('data.*.id'));
    }

    public function test_create_sets_server_owned_defaults_and_records_access_histories_and_audit(): void
    {
        $teacher = $this->user('teacher', $this->department);

        $response = $this->actingAs($teacher)
            ->postJson('/api/v2/projects', $this->validPayload([
                'name' => 'API Created Project',
                'project_code' => ' api-001 ',
                'school_plan_id' => $this->schoolPlan->id,
            ]))
            ->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('data.name', 'API Created Project')
            ->assertJsonPath('data.project_code', 'API-001')
            ->assertJsonPath('data.owner.id', $teacher->id)
            ->assertJsonPath('data.approval_status.code', 'draft')
            ->assertJsonPath('data.execution_status.code', 'not_started')
            ->assertJsonPath('data.evaluation_status.code', 'pending')
            ->assertJsonPath('data.actual_spent', '0.00');

        $projectId = $response->json('data.id');

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'user_id' => $teacher->id,
            'department_id' => $this->department->id,
            'project_status_id' => $this->draftStatus->id,
            'project_execution_status_id' => $this->executionStatus('not_started')->id,
            'evaluation_status_id' => $this->evaluationStatus('pending')->id,
        ]);
        $this->assertDatabaseHas('project_access', [
            'project_id' => $projectId,
            'user_id' => $teacher->id,
            'can_view' => true,
            'can_edit' => true,
            'can_delete' => true,
        ]);
        $this->assertDatabaseHas('project_status_histories', [
            'project_id' => $projectId,
            'from_status_id' => null,
            'to_status_id' => $this->draftStatus->id,
            'changed_by' => $teacher->id,
        ]);
        $this->assertDatabaseHas('project_execution_status_histories', [
            'project_id' => $projectId,
            'from_status_id' => null,
            'to_status_id' => $this->executionStatus('not_started')->id,
            'changed_by' => $teacher->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'project.created',
            'auditable_type' => Project::class,
            'auditable_id' => $projectId,
            'user_id' => $teacher->id,
        ]);

        $this->actingAs($teacher)
            ->postJson('/api/v2/projects', $this->validPayload([
                'name' => 'Duplicate Code Project',
                'project_code' => 'api-001',
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['project_code']]);
    }

    public function test_create_rejects_unsupported_decimal_scale(): void
    {
        $this->actingAs($this->user('teacher', $this->department))
            ->postJson('/api/v2/projects', $this->validPayload(['budget' => 1.999]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['budget']]);
    }

    public function test_project_code_uniqueness_is_case_and_whitespace_insensitive(): void
    {
        $teacher = $this->user('teacher', $this->department);
        $this->project($teacher, ['project_code' => 'legacy-01 ']);

        $this->actingAs($teacher)
            ->postJson('/api/v2/projects', $this->validPayload([
                'name' => 'Conflicting Legacy Code',
                'project_code' => 'LEGACY-01',
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['project_code']]);
    }

    public function test_update_tracks_execution_status_and_enforces_effective_date_range(): void
    {
        $teacher = $this->user('teacher', $this->department);
        $project = $this->project($teacher, [
            'start_date' => '2026-10-10',
            'end_date' => '2026-10-20',
        ]);

        $this->actingAs($teacher)
            ->putJson("/api/v2/projects/{$project->id}", [
                'end_date' => '2026-10-01',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['end_date']]);

        $this->actingAs($teacher)
            ->putJson("/api/v2/projects/{$project->id}", [
                'actual_spent' => 1250,
                'execution_status' => 'in_progress',
                'execution_status_comment' => 'Work has started.',
            ])
            ->assertOk()
            ->assertJsonPath('data.actual_spent', '1250.00')
            ->assertJsonPath('data.execution_status.code', 'in_progress');

        $this->assertDatabaseHas('project_execution_status_histories', [
            'project_id' => $project->id,
            'from_status_id' => $this->executionStatus('not_started')->id,
            'to_status_id' => $this->executionStatus('in_progress')->id,
            'changed_by' => $teacher->id,
            'comment' => 'Work has started.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'project.updated',
            'auditable_id' => $project->id,
            'user_id' => $teacher->id,
        ]);
    }

    public function test_evaluation_status_and_other_protected_fields_are_rejected(): void
    {
        $teacher = $this->user('teacher', $this->department);
        $project = $this->project($teacher);

        $this->actingAs($teacher)
            ->putJson("/api/v2/projects/{$project->id}", ['evaluation_status' => 'passed'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['evaluation_status']]);

        $this->actingAs($teacher)
            ->putJson("/api/v2/projects/{$project->id}", [
                'ai_summary' => 'Do not allow generic AI writes.',
                'project_status_id' => $this->draftStatus->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['ai_summary', 'project_status_id']]);

        $director = $this->user('director');

        $this->actingAs($director)
            ->putJson("/api/v2/projects/{$project->id}", ['evaluation_status' => 'passed'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['evaluation_status']]);

        $this->assertSame(
            $this->evaluationStatus('pending')->id,
            $project->fresh()->evaluation_status_id
        );
    }

    public function test_school_plan_must_match_fiscal_year_and_fiscal_change_clears_the_plan(): void
    {
        $teacher = $this->user('teacher', $this->department);
        $otherPlan = SchoolPlan::create([
            'fiscal_year_id' => $this->otherFiscalYear->id,
            'code' => 'PLAN-02',
            'name' => 'Next Fiscal Plan',
        ]);

        $this->actingAs($teacher)
            ->postJson('/api/v2/projects', $this->validPayload([
                'school_plan_id' => $otherPlan->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['school_plan_id']]);

        $project = $this->project($teacher, ['school_plan_id' => $this->schoolPlan->id]);

        $this->actingAs($teacher)
            ->putJson("/api/v2/projects/{$project->id}", [
                'fiscal_year_id' => $this->otherFiscalYear->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.fiscal_year.id', $this->otherFiscalYear->id)
            ->assertJsonPath('data.school_plan', null);

        $this->assertNull($project->fresh()->school_plan_id);

        $this->actingAs($teacher)
            ->putJson("/api/v2/projects/{$project->id}", [
                'school_plan_id' => $this->schoolPlan->id,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['school_plan_id']]);
    }

    public function test_execution_comment_requires_a_status_and_legacy_nullable_v2_relations_are_safe(): void
    {
        $teacher = $this->user('teacher', $this->department);
        $project = $this->project($teacher, [
            'fiscal_year_id' => null,
            'school_plan_id' => null,
            'project_execution_status_id' => null,
            'evaluation_status_id' => null,
        ]);

        $this->actingAs($teacher)
            ->putJson("/api/v2/projects/{$project->id}", [
                'execution_status_comment' => 'This must not be discarded.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['execution_status_comment']]);

        $this->actingAs($teacher)
            ->getJson("/api/v2/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.fiscal_year', null)
            ->assertJsonPath('data.execution_status', null)
            ->assertJsonPath('data.evaluation_status', null);
    }

    public function test_show_and_delete_use_policy_scope_and_deletion_is_audited(): void
    {
        $owner = $this->user('teacher', $this->department);
        $viewer = $this->user('teacher', $this->department);
        $project = $this->project($owner);

        $this->actingAs($viewer)
            ->getJson("/api/v2/projects/{$project->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->actingAs($owner)
            ->deleteJson("/api/v2/projects/{$project->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'project.deleted',
            'auditable_id' => $project->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_owner_access_grant_does_not_bypass_workflow_edit_and_delete_lock(): void
    {
        $owner = $this->user('teacher', $this->department);
        $projectId = $this->actingAs($owner)
            ->postJson('/api/v2/projects', $this->validPayload())
            ->assertCreated()
            ->json('data.id');

        $this->post("/projects/{$projectId}/submit")
            ->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'project_status_id' => ProjectStatus::query()
                ->where('code', 'pending_deputy')
                ->value('id'),
        ]);

        $this->putJson("/api/v2/projects/{$projectId}", ['name' => 'Blocked change'])
            ->assertForbidden();
        $this->deleteJson("/api/v2/projects/{$projectId}")
            ->assertForbidden();

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'deleted_at' => null,
        ]);
    }

    public function test_v2_model_binding_errors_do_not_expose_internal_model_details(): void
    {
        $this->actingAs($this->user('director'))
            ->getJson('/api/v2/projects/999999')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'The requested resource was not found.',
                'code' => 'not_found',
            ]);
    }

    public function test_project_options_supply_all_react_form_lookups(): void
    {
        $this->actingAs($this->user('teacher', $this->department))
            ->getJson('/api/v2/project-options')
            ->assertOk()
            ->assertJsonPath('data.fiscal_years.0.id', $this->otherFiscalYear->id)
            ->assertJsonPath('data.school_plans.0.id', $this->schoolPlan->id)
            ->assertJsonFragment(['code' => 'not_started'])
            ->assertJsonFragment(['code' => 'pending'])
            ->assertJsonStructure(['data' => [
                'fiscal_years',
                'academic_years',
                'departments',
                'project_categories',
                'school_plans',
                'execution_statuses',
                'evaluation_statuses',
                'budget_sources',
            ]]);
    }

    private function user(string $roleCode, ?Department $department = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'department_id' => $department?->id,
            'is_active' => true,
        ]);
    }

    private function project(User $owner, array $overrides = []): Project
    {
        return Project::create(array_merge([
            'name' => 'Default Project',
            'objective' => 'Improve school outcomes.',
            'budget' => 5000,
            'actual_spent' => 0,
            'user_id' => $owner->id,
            'department_id' => $owner->department_id ?? $this->department->id,
            'project_category_id' => $this->category->id,
            'academic_year_id' => $this->academicYear->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'project_status_id' => $this->draftStatus->id,
            'project_execution_status_id' => $this->executionStatus('not_started')->id,
            'evaluation_status_id' => $this->evaluationStatus('pending')->id,
        ], $overrides));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Valid Project',
            'objective' => 'Improve school outcomes.',
            'budget' => 5000,
            'department_id' => $this->department->id,
            'project_category_id' => $this->category->id,
            'academic_year_id' => $this->academicYear->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-10-01',
            'end_date' => '2027-03-31',
        ], $overrides);
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
