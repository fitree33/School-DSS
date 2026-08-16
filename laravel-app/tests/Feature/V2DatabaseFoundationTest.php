<?php

namespace Tests\Feature;

use App\Models\DepartmentBudget;
use App\Models\FiscalYear;
use App\Models\SchoolBudget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class V2DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const HISTORY_MARKER = '[migration:v2-initial-status]';

    public function test_v2_budget_plan_and_status_schema_is_additive_and_seeded(): void
    {
        foreach ([
            'fiscal_years',
            'school_budgets',
            'department_budgets',
            'school_plans',
            'project_execution_statuses',
            'evaluation_statuses',
            'project_execution_status_histories',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing V2 table: {$table}");
        }

        $this->assertTrue(Schema::hasColumns('projects', [
            'fiscal_year_id',
            'school_plan_id',
            'project_execution_status_id',
            'evaluation_status_id',
            'key_points',
            'monitor_person',
            'evaluation_method',
            'evaluation_tools',
        ]));

        $this->assertSame(
            ['not_started', 'in_progress', 'completed'],
            DB::table('project_execution_statuses')->orderBy('sort_order')->pluck('code')->all()
        );
        $this->assertSame(
            ['ยังไม่ดำเนินการ', 'กำลังดำเนินการ', 'ดำเนินการแล้ว'],
            DB::table('project_execution_statuses')->orderBy('sort_order')->pluck('name')->all()
        );
        $this->assertSame(
            ['pending', 'passed', 'failed'],
            DB::table('evaluation_statuses')->orderBy('sort_order')->pluck('code')->all()
        );
        $this->assertSame(
            ['รอประเมิน', 'ผ่าน', 'ไม่ผ่าน'],
            DB::table('evaluation_statuses')->orderBy('sort_order')->pluck('name')->all()
        );

        $this->assertDatabaseMissing('project_statuses', ['code' => 'not_started']);

        foreach (['activities', 'sub_activities', 'project_activities', 'project_sub_activities'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Forbidden activity table exists: {$table}");
        }
    }

    public function test_department_budget_is_unallocated_by_default_and_tracks_allocator(): void
    {
        $fiscalYear = FiscalYear::create([
            'year' => 2570,
            'start_date' => '2026-10-01',
            'end_date' => '2027-09-30',
            'is_active' => true,
        ]);
        $schoolBudget = SchoolBudget::create([
            'fiscal_year_id' => $fiscalYear->id,
            'total_amount' => 500000,
        ]);
        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'ฝ่ายทดสอบงบประมาณ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $departmentBudget = DepartmentBudget::create([
            'school_budget_id' => $schoolBudget->id,
            'department_id' => $departmentId,
            'allocated_amount' => 150000,
        ])->refresh();

        $this->assertFalse($departmentBudget->is_allocated);
        $this->assertNull($departmentBudget->allocated_at);
        $this->assertNull($departmentBudget->allocated_by);
        $this->assertSame('150000.00', $departmentBudget->allocated_amount);

        $allocatorId = DB::table('users')->insertGetId([
            'name' => 'ผู้จัดสรรงบประมาณ',
            'email' => 'budget-allocator@example.test',
            'password' => 'not-used-by-this-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $departmentBudget->update([
            'is_allocated' => true,
            'allocated_at' => now(),
            'allocated_by' => $allocatorId,
        ]);
        $departmentBudget->refresh();

        $this->assertTrue($departmentBudget->is_allocated);
        $this->assertNotNull($departmentBudget->allocated_at);
        $this->assertSame($allocatorId, $departmentBudget->allocated_by);

        DB::table('users')->where('id', $allocatorId)->delete();

        $this->assertNull($departmentBudget->refresh()->allocated_by);
    }

    public function test_legacy_project_statuses_are_backfilled_without_being_replaced(): void
    {
        $legacyStatusIds = $this->createLegacyProjectStatuses([
            'draft',
            'approved',
            'in_progress',
            'completed',
            'archived',
        ]);
        $projectIds = $this->createLegacyProjects($legacyStatusIds);

        $migration = $this->backfillMigration();
        $migration->up();

        $executionStatusIds = DB::table('project_execution_statuses')->pluck('id', 'code');
        $pendingEvaluationStatusId = DB::table('evaluation_statuses')->where('code', 'pending')->value('id');
        $expectedExecutionCodes = [
            'draft' => 'not_started',
            'approved' => 'not_started',
            'in_progress' => 'in_progress',
            'completed' => 'completed',
            'archived' => 'not_started',
        ];

        foreach ($projectIds as $legacyCode => $projectId) {
            $project = DB::table('projects')->where('id', $projectId)->first();

            $this->assertSame($legacyStatusIds[$legacyCode], $project->project_status_id);
            $this->assertSame(
                $executionStatusIds[$expectedExecutionCodes[$legacyCode]],
                $project->project_execution_status_id
            );
            $this->assertSame($pendingEvaluationStatusId, $project->evaluation_status_id);
            $this->assertDatabaseHas('project_execution_status_histories', [
                'project_id' => $projectId,
                'from_status_id' => null,
                'to_status_id' => $executionStatusIds[$expectedExecutionCodes[$legacyCode]],
                'changed_by' => null,
                'comment' => self::HISTORY_MARKER,
            ]);
        }

        $this->assertSame(
            count($projectIds),
            DB::table('project_execution_status_histories')
                ->where('comment', self::HISTORY_MARKER)
                ->count()
        );

        $migration->up();

        $this->assertSame(
            count($projectIds),
            DB::table('project_execution_status_histories')
                ->where('comment', self::HISTORY_MARKER)
                ->count()
        );

        $migration->down();

        $this->assertSame(
            0,
            DB::table('project_execution_status_histories')
                ->where('comment', self::HISTORY_MARKER)
                ->count()
        );

        foreach ($projectIds as $legacyCode => $projectId) {
            $project = DB::table('projects')->where('id', $projectId)->first();

            $this->assertSame($legacyStatusIds[$legacyCode], $project->project_status_id);
            $this->assertNull($project->project_execution_status_id);
            $this->assertNull($project->evaluation_status_id);
        }
    }

    public function test_v2_migrations_roll_back_and_reapply_on_sqlite(): void
    {
        $migrationFiles = [
            '2026_08_17_000100_create_fiscal_years_table.php',
            '2026_08_17_000200_create_school_budgets_table.php',
            '2026_08_17_000300_create_department_budgets_table.php',
            '2026_08_17_000400_create_school_plans_table.php',
            '2026_08_17_000500_create_project_execution_statuses_table.php',
            '2026_08_17_000600_create_evaluation_statuses_table.php',
            '2026_08_17_000700_add_v2_foundation_fields_to_projects_table.php',
            '2026_08_17_000800_create_project_execution_status_histories_table.php',
            '2026_08_17_000900_backfill_project_v2_statuses.php',
        ];
        $migrations = array_map(
            fn (string $file) => $this->v2Migration($file),
            $migrationFiles
        );

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        $this->assertTrue(Schema::hasTable('projects'));
        $this->assertTrue(Schema::hasTable('project_statuses'));
        $this->assertFalse(Schema::hasTable('fiscal_years'));
        $this->assertFalse(Schema::hasTable('project_execution_status_histories'));
        $this->assertFalse(Schema::hasColumn('projects', 'fiscal_year_id'));
        $this->assertFalse(Schema::hasColumn('projects', 'project_execution_status_id'));
        $this->assertTrue(Schema::hasColumn('projects', 'project_status_id'));

        foreach ($migrations as $migration) {
            $migration->up();
        }

        $this->assertTrue(Schema::hasTable('fiscal_years'));
        $this->assertTrue(Schema::hasTable('project_execution_status_histories'));
        $this->assertTrue(Schema::hasColumn('projects', 'fiscal_year_id'));
        $this->assertTrue(Schema::hasColumn('projects', 'project_execution_status_id'));
        $this->assertTrue(Schema::hasColumn('projects', 'project_status_id'));
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, int>
     */
    private function createLegacyProjectStatuses(array $codes): array
    {
        $ids = [];

        foreach ($codes as $code) {
            DB::table('project_statuses')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => 'Legacy '.str_replace('_', ' ', $code),
                    'color' => 'slate',
                    'sort_order' => 10,
                    'is_terminal' => in_array($code, ['completed', 'archived'], true),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $ids[$code] = DB::table('project_statuses')->where('code', $code)->value('id');
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $legacyStatusIds
     * @return array<string, int>
     */
    private function createLegacyProjects(array $legacyStatusIds): array
    {
        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'ฝ่ายโครงการเดิม',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = DB::table('project_categories')->insertGetId([
            'name' => 'ประเภทโครงการเดิม',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $academicYearId = DB::table('academic_years')->insertGetId([
            'year' => 2569,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ownerId = DB::table('users')->insertGetId([
            'name' => 'เจ้าของโครงการเดิม',
            'email' => 'legacy-project-owner@example.test',
            'password' => 'not-used-by-this-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $projectIds = [];

        foreach ($legacyStatusIds as $code => $legacyStatusId) {
            $projectIds[$code] = DB::table('projects')->insertGetId([
                'name' => "Legacy project {$code}",
                'objective' => 'Preserve the legacy workflow status.',
                'budget' => 1000,
                'user_id' => $ownerId,
                'department_id' => $departmentId,
                'project_category_id' => $categoryId,
                'academic_year_id' => $academicYearId,
                'project_status_id' => $legacyStatusId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $projectIds;
    }

    private function backfillMigration(): Migration
    {
        return $this->v2Migration('2026_08_17_000900_backfill_project_v2_statuses.php');
    }

    private function v2Migration(string $file): Migration
    {
        return require database_path("migrations/{$file}");
    }
}
