<?php

namespace Tests\Feature\Api\V2;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\EvaluationStatus;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectExecutionStatus;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectEvaluationApiTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Department $otherDepartment;

    private ProjectCategory $category;

    private AcademicYear $academicYear;

    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProjectStatusSeeder::class);
        $this->seed(AuthorizationSeeder::class);

        $this->department = Department::create(['name' => 'Academic Affairs']);
        $this->otherDepartment = Department::create(['name' => 'Student Affairs']);
        $this->category = ProjectCategory::create(['name' => 'School Development']);
        $this->academicYear = AcademicYear::create(['year' => 2570, 'is_active' => true]);
        $this->fiscalYear = FiscalYear::create([
            'year' => 2570,
            'start_date' => '2026-10-01',
            'end_date' => '2027-09-30',
            'is_active' => true,
        ]);
    }

    public function test_evaluation_options_and_project_queue_are_permission_scoped_and_filterable(): void
    {
        [$frameworkId] = $this->framework($this->fiscalYear, true);
        $departmentHead = $this->user('department_head', $this->department);
        $otherOwner = $this->user('department_head', $this->otherDepartment);
        $visible = $this->project($departmentHead, $this->fiscalYear, [
            'name' => 'Visible pending evaluation',
        ]);
        $hidden = $this->project($otherOwner, $this->fiscalYear, [
            'name' => 'Other department evaluation',
            'department_id' => $this->otherDepartment->id,
        ]);

        $teacher = $this->user('teacher', $this->department);

        $this->actingAs($teacher)
            ->getJson('/api/v2/evaluation-options')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $options = $this->actingAs($departmentHead)
            ->getJson('/api/v2/evaluation-options')
            ->assertOk()
            ->assertJsonPath('data.can.manage_frameworks', false)
            ->assertJsonFragment(['id' => $frameworkId, 'code' => 'SCHOOL-KPI']);

        $this->assertNotEmpty($options->json('data.fiscal_years'));
        $this->assertNotEmpty($options->json('data.departments'));
        $this->assertNotEmpty($options->json('data.evaluation_statuses'));

        $queue = $this->getJson(sprintf(
            '/api/v2/evaluation-projects?fiscal_year_id=%d&department_id=%d&evaluation_status=pending&per_page=100',
            $this->fiscalYear->id,
            $this->department->id,
        ))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.abilities.view_evaluations', true)
            ->assertJsonPath('data.0.abilities.create_evaluation', true);

        $this->assertNotContains($hidden->id, $queue->json('data.*.id'));
    }

    public function test_create_evaluation_sets_evaluator_and_round_server_side_and_calculates_totals_without_auto_result(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $evaluator = $this->user('department_head', $this->department);
        $project = $this->project($evaluator, $this->fiscalYear);

        $attemptedOwnershipOverride = $this->evaluationPayload($frameworkId, $criteria) + [
            'evaluator_id' => $this->user('director')->id,
            'round' => 99,
        ];

        $this->actingAs($evaluator)
            ->postJson("/api/v2/projects/{$project->id}/evaluations", $attemptedOwnershipOverride)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors']);

        $created = $this->postJson(
            "/api/v2/projects/{$project->id}/evaluations",
            $this->evaluationPayload($frameworkId, $criteria),
        )
            ->assertCreated()
            ->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.framework.id', $frameworkId)
            ->assertJsonPath('data.evaluator.id', $evaluator->id)
            ->assertJsonPath('data.round', 1)
            ->assertJsonPath('data.comment', 'Evidence reviewed by the evaluator.')
            ->assertJsonFragment(['comment' => 'Most outcomes were achieved.'])
            ->assertJsonPath('data.result', null)
            ->assertJsonCount(2, 'data.scores');

        $evaluationId = (int) $created->json('data.id');
        $evaluation = DB::table('project_evaluations')->find($evaluationId);

        $this->assertSame($project->id, (int) $evaluation->project_id);
        $this->assertSame($frameworkId, (int) $evaluation->evaluation_framework_id);
        $this->assertSame($evaluator->id, (int) $evaluation->evaluator_id);
        $this->assertSame(1, (int) $evaluation->round);
        $this->assertEqualsWithDelta(10.0, (float) $evaluation->total_score, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $evaluation->maximum_score, 0.001);
        $this->assertEqualsWithDelta(66.67, (float) $evaluation->percentage, 0.001);
        $this->assertEqualsWithDelta(72.0, (float) $evaluation->weighted_percentage, 0.001);
        $this->assertNull($evaluation->finalized_at);
        $this->assertDatabaseCount('evaluation_scores', 2);
        $this->assertDatabaseCount('project_evaluation_results', 0);
        $this->assertSame('pending', $project->fresh()->evaluationStatus->code);
    }

    public function test_optional_weights_leave_weighted_percentage_null_without_hiding_raw_totals(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true, 'UNWEIGHTED');
        DB::table('evaluation_criteria')
            ->where('evaluation_framework_id', $frameworkId)
            ->update(['weight' => 0]);
        $criteria = DB::table('evaluation_criteria')
            ->where('evaluation_framework_id', $frameworkId)
            ->orderBy('sort_order')
            ->get()
            ->all();
        $evaluator = $this->user('department_head', $this->department);
        $project = $this->project($evaluator, $this->fiscalYear);

        $created = $this->actingAs($evaluator)
            ->postJson(
                "/api/v2/projects/{$project->id}/evaluations",
                $this->evaluationPayload($frameworkId, $criteria),
            )
            ->assertCreated()
            ->assertJsonPath('data.total_score', '10.00')
            ->assertJsonPath('data.maximum_score', '15.00')
            ->assertJsonPath('data.percentage', '66.67')
            ->assertJsonPath('data.weighted_percentage', null);

        $evaluation = DB::table('project_evaluations')->find($created->json('data.id'));
        $this->assertNull($evaluation->weighted_percentage);
        $this->assertSame('pending', $project->fresh()->evaluationStatus->code);
    }

    public function test_project_evaluation_policy_enforces_view_create_and_finalize_permissions(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $departmentHead = $this->user('department_head', $this->department);
        $teacher = $this->user('teacher', $this->department);
        $project = $this->project($departmentHead, $this->fiscalYear);

        $this->actingAs($teacher)
            ->getJson("/api/v2/projects/{$project->id}/evaluations")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->postJson(
            "/api/v2/projects/{$project->id}/evaluations",
            $this->evaluationPayload($frameworkId, $criteria),
        )->assertForbidden();

        $evaluationId = $this->createEvaluation($departmentHead, $project, $frameworkId, $criteria);

        $this->postJson("/api/v2/project-evaluations/{$evaluationId}/finalize", [
            'status' => 'passed',
            'decision_note' => 'Department heads do not hold finalize permission.',
        ])->assertForbidden();

        $deputy = $this->user('deputy_director', $this->department);

        $this->actingAs($deputy)
            ->postJson("/api/v2/project-evaluations/{$evaluationId}/finalize", [
                'status' => 'passed',
                'decision_note' => 'Deputy directors do not hold finalize permission.',
            ])
            ->assertForbidden();

        $otherDepartmentHead = $this->user('department_head', $this->otherDepartment);

        $this->actingAs($otherDepartmentHead)
            ->getJson("/api/v2/project-evaluations/{$evaluationId}")
            ->assertForbidden();

        $this->assertDatabaseCount('project_evaluation_results', 0);
        $this->assertSame('pending', $project->fresh()->evaluationStatus->code);
    }

    public function test_score_validation_rejects_missing_duplicate_foreign_and_out_of_range_scores(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        [, $foreignCriteria] = $this->framework($this->fiscalYear, true, 'OTHER-KPI');
        $evaluator = $this->user('department_head', $this->department);
        $project = $this->project($evaluator, $this->fiscalYear);
        $uri = "/api/v2/projects/{$project->id}/evaluations";

        $missing = $this->evaluationPayload($frameworkId, $criteria);
        array_pop($missing['scores']);

        $this->actingAs($evaluator)
            ->postJson($uri, $missing)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors']);

        $outOfRange = $this->evaluationPayload($frameworkId, $criteria);
        $outOfRange['scores'][0]['score'] = 5.01;

        $this->postJson($uri, $outOfRange)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors']);

        $negative = $this->evaluationPayload($frameworkId, $criteria);
        $negative['scores'][0]['score'] = -0.01;

        $this->postJson($uri, $negative)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors']);

        $duplicate = $this->evaluationPayload($frameworkId, $criteria);
        $duplicate['scores'][1]['evaluation_criterion_id'] = $criteria[0]->id;

        $this->postJson($uri, $duplicate)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors']);

        $foreign = $this->evaluationPayload($frameworkId, $criteria);
        $foreign['scores'][1]['evaluation_criterion_id'] = $foreignCriteria[1]->id;

        $this->postJson($uri, $foreign)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors']);

        $this->assertDatabaseCount('project_evaluations', 0);
        $this->assertDatabaseCount('evaluation_scores', 0);
    }

    public function test_framework_must_be_active_applicable_and_match_the_project_fiscal_year(): void
    {
        [$inactiveFramework, $inactiveCriteria] = $this->framework($this->fiscalYear, false, 'INACTIVE');
        $otherYear = FiscalYear::create([
            'year' => 2571,
            'start_date' => '2027-10-01',
            'end_date' => '2028-09-30',
        ]);
        [$otherYearFramework, $otherYearCriteria] = $this->framework($otherYear, true, 'OTHER-YEAR');
        $evaluator = $this->user('department_head', $this->department);
        $project = $this->project($evaluator, $this->fiscalYear);
        $uri = "/api/v2/projects/{$project->id}/evaluations";

        $this->actingAs($evaluator)
            ->postJson($uri, $this->evaluationPayload($inactiveFramework, $inactiveCriteria))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['evaluation_framework_id']]);

        $this->postJson($uri, $this->evaluationPayload($otherYearFramework, $otherYearCriteria, [
            'evaluated_at' => '2027-11-01',
        ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['evaluation_framework_id']]);

        [$datedFramework, $datedCriteria] = $this->framework($this->fiscalYear, true, 'DATED');

        $this->postJson($uri, $this->evaluationPayload($datedFramework, $datedCriteria, [
            'evaluated_at' => '2028-01-01',
        ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['evaluated_at']]);

        $this->assertDatabaseCount('project_evaluations', 0);
    }

    public function test_draft_evaluation_can_be_updated_but_framework_evaluator_and_round_are_immutable(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $evaluator = $this->user('department_head', $this->department);
        $project = $this->project($evaluator, $this->fiscalYear);
        $evaluationId = $this->createEvaluation($evaluator, $project, $frameworkId, $criteria);

        $update = [
            'evaluated_at' => '2027-04-10',
            'comment' => 'Updated draft notes.',
            'scores' => [
                [
                    'evaluation_criterion_id' => $criteria[0]->id,
                    'score' => 5,
                    'comment' => 'Full outcome score.',
                ],
                [
                    'evaluation_criterion_id' => $criteria[1]->id,
                    'score' => 10,
                    'comment' => 'Full budget score.',
                ],
            ],
        ];

        $this->actingAs($evaluator)
            ->putJson("/api/v2/project-evaluations/{$evaluationId}", $update)
            ->assertOk()
            ->assertJsonPath('data.id', $evaluationId)
            ->assertJsonPath('data.round', 1)
            ->assertJsonPath('data.evaluator.id', $evaluator->id)
            ->assertJsonPath('data.total_score', '15.00')
            ->assertJsonPath('data.percentage', '100.00')
            ->assertJsonPath('data.weighted_percentage', '100.00');

        $immutableFields = $update + [
            'evaluation_framework_id' => $frameworkId,
            'evaluator_id' => $this->user('director')->id,
            'round' => 50,
        ];

        $this->putJson("/api/v2/project-evaluations/{$evaluationId}", $immutableFields)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors']);

        $evaluation = DB::table('project_evaluations')->find($evaluationId);
        $this->assertSame($frameworkId, (int) $evaluation->evaluation_framework_id);
        $this->assertSame($evaluator->id, (int) $evaluation->evaluator_id);
        $this->assertSame(1, (int) $evaluation->round);
    }

    public function test_used_framework_structure_is_immutable_while_display_metadata_can_change(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $evaluator = $this->user('department_head', $this->department);
        $director = $this->user('director', $this->department);
        $project = $this->project($evaluator, $this->fiscalYear);
        $this->createEvaluation($evaluator, $project, $frameworkId, $criteria);

        $structuralChange = [
            'name' => 'Renamed Framework',
            'description' => 'Attempted structural edit.',
            'fiscal_year_id' => $this->fiscalYear->id,
            'effective_from' => '2026-10-01',
            'effective_to' => '2027-09-30',
            'criteria' => [
                [
                    'name' => 'Outcome achievement',
                    'description' => 'Changed after use.',
                    'max_score' => 99,
                    'weight' => 60,
                    'sort_order' => 10,
                    'evaluation_method' => 'Changed method',
                    'evaluation_tools' => 'Changed tool',
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
            ],
        ];

        $this->actingAs($director)
            ->putJson("/api/v2/evaluation-frameworks/{$frameworkId}", $structuralChange)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['framework']]);

        $this->assertEqualsWithDelta(
            5.0,
            (float) DB::table('evaluation_criteria')->where('id', $criteria[0]->id)->value('max_score'),
            0.001,
        );

        $this->putJson("/api/v2/evaluation-frameworks/{$frameworkId}", [
            'name' => 'Renamed Framework',
            'description' => 'Display-only metadata may be corrected.',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Framework');

        $this->assertDatabaseHas('evaluation_frameworks', [
            'id' => $frameworkId,
            'name' => 'Renamed Framework',
            'description' => 'Display-only metadata may be corrected.',
        ]);
    }

    public function test_finalize_requires_an_explicit_manual_status_and_atomically_snapshots_scores(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $evaluator = $this->user('department_head', $this->department);
        $director = $this->user('director', $this->department);
        $project = $this->project($evaluator, $this->fiscalYear);
        $evaluationId = $this->createEvaluation($evaluator, $project, $frameworkId, $criteria);

        $this->actingAs($director)
            ->postJson("/api/v2/project-evaluations/{$evaluationId}/finalize", [
                'decision_note' => 'A result without an explicit status must not be stored.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['status']]);

        $this->assertDatabaseCount('project_evaluation_results', 0);
        $this->assertNull(DB::table('project_evaluations')->find($evaluationId)->finalized_at);
        $this->assertSame('pending', $project->fresh()->evaluationStatus->code);

        $finalized = $this->postJson("/api/v2/project-evaluations/{$evaluationId}/finalize", [
            'status' => 'passed',
            'decision_note' => 'Manually approved by the authorized finalizer.',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $evaluationId)
            ->assertJsonPath('data.result.status.code', 'passed')
            ->assertJsonPath('data.result.decision_note', 'Manually approved by the authorized finalizer.')
            ->assertJsonPath('data.result.finalized_by.id', $director->id);

        $result = DB::table('project_evaluation_results')
            ->where('project_evaluation_id', $evaluationId)
            ->first();

        $this->assertNotNull($result);
        $this->assertSame($project->id, (int) $result->project_id);
        $this->assertSame($frameworkId, (int) $result->evaluation_framework_id);
        $this->assertEqualsWithDelta(10.0, (float) $result->total_score, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $result->maximum_score, 0.001);
        $this->assertEqualsWithDelta(66.67, (float) $result->percentage, 0.001);
        $this->assertEqualsWithDelta(72.0, (float) $result->weighted_percentage, 0.001);
        $this->assertSame($director->id, (int) $result->finalized_by);
        $this->assertNotNull($result->finalized_at);
        $this->assertSame('passed', $project->fresh()->evaluationStatus->code);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'project_evaluation.finalized',
            'auditable_id' => $result->id,
            'user_id' => $director->id,
        ]);

        $snapshot = json_decode($result->scores_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $snapshot);
        $this->assertSame('Outcome achievement', $snapshot[0]['name']);
        $this->assertEqualsWithDelta(5.0, (float) $snapshot[0]['max_score'], 0.001);
        $this->assertEqualsWithDelta(4.0, (float) $snapshot[0]['score'], 0.001);

        $this->putJson("/api/v2/project-evaluations/{$evaluationId}", [
            'evaluated_at' => '2027-04-11',
            'comment' => 'A finalized evaluation is immutable.',
            'scores' => $this->scorePayload($criteria),
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->postJson("/api/v2/project-evaluations/{$evaluationId}/finalize", [
            'status' => 'failed',
            'decision_note' => 'A second result must not replace history.',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseCount('project_evaluation_results', 1);
        $this->assertSame('passed', $project->fresh()->evaluationStatus->code);
        $this->assertGreaterThan(0, (int) $finalized->json('data.result.id'));
    }

    public function test_perfect_scores_remain_pending_without_an_explicit_pass_fail_decision_and_dashboard_stays_compatible(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $evaluator = $this->user('department_head', $this->department);
        $director = $this->user('director', $this->department);
        $project = $this->project($evaluator, $this->fiscalYear);
        $perfect = $this->evaluationPayload($frameworkId, $criteria, [
            'scores' => [
                ['evaluation_criterion_id' => $criteria[0]->id, 'score' => 5, 'comment' => null],
                ['evaluation_criterion_id' => $criteria[1]->id, 'score' => 10, 'comment' => null],
            ],
        ]);

        $evaluationId = (int) $this->actingAs($evaluator)
            ->postJson("/api/v2/projects/{$project->id}/evaluations", $perfect)
            ->assertCreated()
            ->assertJsonPath('data.total_score', '15.00')
            ->assertJsonPath('data.percentage', '100.00')
            ->json('data.id');

        $this->assertSame('pending', $project->fresh()->evaluationStatus->code);

        $this->actingAs($director)
            ->getJson("/api/v2/dashboard?fiscal_year_id={$this->fiscalYear->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluation_status_counts.pending', 1)
            ->assertJsonPath('data.evaluation_status_counts.passed', 0)
            ->assertJsonPath('data.evaluation_status_counts.failed', 0);

        $this->postJson("/api/v2/project-evaluations/{$evaluationId}/finalize", [
            'status' => 'pending',
            'decision_note' => 'Scores recorded; no school-approved pass/fail rule was applied.',
        ])
            ->assertOk()
            ->assertJsonPath('data.result.status.code', 'pending');

        $this->assertSame('pending', $project->fresh()->evaluationStatus->code);

        $this->getJson("/api/v2/dashboard?fiscal_year_id={$this->fiscalYear->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluation_status_counts.pending', 1)
            ->assertJsonPath('data.evaluation_status_counts.passed', 0)
            ->assertJsonPath('data.evaluation_status_counts.failed', 0);
    }

    public function test_finalized_rounds_are_immutable_and_project_history_preserves_each_evaluation(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $evaluator = $this->user('department_head', $this->department);
        $director = $this->user('director', $this->department);
        $project = $this->project($evaluator, $this->fiscalYear);
        $firstId = $this->createEvaluation($evaluator, $project, $frameworkId, $criteria);

        $this->actingAs($director)
            ->postJson("/api/v2/project-evaluations/{$firstId}/finalize", [
                'status' => 'passed',
                'decision_note' => 'First finalized round.',
            ])
            ->assertOk();

        $firstBefore = (array) DB::table('project_evaluations')->find($firstId);

        $secondId = (int) $this->actingAs($evaluator)
            ->postJson(
                "/api/v2/projects/{$project->id}/evaluations",
                $this->evaluationPayload($frameworkId, $criteria, ['comment' => 'Second evaluation round.']),
            )
            ->assertCreated()
            ->assertJsonPath('data.round', 2)
            ->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertDatabaseCount('project_evaluations', 2);
        $this->assertSame($firstBefore, (array) DB::table('project_evaluations')->find($firstId));
        $this->assertSame(
            'passed',
            $project->fresh()->evaluationStatus->code,
            'Starting a new draft round must not erase the latest finalized status.',
        );

        $this->getJson("/api/v2/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.latest_evaluation.id', $firstId)
            ->assertJsonPath('data.latest_evaluation.result.status.code', 'passed');

        $history = $this->getJson("/api/v2/projects/{$project->id}/evaluations")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing([1, 2], $history->json('data.*.round'));

        $this->getJson("/api/v2/project-evaluations/{$firstId}")
            ->assertOk()
            ->assertJsonPath('data.id', $firstId)
            ->assertJsonPath('data.round', 1)
            ->assertJsonPath('data.result.status.code', 'passed');

        $this->assertNull(DB::table('project_evaluations')->find($secondId)->finalized_at);
    }

    public function test_locked_fiscal_year_is_read_only_for_create_update_and_finalize_but_history_remains_visible(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $evaluator = $this->user('department_head', $this->department);
        $director = $this->user('director', $this->department);
        $project = $this->project($evaluator, $this->fiscalYear);
        $evaluationId = $this->createEvaluation($evaluator, $project, $frameworkId, $criteria);

        $this->fiscalYear->update(['is_locked' => true]);

        $this->actingAs($evaluator)
            ->postJson(
                "/api/v2/projects/{$project->id}/evaluations",
                $this->evaluationPayload($frameworkId, $criteria),
            )
            ->assertForbidden();

        $this->putJson("/api/v2/project-evaluations/{$evaluationId}", [
            'evaluated_at' => '2027-04-15',
            'comment' => 'Blocked by fiscal-year lock.',
            'scores' => $this->scorePayload($criteria),
        ])
            ->assertForbidden();

        $this->actingAs($director)
            ->postJson("/api/v2/project-evaluations/{$evaluationId}/finalize", [
                'status' => 'passed',
                'decision_note' => 'Blocked by fiscal-year lock.',
            ])
            ->assertForbidden();

        $this->actingAs($evaluator)
            ->getJson("/api/v2/project-evaluations/{$evaluationId}")
            ->assertOk()
            ->assertJsonPath('data.id', $evaluationId);

        $this->getJson("/api/v2/projects/{$project->id}/evaluations")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseCount('project_evaluations', 1);
        $this->assertDatabaseCount('project_evaluation_results', 0);
        $this->assertSame('pending', $project->fresh()->evaluationStatus->code);
    }

    public function test_generic_project_api_cannot_write_evaluation_status_and_explicit_finalize_updates_dashboard(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $director = $this->user('director', $this->department);
        $project = $this->project($director, $this->fiscalYear);

        $this->actingAs($director)
            ->putJson("/api/v2/projects/{$project->id}", ['evaluation_status' => 'failed'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['evaluation_status']]);

        $this->assertSame('pending', $project->fresh()->evaluationStatus->code);

        $evaluationId = $this->createEvaluation($director, $project, $frameworkId, $criteria);

        $this->postJson("/api/v2/project-evaluations/{$evaluationId}/finalize", [
            'status' => 'failed',
            'decision_note' => 'Explicit manual decision.',
        ])->assertOk();

        $this->getJson("/api/v2/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluation_status.code', 'failed')
            ->assertJsonPath('data.latest_evaluation.id', $evaluationId)
            ->assertJsonPath('data.latest_evaluation.result.status.code', 'failed')
            ->assertJsonPath('data.latest_evaluation.evaluator.id', $director->id);

        $this->getJson("/api/v2/dashboard?fiscal_year_id={$this->fiscalYear->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluation_status_counts.pending', 0)
            ->assertJsonPath('data.evaluation_status_counts.passed', 0)
            ->assertJsonPath('data.evaluation_status_counts.failed', 1);

        $this->getJson("/api/v2/evaluation-projects?evaluation_status=failed&fiscal_year_id={$this->fiscalYear->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $project->id);

        $this->getJson("/api/v2/evaluation-projects?evaluation_status=pending&fiscal_year_id={$this->fiscalYear->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_latest_evaluation_follows_finalize_order_when_timestamps_are_equal(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $director = $this->user('director', $this->department);
        $project = $this->project($director, $this->fiscalYear);
        $firstEvaluationId = $this->createEvaluation($director, $project, $frameworkId, $criteria);
        $secondEvaluationId = $this->createEvaluation($director, $project, $frameworkId, $criteria);

        Carbon::setTestNow('2027-04-02 10:00:00');

        try {
            $this->postJson("/api/v2/project-evaluations/{$secondEvaluationId}/finalize", [
                'status' => 'passed',
                'decision_note' => 'Finalized first at the shared timestamp.',
            ])->assertOk();

            $this->postJson("/api/v2/project-evaluations/{$firstEvaluationId}/finalize", [
                'status' => 'failed',
                'decision_note' => 'Finalized last at the shared timestamp.',
            ])->assertOk();
        } finally {
            Carbon::setTestNow();
        }

        $this->getJson("/api/v2/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.evaluation_status.code', 'failed')
            ->assertJsonPath('data.latest_evaluation.id', $firstEvaluationId)
            ->assertJsonPath('data.latest_evaluation.result.status.code', 'failed');

        $this->getJson("/api/v2/evaluation-projects?fiscal_year_id={$this->fiscalYear->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $project->id)
            ->assertJsonPath('data.0.evaluation_status.code', 'failed')
            ->assertJsonPath('data.0.latest_evaluation.id', $firstEvaluationId)
            ->assertJsonPath('data.0.latest_evaluation.result.status.code', 'failed');
    }

    public function test_soft_deleting_a_finalized_project_preserves_evaluation_history_and_result(): void
    {
        [$frameworkId, $criteria] = $this->framework($this->fiscalYear, true);
        $director = $this->user('director', $this->department);
        $project = $this->project($director, $this->fiscalYear);
        $evaluationId = $this->createEvaluation($director, $project, $frameworkId, $criteria);

        $this->postJson("/api/v2/project-evaluations/{$evaluationId}/finalize", [
            'status' => 'passed',
            'decision_note' => 'Finalized before the project is archived.',
        ])->assertOk();

        $resultId = DB::table('project_evaluation_results')
            ->where('project_evaluation_id', $evaluationId)
            ->value('id');

        $this->deleteJson("/api/v2/projects/{$project->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('project_evaluations', ['id' => $evaluationId]);
        $this->assertDatabaseHas('project_evaluation_results', [
            'id' => $resultId,
            'project_id' => $project->id,
            'project_evaluation_id' => $evaluationId,
        ]);
    }

    /**
     * @return array{0: int, 1: array<int, object>}
     */
    private function framework(FiscalYear $fiscalYear, bool $active, string $code = 'SCHOOL-KPI'): array
    {
        $timestamp = now();
        $frameworkId = DB::table('evaluation_frameworks')->insertGetId([
            'code' => $code,
            'version' => '1.0',
            'name' => "{$code} Framework",
            'description' => 'Fixture framework using explicit school indicators.',
            'fiscal_year_id' => $fiscalYear->id,
            'effective_from' => $fiscalYear->start_date?->toDateString(),
            'effective_to' => $fiscalYear->end_date?->toDateString(),
            'is_active' => $active,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('evaluation_criteria')->insert([
            [
                'evaluation_framework_id' => $frameworkId,
                'name' => 'Outcome achievement',
                'description' => 'Compare actual outcomes with the stated target.',
                'max_score' => 5,
                'weight' => 60,
                'sort_order' => 10,
                'evaluation_method' => 'Review reported outcomes',
                'evaluation_tools' => 'Outcome checklist',
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'evaluation_framework_id' => $frameworkId,
                'name' => 'Budget stewardship',
                'description' => 'Review the appropriate use of project resources.',
                'max_score' => 10,
                'weight' => 40,
                'sort_order' => 20,
                'evaluation_method' => 'Document review',
                'evaluation_tools' => 'Budget evidence form',
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        return [
            $frameworkId,
            DB::table('evaluation_criteria')
                ->where('evaluation_framework_id', $frameworkId)
                ->orderBy('sort_order')
                ->get()
                ->all(),
        ];
    }

    private function user(string $roleCode, ?Department $department = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'department_id' => $department?->id,
            'is_active' => true,
        ]);
    }

    private function project(User $owner, FiscalYear $fiscalYear, array $overrides = []): Project
    {
        return Project::create(array_merge([
            'name' => 'Phase 4 Evaluation Project',
            'objective' => 'Verify evaluation workflow behavior.',
            'budget' => 1000,
            'actual_spent' => 0,
            'user_id' => $owner->id,
            'department_id' => $owner->department_id ?? $this->department->id,
            'project_category_id' => $this->category->id,
            'academic_year_id' => $this->academicYear->id,
            'fiscal_year_id' => $fiscalYear->id,
            'project_status_id' => ProjectStatus::query()->where('code', 'draft')->value('id'),
            'project_execution_status_id' => ProjectExecutionStatus::query()
                ->where('code', 'not_started')
                ->value('id'),
            'evaluation_status_id' => EvaluationStatus::query()
                ->where('code', 'pending')
                ->value('id'),
        ], $overrides));
    }

    /**
     * @param  array<int, object>  $criteria
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function evaluationPayload(int $frameworkId, array $criteria, array $overrides = []): array
    {
        return array_merge([
            'evaluation_framework_id' => $frameworkId,
            'evaluated_at' => '2027-04-01',
            'comment' => 'Evidence reviewed by the evaluator.',
            'scores' => $this->scorePayload($criteria),
        ], $overrides);
    }

    /**
     * @param  array<int, object>  $criteria
     * @return array<int, array<string, mixed>>
     */
    private function scorePayload(array $criteria): array
    {
        return [
            [
                'evaluation_criterion_id' => $criteria[0]->id,
                'score' => 4,
                'comment' => 'Most outcomes were achieved.',
            ],
            [
                'evaluation_criterion_id' => $criteria[1]->id,
                'score' => 6,
                'comment' => 'Budget evidence was adequate.',
            ],
        ];
    }

    /**
     * @param  array<int, object>  $criteria
     */
    private function createEvaluation(
        User $evaluator,
        Project $project,
        int $frameworkId,
        array $criteria,
    ): int {
        return (int) $this->actingAs($evaluator)
            ->postJson(
                "/api/v2/projects/{$project->id}/evaluations",
                $this->evaluationPayload($frameworkId, $criteria),
            )
            ->assertCreated()
            ->json('data.id');
    }
}
