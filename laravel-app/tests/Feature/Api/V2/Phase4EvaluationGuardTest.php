<?php

namespace Tests\Feature\Api\V2;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationStatus;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectExecutionStatus;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\EvaluationCriteriaSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Phase4EvaluationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProjectStatusSeeder::class);
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_phase_four_schema_is_additive_and_does_not_introduce_forbidden_scope(): void
    {
        foreach ([
            'evaluation_frameworks',
            'evaluation_criteria',
            'project_evaluations',
            'evaluation_scores',
            'project_evaluation_results',
            'evaluation_statuses',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing Phase 4 table: {$table}");
        }

        $this->assertTrue(Schema::hasColumns('evaluation_frameworks', [
            'code',
            'version',
            'name',
            'description',
            'fiscal_year_id',
            'effective_from',
            'effective_to',
            'is_active',
        ]));
        $this->assertTrue(Schema::hasColumns('evaluation_criteria', [
            'evaluation_framework_id',
            'description',
            'max_score',
            'weight',
            'sort_order',
            'evaluation_method',
            'evaluation_tools',
            'is_active',
        ]));
        $this->assertTrue(Schema::hasColumns('project_evaluations', [
            'project_id',
            'evaluation_framework_id',
            'evaluator_id',
            'round',
            'total_score',
            'maximum_score',
            'percentage',
            'weighted_percentage',
            'comment',
            'evaluated_at',
            'finalized_by',
            'finalized_at',
        ]));
        $this->assertTrue(Schema::hasColumns('project_evaluation_results', [
            'project_id',
            'project_evaluation_id',
            'evaluation_framework_id',
            'evaluation_status_id',
            'total_score',
            'maximum_score',
            'percentage',
            'weighted_percentage',
            'scores_snapshot',
            'decision_note',
            'finalized_by',
            'finalized_at',
        ]));

        foreach ([
            'activities',
            'sub_activities',
            'project_activities',
            'project_sub_activities',
            'evaluation_imports',
            'ai_evaluation_imports',
            'project_evaluation_signatures',
            'pdf_signatures',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Forbidden Phase 4 table exists: {$table}");
        }

        $director = $this->user('director');

        foreach ([
            '/api/v2/evaluation-imports',
            '/api/v2/evaluation-signatures',
            '/api/v2/project-evaluations/1/pdf-signature',
            '/api/v2/activities',
            '/api/v2/sub-activities',
        ] as $uri) {
            $this->actingAs($director)
                ->getJson($uri)
                ->assertNotFound()
                ->assertJsonPath('code', 'not_found');
        }
    }

    public function test_legacy_evaluation_history_is_isolated_from_phase_four_results_and_status(): void
    {
        $this->seed(EvaluationCriteriaSeeder::class);
        $department = Department::create(['name' => 'Legacy Evaluation Department']);
        $academicYear = AcademicYear::create(['year' => 2570, 'is_active' => true]);
        $fiscalYear = FiscalYear::create(['year' => 2570, 'is_active' => true]);
        $category = ProjectCategory::create(['name' => 'Legacy Evaluation Category']);
        $director = $this->user('director', $department);
        $project = Project::create([
            'name' => 'Legacy Evaluation Project',
            'objective' => 'Prove legacy scoring cannot write a Phase 4 result.',
            'budget' => 1000,
            'actual_spent' => 0,
            'user_id' => $director->id,
            'department_id' => $department->id,
            'project_category_id' => $category->id,
            'academic_year_id' => $academicYear->id,
            'fiscal_year_id' => $fiscalYear->id,
            'project_status_id' => ProjectStatus::query()->where('code', 'draft')->value('id'),
            'project_execution_status_id' => ProjectExecutionStatus::query()
                ->where('code', 'not_started')
                ->value('id'),
            'evaluation_status_id' => EvaluationStatus::query()
                ->where('code', 'pending')
                ->value('id'),
        ]);
        $scores = EvaluationCriterion::query()
            ->whereNull('evaluation_framework_id')
            ->pluck('max_score', 'id')
            ->all();

        $this->assertNotEmpty($scores);

        foreach ([99, 99] as $clientRound) {
            $this->actingAs($director)
                ->post(route('projects.evaluations.store', $project), [
                    'round' => $clientRound,
                    'scores' => $scores,
                    'comment' => 'Legacy evaluation must remain isolated.',
                ])
                ->assertRedirect(route('projects.show', $project));
        }

        $legacyEvaluations = DB::table('project_evaluations')
            ->where('project_id', $project->id)
            ->orderBy('round')
            ->get();

        $this->assertCount(2, $legacyEvaluations);
        $this->assertSame([1, 2], $legacyEvaluations->pluck('round')->map(fn ($round) => (int) $round)->all());
        $this->assertTrue($legacyEvaluations->every(
            fn (object $evaluation): bool => $evaluation->evaluation_framework_id === null,
        ));
        $this->assertDatabaseCount('project_evaluation_results', 0);
        $this->assertSame(2, DB::table('dss_results')->where('project_id', $project->id)->count());
        $this->assertSame('pending', $project->fresh()->evaluationStatus->code);

        $phaseFourEvaluator = $this->user('deputy_director', $department);
        $timestamp = now();
        $frameworkId = DB::table('evaluation_frameworks')->insertGetId([
            'code' => 'PHASE4-ISOLATION',
            'version' => '1.0',
            'name' => 'Phase 4 isolation fixture',
            'fiscal_year_id' => $fiscalYear->id,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('project_evaluations')->insert([
            'project_id' => $project->id,
            'evaluator_id' => $phaseFourEvaluator->id,
            'evaluation_framework_id' => $frameworkId,
            'round' => 1,
            'total_score' => 15,
            'maximum_score' => 15,
            'percentage' => 100,
            'comment' => 'Must not affect the legacy DSS evaluator count.',
            'evaluated_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->actingAs($director)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('ผู้ประเมิน 1 คน');
        /** @var Project $renderedProject */
        $renderedProject = $response->viewData('project');

        $this->assertCount(2, $renderedProject->evaluations);
        $this->assertTrue($renderedProject->evaluations->every(
            fn ($evaluation): bool => $evaluation->evaluation_framework_id === null,
        ));
    }

    private function user(string $roleCode, ?Department $department = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'department_id' => $department?->id,
            'is_active' => true,
        ]);
    }
}
