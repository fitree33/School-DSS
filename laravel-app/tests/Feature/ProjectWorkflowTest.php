<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectKpi;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_moves_through_teacher_deputy_and_director_workflow(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [$project, $teacher, $deputy, $director] = $this->fixture('draft');

        $this->actingAs($teacher)
            ->post(route('projects.workflow.submit', $project))
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('pending_deputy', $project->status->code);
        $this->assertNotNull($project->submitted_at);
        $this->assertDatabaseHas('project_notifications', [
            'user_id' => $deputy->id,
            'project_id' => $project->id,
            'type' => 'review',
        ]);

        $this->actingAs($director)
            ->post(route('projects.workflow.decide', $project), [
                'decision' => 'approve',
                'comment' => 'พยายามข้ามขั้นตอน',
            ])
            ->assertForbidden();

        $this->actingAs($deputy)
            ->post(route('projects.workflow.screen', $project), [
                'decision' => 'forward',
                'comment' => 'ข้อมูลครบถ้วน ส่งต่อเพื่ออนุมัติ',
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('pending_director', $project->status->code);
        $this->assertNotNull($project->screened_at);

        $this->actingAs($director)
            ->post(route('projects.workflow.decide', $project), [
                'decision' => 'approve',
                'comment' => 'อนุมัติตามแผนงาน',
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('approved', $project->status->code);
        $this->assertNotNull($project->approved_at);
        $this->assertDatabaseCount('project_status_histories', 4);
    }

    public function test_teacher_cannot_edit_project_after_submitting_it(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [$project, $teacher] = $this->fixture('pending_deputy');

        $this->actingAs($teacher)
            ->put(route('projects.update', $project), [])
            ->assertForbidden();
    }

    public function test_completion_report_updates_actual_kpi_and_closes_project(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [$project, $teacher] = $this->fixture('approved');
        $kpi = ProjectKpi::create([
            'project_id' => $project->id,
            'name' => 'ผู้เข้าร่วมผ่านเกณฑ์',
            'target_value' => 80,
            'unit' => 'ร้อยละ',
        ]);

        $this->actingAs($teacher)
            ->post(route('projects.workflow.complete', $project), [
                'success_percent' => 92,
                'quality_score' => 4.5,
                'actual_spent' => 900,
                'summary' => 'ดำเนินกิจกรรมครบตามแผน',
                'problems' => 'เวลาจำกัด',
                'suggestions' => 'เพิ่มระยะเวลาในปีถัดไป',
                'kpi_actuals' => [$kpi->id => 88],
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('completed', $project->status->code);
        $this->assertSame('900.00', $project->actual_spent);
        $this->assertSame('88.00', $kpi->fresh()->actual_value);
        $this->assertDatabaseHas('project_completion_reports', [
            'project_id' => $project->id,
            'reported_by' => $teacher->id,
            'success_percent' => 92,
            'quality_score' => 4.5,
        ]);
    }

    private function fixture(string $statusCode): array
    {
        foreach ([
            'draft' => 'Draft',
            'returned' => 'Returned for Revision',
            'pending_deputy' => 'Pending Deputy Review',
            'pending_director' => 'Pending Director Approval',
            'approved' => 'Approved',
            'completed' => 'Completed',
        ] as $code => $name) {
            ProjectStatus::updateOrCreate(['code' => $code], ['name' => $name]);
        }

        $departmentId = DB::table('departments')->insertGetId(['name' => 'Academic']);
        $categoryId = DB::table('project_categories')->insertGetId(['name' => 'Research']);
        $yearId = DB::table('academic_years')->insertGetId(['year' => 2569]);

        $teacher = User::factory()->create([
            'role_id' => Role::where('code', 'teacher')->value('id'),
            'department_id' => $departmentId,
        ]);
        $deputy = User::factory()->create([
            'role_id' => Role::where('code', 'deputy_director')->value('id'),
        ]);
        $director = User::factory()->create([
            'role_id' => Role::where('code', 'director')->value('id'),
        ]);

        $project = Project::create([
            'name' => 'โครงการตาม Workflow',
            'objective' => 'ทดสอบการอนุมัติหลายขั้นตอน',
            'budget' => 1000,
            'user_id' => $teacher->id,
            'department_id' => $departmentId,
            'project_category_id' => $categoryId,
            'academic_year_id' => $yearId,
            'project_status_id' => ProjectStatus::where('code', $statusCode)->value('id'),
        ]);

        DB::table('project_status_histories')->insert([
            'project_id' => $project->id,
            'to_status_id' => $project->project_status_id,
            'changed_by' => $teacher->id,
            'comment' => 'สร้างโครงการ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$project, $teacher, $deputy, $director];
    }
}
