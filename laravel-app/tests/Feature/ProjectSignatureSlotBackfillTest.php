<?php

namespace Tests\Feature;

use App\Enums\ProjectSignatureSlotCode;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectSignatureSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\BuildsProjectSignatureSlots;
use Tests\TestCase;

class ProjectSignatureSlotBackfillTest extends TestCase
{
    use BuildsProjectSignatureSlots;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectSignatures();
    }

    public function test_dry_run_has_no_slot_audit_or_project_writes(): void
    {
        $project = $this->signatureProject($this->signatureUser(), initialize: false);
        $before = $project->getRawOriginal();
        $auditCount = AuditLog::query()->count();

        $this->artisan('projects:backfill-signature-slots')
            ->expectsOutput('Mode: dry-run (no writes)')
            ->expectsOutput("Project {$project->id}: would_initialize")
            ->assertSuccessful();

        $this->assertDatabaseCount('project_signature_slots', 0);
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertSame($before, $project->refresh()->getRawOriginal());
    }

    public function test_apply_initializes_four_blank_slots_and_replays_without_any_writes(): void
    {
        $project = $this->signatureProject($this->signatureUser(), initialize: false);

        $this->artisan('projects:backfill-signature-slots', ['--apply' => true])
            ->expectsOutput("Project {$project->id}: initialized")
            ->assertSuccessful();

        $this->assertCanonicalBlankSlots($project);
        $before = $this->slotRows();
        $audits = AuditLog::query()->get()->map->getRawOriginal()->all();
        $this->assertCount(1, $audits);
        $this->assertNull($audits[0]['user_id']);
        $this->assertNull($audits[0]['ip_address']);
        $this->assertNull($audits[0]['user_agent']);
        $this->travel(1)->day();

        $this->artisan('projects:backfill-signature-slots', ['--apply' => true])
            ->expectsOutput("Project {$project->id}: already_complete")
            ->assertSuccessful();
        $this->artisan('projects:backfill-signature-slots')
            ->expectsOutput("Project {$project->id}: already_complete")
            ->assertSuccessful();

        $this->assertSame($before, $this->slotRows());
        $this->assertSame($audits, AuditLog::query()->get()->map->getRawOriginal()->all());
    }

    public function test_partial_backfill_preserves_existing_assignment_revision_and_timestamps(): void
    {
        $owner = $this->signatureUser();
        $assignee = $this->signatureUser();
        $project = $this->signatureProject($owner, initialize: false);
        $existing = ProjectSignatureSlot::query()->create([
            'project_id' => $project->id,
            'slot_code' => ProjectSignatureSlotCode::ProjectProposer,
            'slot_no' => 1,
            'assigned_user_id' => $assignee->id,
            'assignment_revision' => 9,
            'assigned_by' => $owner->id,
            'assigned_at' => '2025-01-01 12:00:00',
            'created_at' => '2025-01-01 12:00:00',
            'updated_at' => '2025-01-02 12:00:00',
        ]);
        $before = $existing->refresh()->getRawOriginal();

        $this->artisan('projects:backfill-signature-slots')
            ->expectsOutput("Project {$project->id}: would_initialize")
            ->assertSuccessful();
        $this->assertDatabaseCount('project_signature_slots', 1);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->artisan('projects:backfill-signature-slots', ['--apply' => true])
            ->expectsOutput('would_initialize=0, initialized=1, already_complete=0, inserted_rows=3, failed=0')
            ->assertSuccessful();

        $this->assertDatabaseCount('project_signature_slots', 4);
        $this->assertSame($before, $existing->refresh()->getRawOriginal());
        $this->assertSame(3, ProjectSignatureSlot::query()->whereNull('assigned_user_id')->where('assignment_revision', 0)->count());
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_backfill_includes_soft_deleted_projects_and_respects_explicit_selection(): void
    {
        $owner = $this->signatureUser();
        $deleted = $this->signatureProject($owner, initialize: false);
        $untouched = $this->signatureProject($owner, initialize: false);
        $deleted->delete();
        $before = $deleted->getRawOriginal();

        $this->artisan('projects:backfill-signature-slots', [
            '--apply' => true, '--project-id' => [(string) $deleted->id, (string) $deleted->id], '--chunk' => 1,
        ])->assertSuccessful();

        $this->assertCanonicalBlankSlots($deleted);
        $this->assertDatabaseMissing('project_signature_slots', ['project_id' => $untouched->id]);
        $this->assertSame($before, Project::withTrashed()->findOrFail($deleted->id)->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_missing_selected_target_reports_partial_completion_and_failure(): void
    {
        $project = $this->signatureProject($this->signatureUser(), initialize: false);

        $this->artisan('projects:backfill-signature-slots', [
            '--apply' => true, '--project-id' => [(string) $project->id, '999999'], '--chunk' => 1,
        ])
            ->expectsOutput("Project {$project->id}: initialized")
            ->expectsOutput('Project 999999: failed (project_not_found)')
            ->expectsOutput('would_initialize=0, initialized=1, already_complete=0, inserted_rows=4, failed=1')
            ->assertFailed();

        $this->assertCanonicalBlankSlots($project);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_arguments_fail_before_any_write(array $arguments): void
    {
        $this->signatureProject($this->signatureUser(), initialize: false);

        $this->artisan('projects:backfill-signature-slots', ['--apply' => true] + $arguments)->assertFailed();

        $this->assertDatabaseCount('project_signature_slots', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidArguments(): array
    {
        return [
            'zero id' => [['--project-id' => ['0']]],
            'negative id' => [['--project-id' => ['-1']]],
            'noninteger id' => [['--project-id' => ['1.5']]],
            'overflow id' => [['--project-id' => ['999999999999999999999999']]],
            'zero chunk' => [['--chunk' => 0]],
            'oversized chunk' => [['--chunk' => 1001]],
            'noninteger chunk' => [['--chunk' => '1.5']],
        ];
    }

    public function test_audit_failure_rolls_back_only_affected_project_and_hides_exception_details(): void
    {
        $owner = $this->signatureUser();
        $failed = $this->signatureProject($owner, initialize: false);
        $successful = $this->signatureProject($owner, initialize: false);
        $reject = true;
        AuditLog::creating(function (AuditLog $audit) use ($failed, &$reject): void {
            if ($reject && (int) $audit->auditable_id === (int) $failed->id) {
                throw new RuntimeException('private-password database-host filesystem-path');
            }
        });

        try {
            $this->assertSame(1, Artisan::call('projects:backfill-signature-slots', ['--apply' => true, '--chunk' => 1]));
            $output = Artisan::output();
        } finally {
            $reject = false;
        }

        $this->assertStringContainsString("Project {$failed->id}: failed (project_signature_backfill_failed)", $output);
        $this->assertStringNotContainsString('private-password', $output);
        $this->assertStringNotContainsString('database-host', $output);
        $this->assertDatabaseMissing('project_signature_slots', ['project_id' => $failed->id]);
        $this->assertCanonicalBlankSlots($successful);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_unique_retry_rolls_back_partial_rows_before_reloading_the_project(): void
    {
        $project = $this->signatureProject($this->signatureUser(), initialize: false);
        $conflict = true;
        ProjectSignatureSlot::creating(function (ProjectSignatureSlot $slot) use (&$conflict): void {
            if ($conflict && (int) $slot->slot_no === 2) {
                $conflict = false;
                $first = (array) DB::table('project_signature_slots')->where('project_id', $slot->project_id)->first();
                unset($first['id']);
                DB::table('project_signature_slots')->insert($first);
            }
        });

        $this->artisan('projects:backfill-signature-slots', ['--apply' => true])->assertSuccessful();

        $this->assertFalse($conflict);
        $this->assertCanonicalBlankSlots($project);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_existing_anomaly_is_reported_without_silent_repair(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Legacy corruption fixture uses SQLite ignore_check_constraints.');
        }

        $project = $this->signatureProject($this->signatureUser());
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('project_signature_slots')->where('project_id', $project->id)->where('slot_no', 1)
                ->update(['assignment_revision' => -1]);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $before = $this->slotRows();
        $audits = AuditLog::query()->count();

        $this->artisan('projects:backfill-signature-slots')->assertFailed();
        $this->artisan('projects:backfill-signature-slots', ['--apply' => true])->assertFailed();

        $this->assertSame($before, $this->slotRows());
        $this->assertDatabaseCount('audit_logs', $audits);
    }

    private function assertCanonicalBlankSlots(Project $project): void
    {
        $this->assertSame(4, ProjectSignatureSlot::query()->where('project_id', $project->id)->count());
        foreach (ProjectSignatureSlotCode::cases() as $code) {
            $this->assertDatabaseHas('project_signature_slots', [
                'project_id' => $project->id, 'slot_code' => $code->value, 'slot_no' => $code->slotNo(),
                'assigned_user_id' => null, 'assignment_revision' => 0, 'assigned_by' => null, 'assigned_at' => null,
            ]);
        }
    }

    private function slotRows(): array
    {
        return DB::table('project_signature_slots')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
}
