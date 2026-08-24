<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_cannot_view_another_teachers_project_without_access(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [$owner, $viewer, $project] = $this->projectFixture();

        $this->actingAs($viewer)
            ->get(route('projects.show', $project))
            ->assertForbidden();

        ProjectAccess::create([
            'project_id' => $project->id,
            'user_id' => $viewer->id,
            'can_view' => true,
            'granted_by' => $owner->id,
        ]);

        $this->assertTrue($project->fresh()->hasAccess($viewer, 'view'));
        $this->assertTrue($viewer->fresh()->can('view', $project->fresh()));

        $this->actingAs($viewer)
            ->get(route('projects.show', $project))
            ->assertOk();
    }

    public function test_secure_search_only_returns_projects_visible_to_the_user(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [, $viewer, $project] = $this->projectFixture('โครงการลับเฉพาะฝ่าย');

        $this->actingAs($viewer)
            ->get(route('projects.search', ['q' => 'ลับเฉพาะฝ่าย']))
            ->assertOk()
            ->assertDontSee($project->name);
    }

    public function test_dashboard_only_contains_projects_visible_to_the_user(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [, $viewer, $project] = $this->projectFixture('โครงการที่ห้ามรั่วบน Dashboard');

        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee($project->name);
    }

    public function test_director_can_view_every_project(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [, , $project] = $this->projectFixture();
        $director = User::factory()->create([
            'role_id' => Role::where('code', 'director')->value('id'),
        ]);

        $this->assertTrue($director->fresh()->hasPermission('projects.view_all'));
        $this->assertTrue($director->fresh()->can('view', $project->fresh()));

        $this->actingAs($director)
            ->get(route('projects.show', $project))
            ->assertOk();
    }

    public function test_access_update_reauthorizes_after_reloading_the_locked_project(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [, $viewer, $project] = $this->projectFixture();
        $director = User::factory()->create([
            'role_id' => Role::where('code', 'director')->value('id'),
        ]);
        $fiscalYear = FiscalYear::create([
            'year' => 2570,
            'is_active' => true,
        ]);
        $project->update(['fiscal_year_id' => $fiscalYear->id]);
        $retrievals = 0;

        Project::retrieved(function (Project $retrieved) use ($project, $fiscalYear, &$retrievals): void {
            if ((int) $retrieved->id !== (int) $project->id) {
                return;
            }

            $retrievals++;

            if ($retrievals === 2) {
                FiscalYear::query()
                    ->whereKey($fiscalYear->id)
                    ->update(['is_locked' => true]);
            }
        });

        $this->actingAs($director)
            ->put(route('projects.access.update', $project), [
                'access' => [
                    $viewer->id => [
                        'can_view' => true,
                        'can_edit' => true,
                    ],
                ],
            ])
            ->assertForbidden();

        $this->assertGreaterThanOrEqual(2, $retrievals);
        $this->assertDatabaseMissing('project_access', [
            'project_id' => $project->id,
            'user_id' => $viewer->id,
        ]);
    }

    private function projectFixture(string $name = 'Owner Project'): array
    {
        $teacherRoleId = Role::where('code', 'teacher')->value('id');
        $departmentId = DB::table('departments')->insertGetId(['name' => 'Academic']);
        $categoryId = DB::table('project_categories')->insertGetId(['name' => 'Research']);
        $academicYearId = DB::table('academic_years')->insertGetId(['year' => 2569]);
        $statusId = DB::table('project_statuses')->insertGetId(['name' => 'Draft']);

        $owner = User::factory()->create([
            'role_id' => $teacherRoleId,
            'department_id' => $departmentId,
        ]);
        $viewer = User::factory()->create([
            'role_id' => $teacherRoleId,
            'department_id' => $departmentId,
        ]);

        $project = Project::create([
            'name' => $name,
            'objective' => 'Permission test objective',
            'budget' => 1000,
            'user_id' => $owner->id,
            'department_id' => $departmentId,
            'project_category_id' => $categoryId,
            'academic_year_id' => $academicYearId,
            'project_status_id' => $statusId,
        ]);

        return [$owner, $viewer, $project];
    }
}
