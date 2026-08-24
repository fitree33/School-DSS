<?php

namespace Tests\Feature\Api\V2;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\EvaluationStatus;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectExecutionStatus;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\SchoolBudget;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private Department $academicDepartment;

    private Department $studentDepartment;

    private ProjectCategory $category;

    private AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProjectStatusSeeder::class);
        $this->seed(AuthorizationSeeder::class);

        $this->academicDepartment = Department::create(['name' => 'Academic Affairs']);
        $this->studentDepartment = Department::create(['name' => 'Student Affairs']);
        $this->category = ProjectCategory::create(['name' => 'School Development']);
        $this->academicYear = AcademicYear::create(['year' => 2570, 'is_active' => true]);
    }

    public function test_dashboard_requires_an_active_authenticated_user(): void
    {
        $this->getJson('/api/v2/dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $inactive = $this->user('teacher', $this->academicDepartment, false);

        $this->actingAs($inactive)
            ->getJson('/api/v2/dashboard')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'บัญชีผู้ใช้นี้ถูกระงับการใช้งาน',
                'code' => 'account_inactive',
            ]);
    }

    public function test_dashboard_defaults_to_highest_active_year_and_aggregates_school_wide_without_project_details(): void
    {
        $older = FiscalYear::create(['year' => 2569]);
        $selected = FiscalYear::create(['year' => 2571, 'is_active' => true]);
        FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $latestInactive = FiscalYear::create(['year' => 2572]);

        $schoolBudget = SchoolBudget::create([
            'fiscal_year_id' => $selected->id,
            'total_amount' => 1000,
        ]);
        DepartmentBudget::create([
            'school_budget_id' => $schoolBudget->id,
            'department_id' => $this->academicDepartment->id,
            'allocated_amount' => 600,
            'is_allocated' => true,
        ]);
        DepartmentBudget::create([
            'school_budget_id' => $schoolBudget->id,
            'department_id' => $this->studentDepartment->id,
            'allocated_amount' => 400,
            'is_allocated' => true,
        ]);

        $viewer = $this->user('teacher', $this->academicDepartment);
        $otherOwner = $this->user('teacher', $this->studentDepartment);

        $this->project($viewer, $selected, $this->academicDepartment, [
            'name' => 'Visible only as an aggregate A',
            'budget' => 400,
            'actual_spent' => 300,
            'project_execution_status_id' => $this->executionStatus('not_started')->id,
            'evaluation_status_id' => $this->evaluationStatus('pending')->id,
        ]);
        $this->project($otherOwner, $selected, $this->academicDepartment, [
            'name' => 'School-wide hidden project B',
            'budget' => 300,
            'actual_spent' => 450,
            'project_execution_status_id' => $this->executionStatus('in_progress')->id,
            'evaluation_status_id' => $this->evaluationStatus('passed')->id,
        ]);
        $this->project($otherOwner, $selected, $this->studentDepartment, [
            'name' => 'School-wide hidden project C',
            'budget' => 400,
            'actual_spent' => 100,
            'project_execution_status_id' => $this->executionStatus('completed')->id,
            'evaluation_status_id' => $this->evaluationStatus('failed')->id,
        ]);
        $this->project($otherOwner, $older, $this->studentDepartment, [
            'name' => 'Different fiscal year',
            'budget' => 9999,
            'actual_spent' => 9999,
        ]);
        $deleted = $this->project($otherOwner, $selected, $this->studentDepartment, [
            'name' => 'Soft-deleted project must not affect aggregates',
            'budget' => 9999,
            'actual_spent' => 9999,
        ]);
        $deleted->delete();

        $response = $this->actingAs($viewer)
            ->getJson('/api/v2/dashboard')
            ->assertOk()
            ->assertJsonPath('data.fiscal_year.id', $selected->id)
            ->assertJsonPath('data.total_projects', 3)
            ->assertJsonPath('data.can.manage_budgets', false)
            ->assertJsonPath('data.project_execution_status_counts.not_started', 1)
            ->assertJsonPath('data.project_execution_status_counts.in_progress', 1)
            ->assertJsonPath('data.project_execution_status_counts.completed', 1)
            ->assertJsonPath('data.evaluation_status_counts.pending', 1)
            ->assertJsonPath('data.evaluation_status_counts.passed', 1)
            ->assertJsonPath('data.evaluation_status_counts.failed', 1)
            ->assertJsonCount(2, 'data.departments');

        $school = $response->json('data.school_budget');
        $this->assertSame('1000.00', $school['total_budget']);
        $this->assertSame('1000.00', $school['allocated_to_departments']);
        $this->assertSame('0.00', $school['unallocated']);
        $this->assertSame('850.00', $school['total_actual_spent']);
        $this->assertSame('150.00', $school['remaining']);
        $this->assertEquals(85.0, $school['used_percentage']);
        $this->assertEquals(15.0, $school['remaining_percentage']);
        $this->assertFalse($school['overallocated']);
        $this->assertFalse($school['overspent']);

        $departments = collect($response->json('data.departments'))
            ->keyBy('department.id');
        $academic = $departments->get($this->academicDepartment->id);
        $this->assertSame('600.00', $academic['allocated_budget']);
        $this->assertSame('700.00', $academic['planned_project_budget']);
        $this->assertSame('750.00', $academic['actual_spent']);
        $this->assertSame('-150.00', $academic['remaining']);
        $this->assertEquals(125.0, $academic['used_percentage']);
        $this->assertEquals(-25.0, $academic['remaining_percentage']);
        $this->assertTrue($academic['overcommitted']);
        $this->assertTrue($academic['overspent']);

        $student = $departments->get($this->studentDepartment->id);
        $this->assertSame('400.00', $student['allocated_budget']);
        $this->assertSame('400.00', $student['planned_project_budget']);
        $this->assertSame('100.00', $student['actual_spent']);
        $this->assertSame('300.00', $student['remaining']);
        $this->assertEquals(25.0, $student['used_percentage']);
        $this->assertEquals(75.0, $student['remaining_percentage']);
        $this->assertFalse($student['overcommitted']);
        $this->assertFalse($student['overspent']);

        $this->assertArrayNotHasKey('projects', $response->json('data'));
        $this->assertStringNotContainsString('School-wide hidden project', $response->getContent());

        FiscalYear::query()->update(['is_active' => false]);

        $this->actingAs($viewer)
            ->getJson('/api/v2/dashboard')
            ->assertOk()
            ->assertJsonPath('data.fiscal_year.id', $latestInactive->id)
            ->assertJsonPath('data.total_projects', 0);
    }

    public function test_explicit_fiscal_year_filter_is_exact_and_invalid_ids_are_rejected(): void
    {
        $active = FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $requested = FiscalYear::create(['year' => 2571]);
        $owner = $this->user('director');

        SchoolBudget::create([
            'fiscal_year_id' => $requested->id,
            'total_amount' => 500,
        ]);
        $this->project($owner, $active, $this->academicDepartment, [
            'budget' => 1000,
            'actual_spent' => 1000,
        ]);
        $this->project($owner, $requested, $this->academicDepartment, [
            'budget' => 100,
            'actual_spent' => 25,
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v2/dashboard?fiscal_year_id={$requested->id}")
            ->assertOk()
            ->assertJsonPath('data.fiscal_year.id', $requested->id)
            ->assertJsonPath('data.total_projects', 1)
            ->assertJsonPath('data.school_budget.total_budget', '500.00')
            ->assertJsonPath('data.school_budget.total_actual_spent', '25.00')
            ->assertJsonPath('data.can.manage_budgets', true);

        $this->actingAs($owner)
            ->getJson('/api/v2/dashboard?fiscal_year_id=999999')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['fiscal_year_id']]);
    }

    public function test_zero_denominators_distinguish_zero_ratios_from_undefined_ratios_and_show_data_drift(): void
    {
        $viewer = $this->user('teacher', $this->academicDepartment);
        $zeroYear = FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $zeroBudget = SchoolBudget::create([
            'fiscal_year_id' => $zeroYear->id,
            'total_amount' => 0,
        ]);
        DepartmentBudget::create([
            'school_budget_id' => $zeroBudget->id,
            'department_id' => $this->academicDepartment->id,
            'allocated_amount' => 0,
            'is_allocated' => true,
        ]);

        $zeroResponse = $this->actingAs($viewer)
            ->getJson('/api/v2/dashboard')
            ->assertOk();

        $this->assertEquals(0.0, $zeroResponse->json('data.school_budget.used_percentage'));
        $this->assertEquals(0.0, $zeroResponse->json('data.school_budget.remaining_percentage'));
        $this->assertEquals(0.0, $zeroResponse->json('data.departments.0.used_percentage'));
        $this->assertEquals(0.0, $zeroResponse->json('data.departments.0.remaining_percentage'));

        $this->project($viewer, $zeroYear, $this->academicDepartment, [
            'budget' => 0,
            'actual_spent' => 25,
        ]);

        $nonzeroOverZeroResponse = $this->actingAs($viewer)
            ->getJson('/api/v2/dashboard')
            ->assertOk()
            ->assertJsonPath('data.school_budget.overspent', true);

        $this->assertNull($nonzeroOverZeroResponse->json('data.school_budget.used_percentage'));
        $this->assertNull($nonzeroOverZeroResponse->json('data.school_budget.remaining_percentage'));
        $this->assertNull($nonzeroOverZeroResponse->json('data.departments.0.used_percentage'));
        $this->assertNull($nonzeroOverZeroResponse->json('data.departments.0.remaining_percentage'));

        $driftYear = FiscalYear::create(['year' => 2571]);
        $driftBudget = SchoolBudget::create([
            'fiscal_year_id' => $driftYear->id,
            'total_amount' => 100,
        ]);
        DepartmentBudget::create([
            'school_budget_id' => $driftBudget->id,
            'department_id' => $this->academicDepartment->id,
            'allocated_amount' => 120,
            'is_allocated' => true,
        ]);
        $this->project($viewer, $driftYear, $this->academicDepartment, [
            'budget' => 125,
            'actual_spent' => 150,
        ]);

        $driftResponse = $this->actingAs($viewer)
            ->getJson("/api/v2/dashboard?fiscal_year_id={$driftYear->id}")
            ->assertOk();

        $school = $driftResponse->json('data.school_budget');
        $this->assertSame('-20.00', $school['unallocated']);
        $this->assertSame('-50.00', $school['remaining']);
        $this->assertEquals(150.0, $school['used_percentage']);
        $this->assertEquals(-50.0, $school['remaining_percentage']);
        $this->assertTrue($school['overallocated']);
        $this->assertTrue($school['overspent']);

        $department = collect($driftResponse->json('data.departments'))
            ->firstWhere('department.id', $this->academicDepartment->id);
        $this->assertSame('125.00', $department['planned_project_budget']);
        $this->assertSame('150.00', $department['actual_spent']);
        $this->assertSame('-30.00', $department['remaining']);
        $this->assertEquals(125.0, $department['used_percentage']);
        $this->assertEquals(-25.0, $department['remaining_percentage']);
        $this->assertTrue($department['overcommitted']);
        $this->assertTrue($department['overspent']);
    }

    public function test_dashboard_returns_zero_metrics_when_no_fiscal_year_exists(): void
    {
        $response = $this->actingAs($this->user('teacher', $this->academicDepartment))
            ->getJson('/api/v2/dashboard')
            ->assertOk()
            ->assertJsonPath('data.fiscal_year', null)
            ->assertJsonPath('data.fiscal_years', [])
            ->assertJsonPath('data.total_projects', 0)
            ->assertJsonPath('data.school_budget.total_budget', '0.00')
            ->assertJsonPath('data.school_budget.total_actual_spent', '0.00')
            ->assertJsonPath('data.project_execution_status_counts.not_started', 0)
            ->assertJsonPath('data.project_execution_status_counts.in_progress', 0)
            ->assertJsonPath('data.project_execution_status_counts.completed', 0);

        $this->assertEquals(0.0, $response->json('data.school_budget.used_percentage'));
        $this->assertEquals(0.0, $response->json('data.school_budget.remaining_percentage'));
    }

    private function user(
        string $roleCode,
        ?Department $department = null,
        bool $isActive = true,
    ): User {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'department_id' => $department?->id,
            'is_active' => $isActive,
        ]);
    }

    private function project(
        User $owner,
        FiscalYear $fiscalYear,
        Department $department,
        array $overrides = [],
    ): Project {
        return Project::create(array_merge([
            'name' => 'Dashboard Project',
            'objective' => 'Verify dashboard aggregation.',
            'budget' => 0,
            'actual_spent' => 0,
            'user_id' => $owner->id,
            'department_id' => $department->id,
            'project_category_id' => $this->category->id,
            'academic_year_id' => $this->academicYear->id,
            'fiscal_year_id' => $fiscalYear->id,
            'project_status_id' => ProjectStatus::query()->where('code', 'draft')->value('id'),
            'project_execution_status_id' => $this->executionStatus('not_started')->id,
            'evaluation_status_id' => $this->evaluationStatus('pending')->id,
        ], $overrides));
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
