<?php

namespace Tests\Feature;

use App\Models\DssResult;
use App\Models\EvaluationCriterion;
use App\Models\Project;
use App\Models\ProjectEvaluation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\EvaluationCriteriaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectEvaluationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_evaluator_can_create_a_weighted_dss_result(): void
    {
        $this->seed([AuthorizationSeeder::class, EvaluationCriteriaSeeder::class]);
        [$project, $director] = $this->fixture();

        $scores = EvaluationCriterion::query()
            ->pluck('max_score', 'id')
            ->all();

        $this->actingAs($director)
            ->post(route('projects.evaluations.store', $project), [
                'round' => 1,
                'scores' => $scores,
                'comment' => 'ผ่านเกณฑ์ทุกด้าน',
            ])
            ->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseHas('project_evaluations', [
            'project_id' => $project->id,
            'evaluator_id' => $director->id,
            'round' => 1,
            'total_score' => 100,
        ]);

        $this->assertDatabaseHas('dss_results', [
            'project_id' => $project->id,
            'total_score' => 100,
            'recommendation' => 'แนะนำให้ดำเนินโครงการ',
        ]);

        $this->assertCount(count($scores), ProjectEvaluation::firstOrFail()->scores);
        $this->assertSame('weighted-score-v1', DssResult::firstOrFail()->calculation_version);
    }

    public function test_teacher_without_evaluate_permission_cannot_open_evaluation_form(): void
    {
        $this->seed([AuthorizationSeeder::class, EvaluationCriteriaSeeder::class]);
        [$project] = $this->fixture();
        $teacher = User::factory()->create([
            'role_id' => Role::where('code', 'teacher')->value('id'),
            'department_id' => $project->department_id,
        ]);

        $this->actingAs($teacher)
            ->get(route('projects.evaluations.edit', $project))
            ->assertForbidden();
    }

    private function fixture(): array
    {
        $departmentId = DB::table('departments')->insertGetId(['name' => 'Academic']);
        $categoryId = DB::table('project_categories')->insertGetId(['name' => 'Research']);
        $academicYearId = DB::table('academic_years')->insertGetId(['year' => 2569]);
        $statusId = DB::table('project_statuses')->insertGetId(['name' => 'Draft']);

        $owner = User::factory()->create([
            'role_id' => Role::where('code', 'teacher')->value('id'),
            'department_id' => $departmentId,
        ]);
        $director = User::factory()->create([
            'role_id' => Role::where('code', 'director')->value('id'),
        ]);

        $project = Project::create([
            'name' => 'โครงการประเมิน DSS',
            'objective' => 'ทดสอบคะแนนถ่วงน้ำหนัก',
            'budget' => 1000,
            'user_id' => $owner->id,
            'department_id' => $departmentId,
            'project_category_id' => $categoryId,
            'academic_year_id' => $academicYearId,
            'project_status_id' => $statusId,
        ]);

        return [$project, $director];
    }
}
