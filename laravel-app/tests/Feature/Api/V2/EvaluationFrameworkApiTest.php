<?php

namespace Tests\Feature\Api\V2;

use App\Models\Department;
use App\Models\EvaluationFramework;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluationFrameworkApiTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProjectStatusSeeder::class);
        $this->seed(AuthorizationSeeder::class);

        $this->department = Department::create(['name' => 'Academic Affairs']);
        $this->fiscalYear = FiscalYear::create([
            'year' => 2570,
            'start_date' => '2026-10-01',
            'end_date' => '2027-09-30',
            'is_active' => true,
        ]);
    }

    public function test_evaluation_permissions_are_seeded_for_existing_roles(): void
    {
        $permissions = [
            'evaluations.view',
            'evaluations.create',
            'evaluations.update',
            'evaluations.finalize',
            'evaluations.manage_frameworks',
        ];
        $expected = [
            'director' => $permissions,
            'deputy_director' => array_slice($permissions, 0, 3),
            'department_head' => array_slice($permissions, 0, 3),
            'teacher' => [],
        ];

        foreach ($expected as $roleCode => $granted) {
            $user = $this->user($roleCode);

            foreach ($permissions as $permission) {
                $this->assertSame(
                    in_array($permission, $granted, true),
                    $user->hasPermission($permission),
                    "Unexpected {$permission} grant for {$roleCode}.",
                );
            }
        }
    }

    public function test_framework_endpoints_require_authentication_and_policy_permissions(): void
    {
        $this->getJson('/api/v2/evaluation-frameworks')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $teacher = $this->user('teacher');

        $this->actingAs($teacher)
            ->getJson('/api/v2/evaluation-frameworks')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $deputy = $this->user('deputy_director');

        $this->actingAs($deputy)
            ->getJson('/api/v2/evaluation-frameworks')
            ->assertOk();

        $this->postJson('/api/v2/evaluation-frameworks', $this->frameworkPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $inactiveDirector = $this->user('director', false);

        $this->actingAs($inactiveDirector)
            ->getJson('/api/v2/evaluation-frameworks')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    }

    public function test_director_can_create_update_version_activate_and_deactivate_a_framework(): void
    {
        $director = $this->user('director');

        $created = $this->actingAs($director)
            ->postJson('/api/v2/evaluation-frameworks', $this->frameworkPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'SCHOOL-KPI')
            ->assertJsonPath('data.version', '1.0')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonCount(2, 'data.criteria');

        $frameworkId = (int) $created->json('data.id');

        $this->assertDatabaseHas('evaluation_frameworks', [
            'id' => $frameworkId,
            'code' => 'SCHOOL-KPI',
            'version' => '1.0',
            'name' => 'School KPI Framework',
            'fiscal_year_id' => $this->fiscalYear->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseCount('evaluation_criteria', 2);
        $this->assertDatabaseHas('evaluation_criteria', [
            'evaluation_framework_id' => $frameworkId,
            'name' => 'Outcome achievement',
            'max_score' => 5,
            'weight' => 60,
            'evaluation_method' => 'Review reported outcomes',
            'evaluation_tools' => 'Outcome checklist',
            'is_active' => true,
        ]);

        $updatedPayload = $this->editableFrameworkPayload([
            'name' => 'Updated School KPI Framework',
            'description' => 'Updated before the framework is used.',
        ]);

        $this->putJson("/api/v2/evaluation-frameworks/{$frameworkId}", $updatedPayload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated School KPI Framework');

        $versioned = $this->postJson(
            "/api/v2/evaluation-frameworks/{$frameworkId}/versions",
            $this->versionPayload(),
        )
            ->assertCreated()
            ->assertJsonPath('data.code', 'SCHOOL-KPI')
            ->assertJsonPath('data.version', '2.0')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonCount(2, 'data.criteria');

        $versionedId = (int) $versioned->json('data.id');

        $this->assertNotSame($frameworkId, $versionedId);
        $this->assertDatabaseHas('evaluation_frameworks', [
            'id' => $versionedId,
            'code' => 'SCHOOL-KPI',
            'version' => '2.0',
            'is_active' => false,
        ]);

        $this->postJson("/api/v2/evaluation-frameworks/{$versionedId}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('evaluation_frameworks', [
            'id' => $versionedId,
            'is_active' => true,
        ]);

        $this->postJson("/api/v2/evaluation-frameworks/{$versionedId}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('evaluation_frameworks', [
            'id' => $versionedId,
            'is_active' => false,
        ]);
    }

    public function test_framework_validation_rejects_duplicate_versions_invalid_dates_and_invalid_criteria(): void
    {
        $director = $this->user('director');

        $this->actingAs($director)
            ->postJson('/api/v2/evaluation-frameworks', $this->frameworkPayload())
            ->assertCreated();

        $this->postJson('/api/v2/evaluation-frameworks', $this->frameworkPayload())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['version']]);

        $this->postJson('/api/v2/evaluation-frameworks', $this->frameworkPayload([
            'code' => 'INVALID-DATES',
            'effective_from' => '2027-09-30',
            'effective_to' => '2026-10-01',
        ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['effective_to']]);

        $invalidCriteria = $this->frameworkPayload([
            'code' => 'INVALID-CRITERIA',
            'criteria' => [[
                'name' => 'Invalid criterion',
                'max_score' => 0,
                'weight' => -1,
                'sort_order' => 10,
                'is_active' => true,
            ]],
        ]);

        $this->postJson('/api/v2/evaluation-frameworks', $invalidCriteria)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors']);

        $this->assertDatabaseCount('evaluation_frameworks', 1);
    }

    public function test_framework_activation_rejects_partially_weighted_criteria(): void
    {
        $director = $this->user('director');
        $criteria = $this->criteriaPayload();
        $criteria[1]['weight'] = 0;

        $frameworkId = (int) $this->actingAs($director)
            ->postJson('/api/v2/evaluation-frameworks', $this->frameworkPayload([
                'code' => 'MIXED-WEIGHTS',
                'criteria' => $criteria,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.is_active', false)
            ->json('data.id');

        $this->postJson("/api/v2/evaluation-frameworks/{$frameworkId}/activate")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors']);

        $this->assertDatabaseHas('evaluation_frameworks', [
            'id' => $frameworkId,
            'is_active' => false,
        ]);

        $this->fiscalYear->update(['is_locked' => true]);

        $this->getJson("/api/v2/evaluation-frameworks/{$frameworkId}")
            ->assertOk()
            ->assertJsonPath('data.fiscal_year.is_locked', true)
            ->assertJsonPath('data.abilities.update', false)
            ->assertJsonPath('data.abilities.create_version', false)
            ->assertJsonPath('data.abilities.activate', false)
            ->assertJsonPath('data.abilities.deactivate', false);

        $this->putJson(
            "/api/v2/evaluation-frameworks/{$frameworkId}",
            $this->editableFrameworkPayload(['name' => 'Must remain unchanged']),
        )->assertForbidden();

        $this->postJson(
            "/api/v2/evaluation-frameworks/{$frameworkId}/versions",
            $this->versionPayload(),
        )->assertForbidden();

        $this->postJson("/api/v2/evaluation-frameworks/{$frameworkId}/activate")
            ->assertForbidden();

        $this->postJson("/api/v2/evaluation-frameworks/{$frameworkId}/deactivate")
            ->assertForbidden();

        $this->postJson('/api/v2/evaluation-frameworks', $this->frameworkPayload([
            'code' => 'LOCKED-YEAR',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['fiscal_year_id']]);

        $this->assertDatabaseHas('evaluation_frameworks', [
            'id' => $frameworkId,
            'name' => 'School KPI Framework',
            'is_active' => false,
        ]);
        $this->assertDatabaseCount('evaluation_frameworks', 1);
    }

    public function test_partial_update_compares_non_iso_effective_dates_chronologically(): void
    {
        $director = $this->user('director');
        $frameworkId = (int) $this->actingAs($director)
            ->postJson('/api/v2/evaluation-frameworks', $this->frameworkPayload())
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v2/evaluation-frameworks/{$frameworkId}", [
            'effective_from' => '12/31/2027',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['effective_to']]);

        $framework = EvaluationFramework::findOrFail($frameworkId);
        $this->assertSame('2026-10-01', $framework->effective_from?->toDateString());
        $this->assertSame('2027-09-30', $framework->effective_to?->toDateString());
    }

    public function test_active_framework_update_rejects_partially_weighted_criteria(): void
    {
        $director = $this->user('director');
        $frameworkId = (int) $this->actingAs($director)
            ->postJson('/api/v2/evaluation-frameworks', $this->frameworkPayload([
                'code' => 'ACTIVE-WEIGHTS',
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v2/evaluation-frameworks/{$frameworkId}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $criteria = $this->criteriaPayload();
        $criteria[1]['weight'] = 0;

        $this->putJson(
            "/api/v2/evaluation-frameworks/{$frameworkId}",
            $this->editableFrameworkPayload(['criteria' => $criteria]),
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['criteria']]);

        $this->assertDatabaseHas('evaluation_frameworks', [
            'id' => $frameworkId,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('evaluation_criteria', [
            'evaluation_framework_id' => $frameworkId,
            'name' => 'Budget stewardship',
            'weight' => 40,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function frameworkPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'SCHOOL-KPI',
            'name' => 'School KPI Framework',
            'version' => '1.0',
            'description' => 'School-approved project evaluation indicators.',
            'fiscal_year_id' => $this->fiscalYear->id,
            'effective_from' => '2026-10-01',
            'effective_to' => '2027-09-30',
            'criteria' => $this->criteriaPayload(),
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function versionPayload(): array
    {
        return [
            'version' => '2.0',
            'name' => 'School KPI Framework 2',
            'description' => 'A new immutable version.',
            'fiscal_year_id' => $this->fiscalYear->id,
            'effective_from' => '2026-10-01',
            'effective_to' => '2027-09-30',
            'criteria' => $this->criteriaPayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editableFrameworkPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'School KPI Framework',
            'description' => 'School-approved project evaluation indicators.',
            'fiscal_year_id' => $this->fiscalYear->id,
            'effective_from' => '2026-10-01',
            'effective_to' => '2027-09-30',
            'criteria' => $this->criteriaPayload(),
        ], $overrides);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function criteriaPayload(): array
    {
        return [
            [
                'name' => 'Outcome achievement',
                'description' => 'Compare actual outcomes with the stated target.',
                'max_score' => 5,
                'weight' => 60,
                'sort_order' => 10,
                'evaluation_method' => 'Review reported outcomes',
                'evaluation_tools' => 'Outcome checklist',
                'is_active' => true,
            ],
            [
                'name' => 'Budget stewardship',
                'description' => 'Review the appropriate use of project resources.',
                'max_score' => 10,
                'weight' => 40,
                'sort_order' => 20,
                'evaluation_method' => 'Document review',
                'evaluation_tools' => 'Budget evidence form',
                'is_active' => true,
            ],
        ];
    }

    private function user(string $roleCode, bool $isActive = true): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'department_id' => $this->department->id,
            'is_active' => $isActive,
        ]);
    }
}
