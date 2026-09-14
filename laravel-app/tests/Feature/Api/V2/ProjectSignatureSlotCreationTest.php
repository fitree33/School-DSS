<?php

namespace Tests\Feature\Api\V2;

use App\Enums\DocumentImportProcessingStage;
use App\Enums\DocumentImportStatus;
use App\Enums\ProjectSignatureSlotCode;
use App\Http\Middleware\VerifyImportCallbackSignature;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectSignatureSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\TestCase;

class ProjectSignatureSlotCreationTest extends TestCase
{
    use BuildsPhaseFiveImports;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhaseFiveImports();
        Storage::fake('project-imports');
        Queue::fake();
    }

    #[DataProvider('createPaths')]
    public function test_each_create_path_initializes_four_empty_slots_with_one_audit(string $path): void
    {
        $actor = $this->phaseFiveUser();
        $response = $this->actingAs($actor)->postJson($path, $this->projectPayload());
        $path === '/api/v2/projects' ? $response->assertCreated() : $response->assertRedirect();

        $project = Project::query()->sole();
        $this->assertBlankSlots($project);
        $audit = AuditLog::query()->where('action', 'project_signature_slots.initialized')->sole();
        $this->assertSame($actor->id, (int) $audit->user_id);
        $this->assertSame(Project::class, $audit->auditable_type);
        $this->assertSame($project->id, (int) $audit->auditable_id);
        $this->assertSame($path === '/projects' ? 'legacy_create' : 'project_create', $audit->new_values['source']);
        $this->assertCount(4, $audit->new_values['slots']);
        $this->assertDatabaseCount('project_access', 1);
        $this->assertDatabaseCount('project_status_histories', 1);
        $this->assertDatabaseCount('project_execution_status_histories', 1);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public static function createPaths(): array
    {
        return ['V2' => ['/api/v2/projects'], 'legacy' => ['/projects']];
    }

    #[DataProvider('createAuditFailures')]
    public function test_create_and_slots_roll_back_if_initialization_or_later_audit_fails(string $path, string $action): void
    {
        $actor = $this->phaseFiveUser();
        $reject = true;
        AuditLog::creating(function (AuditLog $audit) use ($action, &$reject): void {
            if ($reject && $audit->action === $action) {
                throw new RuntimeException('Forced signature create audit failure.');
            }
        });
        try {
            $this->actingAs($actor)->postJson($path, $this->projectPayload())->assertInternalServerError();
        } finally {
            $reject = false;
        }

        $this->assertDatabaseCount('project_signature_slots', 0);
        $this->assertNoCanonicalImportWrites();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function createAuditFailures(): array
    {
        return [
            'V2 initialization audit' => ['/api/v2/projects', 'project_signature_slots.initialized'],
            'V2 late audit' => ['/api/v2/projects', 'project.created'],
            'legacy initialization audit' => ['/projects', 'project_signature_slots.initialized'],
            'legacy late audit' => ['/projects', 'project.created'],
        ];
    }

    public function test_fresh_confirm_initializes_slots_and_replay_preserves_the_exact_rows_and_audits(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $url = "/api/v2/imports/{$import->public_id}/confirm";
        $request = ['preview_revision_id' => $revision->id, 'idempotency_key' => 'signature-confirm'];

        $this->actingAs($owner)->postJson($url, $request)->assertCreated();
        $project = Project::query()->sole();
        $this->assertBlankSlots($project);
        $rows = $this->slotRows();
        $audits = $this->auditRows();
        $this->assertSame(1, AuditLog::query()->where('action', 'project_signature_slots.initialized')->count());
        $this->travel(1)->day();

        $this->postJson($url, $request)->assertOk()->assertJsonPath('data.project.id', $project->id);
        $this->assertSame($rows, $this->slotRows());
        $this->assertSame($audits, $this->auditRows());
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_documents', 1);
        $this->assertDatabaseCount('document_versions', 1);
    }

    #[DataProvider('confirmAuditFailures')]
    public function test_confirmation_rolls_back_slots_and_all_canonical_rows_on_audit_failure(string $action): void
    {
        $owner = $this->phaseFiveUser();
        [$import, $run, $revision] = $this->createReviewableImport($owner);
        $importBefore = $import->refresh()->getRawOriginal();
        $runBefore = $run->refresh()->getRawOriginal();
        $reject = true;
        AuditLog::creating(function (AuditLog $audit) use ($action, &$reject): void {
            if ($reject && $audit->action === $action) {
                throw new RuntimeException('Forced signature confirmation audit failure.');
            }
        });
        try {
            $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/confirm", [
                'preview_revision_id' => $revision->id, 'idempotency_key' => 'signature-atomic-confirm',
            ])->assertInternalServerError();
        } finally {
            $reject = false;
        }

        $this->assertDatabaseCount('project_signature_slots', 0);
        $this->assertNoCanonicalImportWrites();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame($importBefore, $import->refresh()->getRawOriginal());
        $this->assertSame($runBefore, $run->refresh()->getRawOriginal());
        Storage::disk('project-imports')->assertExists($import->storage_path);
    }

    public static function confirmAuditFailures(): array
    {
        return [
            'initialization audit' => ['project_signature_slots.initialized'],
            'late confirmation audit' => ['document_import.confirmed'],
        ];
    }

    public function test_preview_read_save_and_replay_do_not_create_slots(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $url = "/api/v2/imports/{$import->public_id}/preview-revisions";
        $request = [
            'base_revision_id' => $revision->id,
            'payload' => $this->validImportPreviewPayload(['name' => 'Reviewed signature project']),
            'idempotency_key' => 'signature-preview',
        ];
        $this->actingAs($owner)->getJson("/api/v2/imports/{$import->public_id}")->assertOk();
        $this->getJson($url)->assertOk();
        $created = $this->postJson($url, $request)->assertCreated();
        $this->postJson($url, $request)->assertOk()->assertJsonPath('data.id', $created->json('data.id'));

        $this->assertDatabaseCount('project_signature_slots', 0);
        $this->assertNoCanonicalImportWrites();
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'project_signature%')->count());
    }

    public function test_signed_callback_and_replay_do_not_initialize_slots(): void
    {
        $secret = 'phase-six-signature-callback-fixture';
        config()->set('project_imports.callback.current_secret', $secret);
        $owner = $this->phaseFiveUser();
        $import = $this->createDocumentImport($owner, DocumentImportStatus::Processing, [
            'processing_stage' => DocumentImportProcessingStage::WaitingForAi,
            'active_extraction_attempt' => 1,
        ]);
        $run = $this->createExtractionRun($import, overrides: ['dispatched_at' => now()]);
        $payload = array_diff_key($this->validImportPreviewPayload(), array_flip([
            'department_id', 'project_category_id', 'academic_year_id', 'fiscal_year_id', 'school_plan_id',
        ]));
        $body = json_encode([
            'status' => 'succeeded', 'payload' => $payload,
            'confidence' => ['name' => 0.98], 'warnings' => [], 'raw_result' => [],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $eventId = 'signature-callback';
        $signature = hash_hmac('sha256', VerifyImportCallbackSignature::canonicalMessage(
            $timestamp, $eventId, $run->public_id, hash('sha256', $body),
        ), $secret);
        $server = [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_IMPORT_TIMESTAMP' => $timestamp,
            'HTTP_X_IMPORT_EVENT_ID' => $eventId, 'HTTP_X_IMPORT_SIGNATURE' => 'sha256='.$signature,
        ];
        $url = "/api/v2/import-extraction-runs/{$run->public_id}/callback";

        $this->call('POST', $url, server: $server, content: $body)
            ->assertOk()->assertJsonPath('data.replayed', false);
        $this->call('POST', $url, server: $server, content: $body)
            ->assertOk()->assertJsonPath('data.replayed', true);

        $this->assertDatabaseCount('project_signature_slots', 0);
        $this->assertNoCanonicalImportWrites();
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'project_signature%')->count());
        $this->assertDatabaseCount('import_preview_revisions', 1);
    }

    private function projectPayload(): array
    {
        return [
            'name' => 'Signature create integration', 'objective' => 'Verify atomic slot initialization.',
            'responsible_person' => 'Project owner', 'budget' => '1500.00',
            'department_id' => $this->importDepartment->id,
            'project_category_id' => $this->importCategory->id,
            'academic_year_id' => $this->importAcademicYear->id,
            'fiscal_year_id' => $this->importFiscalYear->id,
        ];
    }

    private function assertBlankSlots(Project $project): void
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

    private function auditRows(): array
    {
        return DB::table('audit_logs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
}
