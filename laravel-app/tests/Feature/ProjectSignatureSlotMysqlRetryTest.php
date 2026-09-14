<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillProjectSignatureSlots;
use App\Enums\ProjectSignatureSlotCode;
use App\Models\AuditLog;
use App\Models\ProjectSignatureSlot;
use App\Services\Projects\ProjectSignatureSlotService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Feature\Concerns\BuildsProjectSignatureSlots;
use Tests\TestCase;

class ProjectSignatureSlotMysqlRetryTest extends TestCase
{
    use BuildsProjectSignatureSlots;
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the guarded disposable MySQL 8.4.11 instance.');
        }

        // Identify the server before RefreshDatabase can issue any DDL.
        $server = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS database_name, @@port AS port, @@datadir AS data_directory, @@bind_address AS bind_address');
        $expected = realpath(base_path('../.foundation-runtime/phase5-mysql/data'));
        $actual = realpath($server->data_directory);
        $normalize = static fn (string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));

        $this->assertSame('8.4.11', $server->version);
        $this->assertSame('school_dss_foundation_test', $server->database_name);
        $this->assertSame(33084, (int) $server->port);
        $this->assertSame('127.0.0.1', $server->bind_address);
        $this->assertNotFalse($expected);
        $this->assertNotFalse($actual);
        $this->assertSame($normalize($expected), $normalize($actual));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectSignatures();
    }

    public function test_migrated_checks_are_enforced_and_reject_mapping_and_assignment_violations(): void
    {
        $checks = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', 'school_dss_foundation_test')
            ->where('table_name', 'project_signature_slots')->where('constraint_type', 'CHECK')
            ->orderBy('constraint_name')->get(['enforced as check_enforced', 'constraint_name as check_name'])
            ->pluck('check_enforced', 'check_name')->all();
        $this->assertSame([
            'project_signature_slots_assignment_check' => 'YES',
            'project_signature_slots_mapping_check' => 'YES',
            'project_signature_slots_revision_check' => 'YES',
        ], $checks);

        $owner = $this->signatureUser();
        $project = $this->signatureProject($owner, initialize: false);
        $valid = [
            'project_id' => $project->id, 'slot_code' => 'project_proposer', 'slot_no' => 1,
            'assigned_user_id' => null, 'assignment_revision' => 0, 'assigned_by' => null,
            'assigned_at' => null, 'created_at' => '2026-09-01 01:02:03', 'updated_at' => '2026-09-01 01:02:03',
        ];
        $violations = [
            ['mapping', ['slot_code' => 'proposer']],
            ['mapping', ['slot_code' => 'PROJECT_PROPOSER']],
            ['mapping', ['slot_code' => 'project_proposer ']],
            ['mapping', ['slot_no' => 2]],
            ['mapping', ['slot_no' => 0]],
            ['mapping', ['slot_no' => 5]],
            ['assignment', ['assigned_user_id' => $owner->id]],
            ['assignment', ['assigned_by' => $owner->id]],
            ['assignment', ['assigned_at' => '2026-09-01 01:02:03']],
            ['assignment', [
                'assigned_user_id' => $owner->id, 'assigned_by' => $owner->id,
                'assigned_at' => '2026-09-01 01:02:03', 'assignment_revision' => 0,
            ]],
        ];

        foreach ($violations as [$constraint, $overrides]) {
            $exception = $this->queryFailure(fn () => DB::table('project_signature_slots')->insert(array_replace($valid, $overrides)));

            $this->assertSame(3819, (int) $exception->errorInfo[1]);
            $this->assertSame("Check constraint 'project_signature_slots_{$constraint}_check' is violated.", $exception->errorInfo[2]);
            $this->assertFalse($this->matchesSlotDuplicate($exception));
            $this->assertDatabaseCount('project_signature_slots', 0);
        }

        DB::table('project_signature_slots')->insert($valid);
        $this->assertDatabaseCount('project_signature_slots', 1);
    }

    public function test_real_foreign_keys_restrict_parent_delete_and_update_with_exact_constraint_names(): void
    {
        $project = $this->signatureProject($this->signatureUser(), initialize: false);
        $assignee = $this->signatureUser();
        $assigner = $this->signatureUser('director');
        $id = DB::table('project_signature_slots')->insertGetId([
            'project_id' => $project->id, 'slot_code' => 'project_proposer', 'slot_no' => 1,
            'assigned_user_id' => $assignee->id, 'assignment_revision' => 1, 'assigned_by' => $assigner->id,
            'assigned_at' => '2026-09-01 01:02:03', 'created_at' => '2026-09-01 01:02:03', 'updated_at' => '2026-09-01 01:02:03',
        ]);
        $before = (array) DB::table('project_signature_slots')->find($id);

        foreach ([
            [$project, 'project_fk', 'project_id'],
            [$assignee, 'assignee_fk', 'assigned_user_id'],
            [$assigner, 'assigner_fk', 'assigned_by'],
        ] as [$parent, $constraint, $column]) {
            foreach (['delete', 'update'] as $operation) {
                $exception = $this->queryFailure(function () use ($parent, $operation): void {
                    $query = DB::table($parent->getTable())->where('id', $parent->id);
                    if ($operation === 'delete') {
                        $query->delete();
                    } else {
                        $query->update(['id' => 999999999]);
                    }
                });

                $this->assertSame('23000', $exception->errorInfo[0]);
                $this->assertSame(1451, (int) $exception->errorInfo[1]);
                $this->assertStringContainsString("CONSTRAINT `project_signature_slots_{$constraint}` FOREIGN KEY (`{$column}`)", $exception->errorInfo[2]);
                $this->assertFalse($this->matchesSlotDuplicate($exception));
                $this->assertDatabaseHas($parent->getTable(), ['id' => $parent->id]);
                $this->assertDatabaseMissing($parent->getTable(), ['id' => 999999999]);
                $this->assertSame($before, (array) DB::table('project_signature_slots')->find($id));
            }
        }
    }

    public function test_backfill_rejects_legacy_anomaly_in_temporary_fixture_without_changing_migrated_table(): void
    {
        $project = $this->signatureProject($this->signatureUser());
        $projectBefore = $project->getRawOriginal();
        $rows = DB::table('project_signature_slots')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $audits = AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all();

        // A connection-local legacy fixture can contain a malformed mapping.
        // Never disable or alter constraints on the migrated InnoDB table.
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE project_signature_slots (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                slot_code VARCHAR(32) NOT NULL,
                slot_no TINYINT UNSIGNED NOT NULL,
                assigned_user_id BIGINT UNSIGNED NULL,
                assignment_revision INT UNSIGNED NOT NULL DEFAULT 0,
                assigned_by BIGINT UNSIGNED NULL,
                assigned_at DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                CONSTRAINT project_signature_slots_project_code_unique UNIQUE (project_id, slot_code),
                CONSTRAINT project_signature_slots_project_no_unique UNIQUE (project_id, slot_no)
            ) ENGINE=InnoDB
            SQL);

        try {
            $malformed = $rows;
            $malformed[0]['slot_code'] = 'PROJECT_PROPOSER';
            DB::table('project_signature_slots')->insert($malformed);
            $before = DB::table('project_signature_slots')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

            foreach ([false, true] as $apply) {
                $this->artisan('projects:backfill-signature-slots', ['--apply' => $apply])
                    ->expectsOutput("Project {$project->id}: failed (signature_slots_inconsistent)")
                    ->expectsOutput('would_initialize=0, initialized=0, already_complete=0, inserted_rows=0, failed=1')
                    ->assertFailed();

                $this->assertSame($before, DB::table('project_signature_slots')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
                $this->assertSame($audits, AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all());
                $this->assertSame($projectBefore, $project->refresh()->getRawOriginal());
            }
        } finally {
            DB::statement('DROP TEMPORARY TABLE project_signature_slots');
        }

        $this->assertSame($rows, DB::table('project_signature_slots')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
    }

    #[DataProvider('knownUniqueIndexes')]
    public function test_exact_migration_index_names_match_real_mysql_duplicate_errors(string $index, array $duplicate): void
    {
        $columns = DB::table('information_schema.statistics')
            ->where('table_schema', 'school_dss_foundation_test')
            ->where('table_name', 'project_signature_slots')->where('index_name', $index)
            ->orderBy('seq_in_index')->get(['column_name as index_column', 'non_unique as is_non_unique']);
        $this->assertSame(['project_id', str_contains($index, '_code_') ? 'slot_code' : 'slot_no'], $columns->pluck('index_column')->all());
        $this->assertSame([0, 0], $columns->pluck('is_non_unique')->map(fn ($value): int => (int) $value)->all());

        // Matcher-only probe: a connection-local temporary table shadows the
        // migrated table, so each unique index can fail independently of CHECK.
        // The production table and its constraints remain untouched.
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE project_signature_slots (
                project_id BIGINT UNSIGNED NOT NULL,
                slot_code VARCHAR(32) NOT NULL,
                slot_no TINYINT UNSIGNED NOT NULL,
                CONSTRAINT project_signature_slots_project_code_unique UNIQUE (project_id, slot_code),
                CONSTRAINT project_signature_slots_project_no_unique UNIQUE (project_id, slot_no)
            ) ENGINE=InnoDB
            SQL);

        try {
            DB::table('project_signature_slots')->insert(['project_id' => 1, 'slot_code' => 'project_proposer', 'slot_no' => 1]);
            $exception = $this->queryFailure(fn () => DB::table('project_signature_slots')->insert($duplicate));

            $this->assertSame('23000', $exception->errorInfo[0]);
            $this->assertSame(1062, (int) $exception->errorInfo[1]);
            $this->assertStringEndsWith("for key 'project_signature_slots.{$index}'", $exception->errorInfo[2]);
            $this->assertTrue($this->matchesSlotDuplicate($exception));
        } finally {
            DB::statement('DROP TEMPORARY TABLE project_signature_slots');
        }

        $this->assertDatabaseCount('project_signature_slots', 0);
    }

    public static function knownUniqueIndexes(): array
    {
        return [
            'project and code' => ['project_signature_slots_project_code_unique', ['project_id' => 1, 'slot_code' => 'project_proposer', 'slot_no' => 2]],
            'project and number' => ['project_signature_slots_project_no_unique', ['project_id' => 1, 'slot_code' => 'related_approver', 'slot_no' => 1]],
        ];
    }

    public function test_known_duplicate_retries_after_rolling_back_partial_slots_and_rereads_project(): void
    {
        $project = $this->signatureProject($this->signatureUser(), initialize: false);
        $firstSlotAttempts = 0;
        $conflict = null;
        $enabled = true;
        $transactionLevel = DB::transactionLevel();
        ProjectSignatureSlot::creating(function (ProjectSignatureSlot $slot) use ($project, &$firstSlotAttempts, &$conflict, &$enabled): void {
            if (! $enabled || (int) $slot->project_id !== (int) $project->id) {
                return;
            }
            if ((int) $slot->slot_no === 1) {
                $firstSlotAttempts++;
            }
            if ((int) $slot->slot_no === 2 && $conflict === null) {
                $row = (array) DB::table('project_signature_slots')->where('project_id', $project->id)->first();
                unset($row['id']);
                $conflict = $this->queryFailure(fn () => DB::table('project_signature_slots')->insert($row));
                throw $conflict;
            }
        });

        try {
            $this->artisan('projects:backfill-signature-slots', ['--apply' => true])->assertSuccessful();
        } finally {
            $enabled = false;
        }

        $this->assertInstanceOf(QueryException::class, $conflict);
        $this->assertTrue($this->matchesSlotDuplicate($conflict));
        $this->assertSame(2, $firstSlotAttempts);
        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertSame(array_map(fn (ProjectSignatureSlotCode $code): string => $code->value, ProjectSignatureSlotCode::cases()), DB::table('project_signature_slots')->where('project_id', $project->id)->orderBy('slot_no')->pluck('slot_code')->all());
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame('project_signature_slots.backfilled', AuditLog::query()->sole()->action);
    }

    public function test_known_duplicate_is_bounded_to_three_attempts_and_reports_failure_without_residue(): void
    {
        $project = $this->signatureProject($this->signatureUser(), initialize: false);
        $attempts = 0;
        $enabled = true;
        $transactionLevel = DB::transactionLevel();
        ProjectSignatureSlot::creating(function (ProjectSignatureSlot $slot) use ($project, &$attempts, &$enabled): void {
            if ($enabled && (int) $slot->project_id === (int) $project->id && (int) $slot->slot_no === 2) {
                $attempts++;
                $row = (array) DB::table('project_signature_slots')->where('project_id', $project->id)->first();
                unset($row['id']);
                DB::table('project_signature_slots')->insert($row);
            }
        });

        try {
            $this->assertSame(1, Artisan::call('projects:backfill-signature-slots', ['--apply' => true]));
            $output = Artisan::output();
        } finally {
            $enabled = false;
        }

        $this->assertSame(3, $attempts);
        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertStringContainsString("Project {$project->id}: failed (project_signature_backfill_failed)", $output);
        $this->assertStringNotContainsString('Duplicate entry', $output);
        $this->assertDatabaseCount('project_signature_slots', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('unknownIntegrityViolations')]
    public function test_real_unknown_integrity_violation_is_thrown_unchanged_without_retry(string $kind, int $expectedDriverCode): void
    {
        $owner = $this->signatureUser(overrides: ['email' => 'project_signature_slots_project_code_unique@example.test']);
        $project = $this->signatureProject($owner, initialize: false);
        $attempts = 0;
        $caught = null;
        $enabled = true;
        $transactionLevel = DB::transactionLevel();
        ProjectSignatureSlot::creating(function (ProjectSignatureSlot $slot) use ($kind, $owner, $project, &$attempts, &$caught, &$enabled): void {
            if (! $enabled || (int) $slot->project_id !== (int) $project->id || (int) $slot->slot_no !== 2) {
                return;
            }

            $attempts++;
            $row = (array) DB::table('project_signature_slots')->where('project_id', $project->id)->first();
            if ($kind === 'primary') {
                $caught = $this->queryFailure(fn () => DB::table('project_signature_slots')->insert($row));
            } elseif ($kind === 'other unique containing known index in value') {
                $user = (array) DB::table('users')->where('id', $owner->id)->first();
                unset($user['id']);
                $caught = $this->queryFailure(fn () => DB::table('users')->insert($user));
            } else {
                unset($row['id']);
                $row['slot_code'] = 'related_approver';
                $row['slot_no'] = 2;
                if ($kind === 'foreign key') {
                    $row['project_id'] = 999999999;
                } else {
                    $row['slot_no'] = 5;
                }
                $caught = $this->queryFailure(fn () => DB::table('project_signature_slots')->insert($row));
            }
            throw $caught;
        });

        try {
            $thrown = $this->queryFailure(fn () => (new ReflectionMethod(BackfillProjectSignatureSlots::class, 'initializeProject'))
                ->invoke(new BackfillProjectSignatureSlots, app(ProjectSignatureSlotService::class), (int) $project->id, 'mysql-negative-retry'));
        } finally {
            $enabled = false;
        }

        $this->assertSame($caught, $thrown);
        $this->assertSame($expectedDriverCode, (int) $thrown->errorInfo[1]);
        $this->assertFalse($this->matchesSlotDuplicate($thrown));
        $this->assertSame(1, $attempts);
        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertDatabaseCount('project_signature_slots', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function unknownIntegrityViolations(): array
    {
        return [
            'slot primary key' => ['primary', 1062],
            'other unique and misleading value' => ['other unique containing known index in value', 1062],
            'project foreign key' => ['foreign key', 1452],
            'canonical mapping check' => ['check', 3819],
        ];
    }

    #[DataProvider('unrelatedUniqueIndexes')]
    public function test_real_duplicate_with_similar_index_or_other_table_is_not_recognized(string $table, string $index): void
    {
        // Both identifiers come only from this static provider.
        DB::statement("CREATE TEMPORARY TABLE `{$table}` (value VARCHAR(100) NOT NULL, CONSTRAINT `{$index}` UNIQUE (value)) ENGINE=InnoDB");

        try {
            $value = "misleading ' for key 'project_signature_slots_project_code_unique";
            DB::table($table)->insert(['value' => $value]);
            $exception = $this->queryFailure(fn () => DB::table($table)->insert(['value' => $value]));

            $this->assertSame(1062, (int) $exception->errorInfo[1]);
            $this->assertFalse($this->matchesSlotDuplicate($exception));
        } finally {
            DB::statement("DROP TEMPORARY TABLE `{$table}`");
        }
    }

    public static function unrelatedUniqueIndexes(): array
    {
        return [
            'index suffix' => ['project_signature_slots', 'project_signature_slots_project_code_unique_suffix'],
            'old mismatched name' => ['project_signature_slots', 'pss_project_code_unique'],
            'same index on other table' => ['signature_retry_other_table', 'project_signature_slots_project_code_unique'],
        ];
    }

    private function queryFailure(callable $query): QueryException
    {
        try {
            $query();
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Expected a real MySQL integrity violation.');
    }

    private function matchesSlotDuplicate(QueryException $exception): bool
    {
        return (new ReflectionMethod(BackfillProjectSignatureSlots::class, 'isSlotUniqueViolation'))
            ->invoke(new BackfillProjectSignatureSlots, $exception);
    }
}
