<?php

namespace Tests\Feature\Api\V2;

use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SchoolBudget;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private Department $academicDepartment;

    private Department $studentDepartment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationSeeder::class);
        $this->academicDepartment = Department::create(['name' => 'Academic Affairs']);
        $this->studentDepartment = Department::create(['name' => 'Student Affairs']);
    }

    public function test_budget_manage_permission_is_seeded_for_director_only_and_custom_grants_survive_reseeding(): void
    {
        $permission = Permission::query()->where('code', 'budgets.manage')->firstOrFail();

        $this->assertTrue($this->role('director')->permissions()->whereKey($permission->id)->exists());
        $this->assertFalse($this->role('deputy_director')->permissions()->whereKey($permission->id)->exists());
        $this->assertFalse($this->role('department_head')->permissions()->whereKey($permission->id)->exists());
        $this->assertFalse($this->role('teacher')->permissions()->whereKey($permission->id)->exists());

        $teacherRole = $this->role('teacher');
        $teacherRole->permissions()->attach($permission->id);

        $this->seed(AuthorizationSeeder::class);

        $this->assertTrue($teacherRole->permissions()->whereKey($permission->id)->exists());
    }

    public function test_budget_management_endpoints_require_budget_manage_permission(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $teacher = $this->user('teacher');

        $this->actingAs($teacher)
            ->getJson('/api/v2/budget-management')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->actingAs($teacher)
            ->putJson("/api/v2/fiscal-years/{$fiscalYear->id}/school-budget", [
                'total_amount' => 1000,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->actingAs($teacher)
            ->putJson(
                "/api/v2/fiscal-years/{$fiscalYear->id}/department-budgets/{$this->academicDepartment->id}",
                [
                    'allocated_amount' => 500,
                    'is_allocated' => true,
                ]
            )
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_manager_can_create_budgets_but_hard_writes_cannot_overallocate_or_reduce_below_allocations(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $director = $this->user('director');

        $this->actingAs($director)
            ->putJson("/api/v2/fiscal-years/{$fiscalYear->id}/school-budget", [
                'total_amount' => 1000,
                'notes' => 'Approved school budget',
            ])
            ->assertOk()
            ->assertJsonStructure(['data']);

        $schoolBudget = SchoolBudget::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->firstOrFail();

        $this->assertSame('1000.00', $schoolBudget->total_amount);
        $this->assertSame('Approved school budget', $schoolBudget->notes);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'school_budget.updated',
            'auditable_id' => $schoolBudget->id,
            'user_id' => $director->id,
        ]);

        $this->actingAs($director)
            ->putJson(
                "/api/v2/fiscal-years/{$fiscalYear->id}/department-budgets/{$this->academicDepartment->id}",
                [
                    'allocated_amount' => 600,
                    'is_allocated' => true,
                    'notes' => 'Confirmed allocation',
                ]
            )
            ->assertOk();

        $this->assertDatabaseHas('department_budgets', [
            'school_budget_id' => $schoolBudget->id,
            'department_id' => $this->academicDepartment->id,
            'allocated_amount' => 600,
            'is_allocated' => true,
            'allocated_by' => $director->id,
        ]);
        $this->assertNotNull(
            $schoolBudget->departmentBudgets()
                ->where('department_id', $this->academicDepartment->id)
                ->value('allocated_at')
        );

        $this->actingAs($director)
            ->putJson(
                "/api/v2/fiscal-years/{$fiscalYear->id}/department-budgets/{$this->studentDepartment->id}",
                [
                    'allocated_amount' => 500,
                    'is_allocated' => true,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['allocated_amount']]);

        $this->assertDatabaseMissing('department_budgets', [
            'school_budget_id' => $schoolBudget->id,
            'department_id' => $this->studentDepartment->id,
        ]);

        $this->actingAs($director)
            ->putJson("/api/v2/fiscal-years/{$fiscalYear->id}/school-budget", [
                'total_amount' => 599.99,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['total_amount']]);

        $this->assertSame('1000.00', $schoolBudget->fresh()->total_amount);
    }

    public function test_unallocated_department_may_keep_a_draft_amount_but_is_excluded_from_confirmed_sums(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2570]);
        $director = $this->user('director');

        $this->actingAs($director)
            ->putJson("/api/v2/fiscal-years/{$fiscalYear->id}/school-budget", [
                'total_amount' => 1000,
            ])
            ->assertOk();

        $departmentUrl = "/api/v2/fiscal-years/{$fiscalYear->id}/department-budgets/{$this->academicDepartment->id}";

        $this->actingAs($director)
            ->putJson($departmentUrl, [
                'allocated_amount' => 600,
                'is_allocated' => true,
            ])
            ->assertOk();

        $this->actingAs($director)
            ->putJson($departmentUrl, [
                'allocated_amount' => 600,
                'is_allocated' => false,
            ])
            ->assertOk();

        $schoolBudget = SchoolBudget::query()->where('fiscal_year_id', $fiscalYear->id)->firstOrFail();
        $this->assertDatabaseHas('department_budgets', [
            'school_budget_id' => $schoolBudget->id,
            'department_id' => $this->academicDepartment->id,
            'allocated_amount' => 600,
            'is_allocated' => false,
            'allocated_at' => null,
            'allocated_by' => null,
        ]);

        $this->actingAs($director)
            ->putJson(
                "/api/v2/fiscal-years/{$fiscalYear->id}/department-budgets/{$this->studentDepartment->id}",
                [
                    'allocated_amount' => 1000,
                    'is_allocated' => true,
                ]
            )
            ->assertOk();
    }

    public function test_locked_fiscal_year_rejects_school_and_department_budget_writes(): void
    {
        $fiscalYear = FiscalYear::create([
            'year' => 2570,
            'is_active' => true,
            'is_locked' => true,
        ]);
        SchoolBudget::create([
            'fiscal_year_id' => $fiscalYear->id,
            'total_amount' => 1000,
        ]);
        $director = $this->user('director');

        $this->actingAs($director)
            ->putJson("/api/v2/fiscal-years/{$fiscalYear->id}/school-budget", [
                'total_amount' => 900,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['fiscal_year_id']]);

        $this->actingAs($director)
            ->putJson(
                "/api/v2/fiscal-years/{$fiscalYear->id}/department-budgets/{$this->academicDepartment->id}",
                [
                    'allocated_amount' => 500,
                    'is_allocated' => true,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['fiscal_year_id']]);

        $this->assertDatabaseMissing('department_budgets', [
            'department_id' => $this->academicDepartment->id,
        ]);
    }

    public function test_budget_amounts_reject_negative_values_and_more_than_two_decimal_places(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2570]);
        $director = $this->user('director');
        $schoolUrl = "/api/v2/fiscal-years/{$fiscalYear->id}/school-budget";

        foreach ([-0.01, 1.999] as $invalidAmount) {
            $this->actingAs($director)
                ->putJson($schoolUrl, ['total_amount' => $invalidAmount])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonStructure(['errors' => ['total_amount']]);
        }

        $this->actingAs($director)
            ->putJson($schoolUrl, ['total_amount' => 1000])
            ->assertOk();

        $departmentUrl = "/api/v2/fiscal-years/{$fiscalYear->id}/department-budgets/{$this->academicDepartment->id}";

        foreach ([-0.01, 1.999] as $invalidAmount) {
            $this->actingAs($director)
                ->putJson($departmentUrl, [
                    'allocated_amount' => $invalidAmount,
                    'is_allocated' => true,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonStructure(['errors' => ['allocated_amount']]);
        }

        $this->assertDatabaseMissing('department_budgets', [
            'department_id' => $this->academicDepartment->id,
        ]);
    }

    public function test_budget_amounts_accept_validator_supported_plus_and_leading_dot_formats(): void
    {
        $fiscalYear = FiscalYear::create(['year' => 2570]);
        $director = $this->user('director');
        $schoolUrl = "/api/v2/fiscal-years/{$fiscalYear->id}/school-budget";

        foreach ([
            '+1.00' => '1.00',
            '.50' => '0.50',
            '+.50' => '0.50',
        ] as $input => $expected) {
            $this->actingAs($director)
                ->putJson($schoolUrl, ['total_amount' => $input])
                ->assertOk()
                ->assertJsonPath('data.total_amount', $expected);
        }

        $this->actingAs($director)
            ->putJson($schoolUrl, ['total_amount' => 1])
            ->assertOk();

        $departmentUrl = "/api/v2/fiscal-years/{$fiscalYear->id}/department-budgets/{$this->academicDepartment->id}";

        foreach ([
            '+1.00' => '1.00',
            '.50' => '0.50',
            '+.50' => '0.50',
        ] as $input => $expected) {
            $this->actingAs($director)
                ->putJson($departmentUrl, [
                    'allocated_amount' => $input,
                    'is_allocated' => true,
                ])
                ->assertOk()
                ->assertJsonPath('data.allocated_amount', $expected);
        }

        $this->actingAs($director)
            ->putJson($schoolUrl, ['total_amount' => '-.50'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['total_amount']]);

        $this->actingAs($director)
            ->putJson($departmentUrl, [
                'allocated_amount' => '-.50',
                'is_allocated' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['allocated_amount']]);
    }

    public function test_budget_management_index_uses_the_selected_year_and_lists_zero_department_rows(): void
    {
        $active = FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $requested = FiscalYear::create(['year' => 2571]);
        SchoolBudget::create([
            'fiscal_year_id' => $requested->id,
            'total_amount' => 2500,
        ]);

        $this->actingAs($this->user('director'))
            ->getJson("/api/v2/budget-management?fiscal_year_id={$requested->id}")
            ->assertOk()
            ->assertJsonPath('data.fiscal_year.id', $requested->id)
            ->assertJsonPath('data.school_budget.total_amount', '2500.00')
            ->assertJsonPath('data.can.manage_budgets', true)
            ->assertJsonCount(2, 'data.department_budgets');

        $this->assertNotSame($active->id, $requested->id);
    }

    private function user(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => $this->role($roleCode)->id,
            'is_active' => true,
        ]);
    }

    private function role(string $code): Role
    {
        return Role::query()->where('code', $code)->firstOrFail();
    }
}
