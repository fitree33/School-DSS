<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Role;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectPageNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_links_to_the_create_project_page(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create([
            'role_id' => Role::where('code', 'teacher')->value('id'),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('projects.create'), false)
            ->assertSee('ร่างโครงการใหม่');
    }

    public function test_project_page_renders_safe_markdown_and_links_to_dashboard(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user, <<<'MARKDOWN'
# ภาพรวม

- รายการแรก
- รายการที่สอง

**ประเด็นสำคัญ**

<script>alert('unsafe')</script>
MARKDOWN);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee(route('dashboard'), false)
            ->assertSee('กลับ Dashboard')
            ->assertSee(route('projects.edit', $project), false)
            ->assertSee('แก้ไขโครงการ')
            ->assertSee('<h1>ภาพรวม</h1>', false)
            ->assertSee('<li>รายการแรก</li>', false)
            ->assertSee('<strong>ประเด็นสำคัญ</strong>', false)
            ->assertDontSee('<script>', false);
    }

    private function createProject(User $user, string $summary): Project
    {
        $suffix = Str::uuid()->toString();
        $now = now();

        $departmentId = DB::table('departments')->insertGetId([
            'name' => "Test Department {$suffix}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $categoryId = DB::table('project_categories')->insertGetId([
            'name' => "Test Category {$suffix}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $academicYearId = DB::table('academic_years')->insertGetId([
            'year' => 9999,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $statusId = DB::table('project_statuses')->insertGetId([
            'name' => "Test Status {$suffix}",
            'code' => 'draft',
            'color' => 'indigo',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Project::create([
            'name' => 'Markdown Summary Project',
            'objective' => 'Verify the summary presentation.',
            'ai_summary' => $summary,
            'ai_summarized_at' => $now,
            'budget' => 1000,
            'user_id' => $user->id,
            'department_id' => $departmentId,
            'project_category_id' => $categoryId,
            'academic_year_id' => $academicYearId,
            'project_status_id' => $statusId,
        ]);
    }
}
