<?php

namespace Tests\Feature;

use App\Models\EvaluationCriterion;
use App\Models\EvaluationScore;
use App\Models\Project;
use App\Models\ProjectEvaluation;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\DssPortfolioService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DecisionSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_saw_ranking_uses_latest_authorized_evaluations(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [$director, $yearId, $projectA, $projectB] = $this->fixture();

        $this->actingAs($director)
            ->get(route('dss.index', ['year_id' => $yearId]))
            ->assertOk()
            ->assertSeeInOrder([$projectA->name, $projectB->name])
            ->assertSee('100.00')
            ->assertSee('60.00');
    }

    public function test_budget_optimizer_selects_best_feasible_portfolio(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [, , $projectA, $projectB, $criteria] = $this->fixture();
        $service = app(DssPortfolioService::class);
        $ranked = $service->rank(
            collect([$projectA->load('evaluations.scores'), $projectB->load('evaluations.scores')]),
            $criteria,
            $criteria->pluck('weight', 'id')->all()
        );

        $portfolio = $service->optimizeBudget($ranked, 500);

        $this->assertSame([$projectB->id], $portfolio['ids']);
        $this->assertSame(500.0, $portfolio['budget']);
    }

    public function test_teacher_cannot_open_management_dss_center(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $teacher = User::factory()->create([
            'role_id' => Role::where('code', 'teacher')->value('id'),
        ]);

        $this->actingAs($teacher)
            ->get(route('dss.index'))
            ->assertForbidden();
    }

    private function fixture(): array
    {
        $departmentId = DB::table('departments')->insertGetId(['name' => 'Academic']);
        $categoryId = DB::table('project_categories')->insertGetId(['name' => 'Research']);
        $yearId = DB::table('academic_years')->insertGetId(['year' => 2569, 'is_active' => true]);
        $status = ProjectStatus::firstOrCreate(
            ['code' => 'pending_director'],
            ['name' => 'Pending Director']
        );

        $teacher = User::factory()->create([
            'role_id' => Role::where('code', 'teacher')->value('id'),
            'department_id' => $departmentId,
        ]);
        $director = User::factory()->create([
            'role_id' => Role::where('code', 'director')->value('id'),
        ]);

        $criteria = collect([
            EvaluationCriterion::create(['name' => 'ผลกระทบ', 'weight' => 50, 'max_score' => 5, 'sort_order' => 10]),
            EvaluationCriterion::create(['name' => 'ความเป็นไปได้', 'weight' => 50, 'max_score' => 5, 'sort_order' => 20]),
        ]);

        $projectA = $this->project('โครงการคะแนนสูง', 1000, $teacher, $departmentId, $categoryId, $yearId, $status->id);
        $projectB = $this->project('โครงการคะแนนรอง', 500, $teacher, $departmentId, $categoryId, $yearId, $status->id);

        $this->evaluate($projectA, $director, $criteria, 5);
        $this->evaluate($projectB, $director, $criteria, 3);

        return [$director, $yearId, $projectA, $projectB, $criteria];
    }

    private function project(string $name, float $budget, User $owner, int $departmentId, int $categoryId, int $yearId, int $statusId): Project
    {
        return Project::create([
            'name' => $name,
            'objective' => 'ใช้สำหรับทดสอบการจัดอันดับ',
            'budget' => $budget,
            'user_id' => $owner->id,
            'department_id' => $departmentId,
            'project_category_id' => $categoryId,
            'academic_year_id' => $yearId,
            'project_status_id' => $statusId,
        ]);
    }

    private function evaluate(Project $project, User $evaluator, $criteria, float $score): void
    {
        $evaluation = ProjectEvaluation::create([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
            'round' => 1,
            'total_score' => ($score / 5) * 100,
            'evaluated_at' => now(),
        ]);

        foreach ($criteria as $criterion) {
            EvaluationScore::create([
                'evaluation_id' => $evaluation->id,
                'criteria_id' => $criterion->id,
                'score' => $score,
            ]);
        }
    }
}
