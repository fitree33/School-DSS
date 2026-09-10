<?php

namespace Tests\Feature\Api\V2;

use App\Enums\AiExtractionRunStatus;
use App\Enums\DocumentImportStatus;
use App\Jobs\ProcessDocumentImport;
use App\Models\DocumentImport;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\TestCase;

class DocumentImportApiTest extends TestCase
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

    public function test_phase_five_import_routes_are_registered_with_public_identifier_binding(): void
    {
        foreach ([
            'api.v2.imports.options',
            'api.v2.imports.index',
            'api.v2.imports.store',
            'api.v2.imports.show',
            'api.v2.imports.original',
            'api.v2.imports.retry',
            'api.v2.imports.preview-revisions.index',
            'api.v2.imports.preview-revisions.store',
            'api.v2.imports.confirm',
            'api.v2.import-extraction-runs.callback',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), "Missing Phase 5 route [{$routeName}].");
        }

        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);

        $this->actingAs($owner)
            ->getJson("/api/v2/imports/{$documentImport->id}")
            ->assertNotFound();
        $this->getJson("/api/v2/imports/{$documentImport->public_id}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $documentImport->public_id);
    }

    public function test_import_endpoints_require_an_active_authenticated_user(): void
    {
        $this->getJson('/api/v2/imports')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v2/imports/options')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $inactive = $this->phaseFiveUser(overrides: ['is_active' => false]);

        $this->actingAs($inactive)
            ->getJson('/api/v2/imports')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    }

    public function test_upload_stores_an_immutable_pdf_and_dispatches_only_the_processing_job(): void
    {
        $owner = $this->phaseFiveUser();
        $contents = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
        $file = UploadedFile::fake()->createWithContent('school-project.pdf', $contents);

        $response = $this->actingAs($owner)
            ->post('/api/v2/imports', ['document' => $file], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('data.status', 'uploaded')
            ->assertJsonPath('data.original.name', 'school-project.pdf')
            ->assertJsonPath('data.original.mime_type', 'application/pdf')
            ->assertJsonPath('data.original.size_bytes', strlen($contents))
            ->assertJsonPath('data.original.sha256', hash('sha256', $contents))
            ->assertJsonPath('data.latest_run', null)
            ->assertJsonPath('data.current_preview', null);

        $documentImport = DocumentImport::query()->sole();

        $this->assertStringContainsString($documentImport->public_id, $response->headers->get('Location'));
        $this->assertSame($owner->id, $documentImport->uploaded_by);
        $this->assertSame($owner->department_id, $documentImport->uploader_department_id);
        Storage::disk('project-imports')->assertExists($documentImport->storage_path);
        $this->assertSame($contents, Storage::disk('project-imports')->get($documentImport->storage_path));
        Queue::assertPushed(
            ProcessDocumentImport::class,
            fn (ProcessDocumentImport $job): bool => $job->documentImportId === $documentImport->id
                && $job->queue === 'document-imports',
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_import.uploaded',
            'auditable_type' => DocumentImport::class,
            'auditable_id' => $documentImport->id,
            'user_id' => $owner->id,
        ]);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_upload_rejects_wrong_content_extension_and_exact_byte_limit_without_side_effects(): void
    {
        $owner = $this->phaseFiveUser();

        $this->actingAs($owner)
            ->post('/api/v2/imports', [
                'document' => UploadedFile::fake()->createWithContent('not-a-pdf.pdf', 'plain text'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['document']]);

        $this->post('/api/v2/imports', [
            'document' => UploadedFile::fake()->createWithContent(
                'wrong-extension.txt',
                "%PDF-1.4\n%%EOF\n",
            ),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['document']]);

        config()->set('project_imports.upload.max_bytes', 16);
        $this->post('/api/v2/imports', [
            'document' => UploadedFile::fake()->createWithContent(
                'too-large.pdf',
                "%PDF-1.4\n".str_repeat('x', 32),
            ),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['document']]);

        $this->assertDatabaseCount('document_imports', 0);
        Queue::assertNothingPushed();
        $this->assertNoCanonicalImportWrites();
    }

    public function test_index_show_and_options_obey_owner_department_and_global_visibility(): void
    {
        $owner = $this->phaseFiveUser();
        $sameDepartmentOwner = $this->phaseFiveUser();
        $otherOwner = $this->phaseFiveUser(department: $this->otherImportDepartment);
        $departmentHead = $this->phaseFiveUser('department_head');
        $director = $this->phaseFiveUser('director');

        $ownImport = $this->createDocumentImport($owner);
        $departmentImport = $this->createDocumentImport(
            $sameDepartmentOwner,
            DocumentImportStatus::Failed,
            ['failure_code' => 'PDF_INVALID'],
        );
        $otherImport = $this->createDocumentImport($otherOwner);

        $ownerResponse = $this->actingAs($owner)
            ->getJson('/api/v2/imports')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->assertSame([$ownImport->id], $ownerResponse->json('data.*.id'));

        $departmentResponse = $this->actingAs($departmentHead)
            ->getJson('/api/v2/imports?status=failed&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.id', $departmentImport->id);
        $this->assertNotContains($otherImport->id, $departmentResponse->json('data.*.id'));

        $this->actingAs($departmentHead)
            ->getJson("/api/v2/imports/{$otherImport->public_id}")
            ->assertForbidden();

        $this->actingAs($director)
            ->getJson('/api/v2/imports')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v2/imports/options')
            ->assertOk()
            ->assertJsonPath('data.constraints.max_bytes', 10 * 1024 * 1024)
            ->assertJsonPath('data.constraints.accepted_mime_types.0', 'application/pdf');

        $this->getJson('/api/v2/imports?status=unknown&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['status', 'per_page']]);
    }

    public function test_import_options_support_read_only_import_permissions_and_keep_project_option_contract(): void
    {
        $viewerRole = Role::query()->create(['code' => 'import_viewer', 'name' => 'Import viewer']);
        $viewerRole->permissions()->attach(Permission::query()->where('code', 'imports.view_all')->value('id'));
        $viewer = $this->phaseFiveUser(overrides: ['role_id' => $viewerRole->id]);
        $this->importFiscalYear->update(['is_locked' => true]);

        $this->assertFalse($viewer->hasPermission('projects.create'));
        $response = $this->actingAs($viewer)->getJson('/api/v2/imports/options')
            ->assertOk()
            ->assertJsonPath('data.project_options.fiscal_years.0.id', $this->importFiscalYear->id)
            ->assertJsonPath('data.project_options.fiscal_years.0.is_locked', true)
            ->assertJsonPath('data.project_options.school_plans.0.fiscal_year_id', $this->importFiscalYear->id)
            ->assertJsonPath('data.constraints.accepted_mime_types.0', 'application/pdf');
        $projectOptions = $this->getJson('/api/v2/project-options')->assertOk();
        $this->assertSame($projectOptions->json('data'), $response->json('data.project_options'));

        $this->post('/api/v2/imports', [
            'document' => UploadedFile::fake()->createWithContent('valid.pdf', "%PDF-1.4\n%%EOF\n"),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $noPermissionsRole = Role::query()->create(['code' => 'no_imports', 'name' => 'No imports']);
        $noPermissions = $this->phaseFiveUser(overrides: ['role_id' => $noPermissionsRole->id]);
        $this->actingAs($noPermissions)->getJson('/api/v2/imports/options')->assertForbidden();
        $this->assertNoCanonicalImportWrites();
        Queue::assertNothingPushed();
    }

    public function test_import_only_viewer_can_read_locked_year_preview_and_history_without_mutation_abilities(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $failedImport = $this->createDocumentImport($owner, DocumentImportStatus::Failed);
        $this->importFiscalYear->update(['is_locked' => true]);
        $viewerRole = Role::query()->create(['code' => 'preview_viewer', 'name' => 'Preview viewer']);
        $viewerRole->permissions()->attach(Permission::query()->where('code', 'imports.view_all')->value('id'));
        $viewer = $this->phaseFiveUser(overrides: ['role_id' => $viewerRole->id]);
        $importBefore = $import->refresh()->getRawOriginal();
        $revisionBefore = $revision->refresh()->getRawOriginal();

        $this->actingAs($viewer)->getJson('/api/v2/imports/options')
            ->assertOk()
            ->assertJsonPath('data.project_options.fiscal_years.0.is_locked', true);
        $this->getJson("/api/v2/imports/{$import->public_id}")
            ->assertOk()
            ->assertJsonPath('data.current_preview.id', $revision->id)
            ->assertJsonPath('data.current_preview.payload.fiscal_year_id', $this->importFiscalYear->id)
            ->assertJsonPath('data.abilities.view_original', true)
            ->assertJsonPath('data.abilities.review', false)
            ->assertJsonPath('data.abilities.retry', false)
            ->assertJsonPath('data.abilities.confirm', false);
        $this->getJson("/api/v2/imports/{$import->public_id}/preview-revisions")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $revision->id);
        $this->postJson("/api/v2/imports/{$import->public_id}/preview-revisions", [
            'base_revision_id' => $revision->id,
            'payload' => $this->validImportPreviewPayload(),
            'idempotency_key' => 'viewer-cannot-edit',
        ])->assertForbidden();
        $this->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $revision->id,
            'idempotency_key' => 'viewer-cannot-confirm',
        ])->assertForbidden();
        $this->postJson("/api/v2/imports/{$failedImport->public_id}/retry")->assertForbidden();

        $this->assertSame($importBefore, $import->refresh()->getRawOriginal());
        $this->assertSame($revisionBefore, $revision->refresh()->getRawOriginal());
        $this->assertSame(DocumentImportStatus::Failed, $failedImport->refresh()->status);
        $this->assertDatabaseCount('import_preview_revisions', 1);
        $this->assertNoCanonicalImportWrites();
        Queue::assertNothingPushed();
    }

    public function test_original_download_is_private_hardened_and_reports_a_missing_blob(): void
    {
        $owner = $this->phaseFiveUser();
        $other = $this->phaseFiveUser(department: $this->otherImportDepartment);
        $contents = "%PDF-1.4\nprivate original\n%%EOF\n";
        $documentImport = $this->createDocumentImport($owner, contents: $contents);

        $this->actingAs($other)
            ->getJson("/api/v2/imports/{$documentImport->public_id}/original")
            ->assertForbidden();

        $response = $this->actingAs($owner)
            ->get("/api/v2/imports/{$documentImport->public_id}/original")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($contents, $response->streamedContent());

        Storage::disk('project-imports')->delete($documentImport->storage_path);

        $this->getJson("/api/v2/imports/{$documentImport->public_id}/original")
            ->assertConflict()
            ->assertJsonPath('code', 'original_document_unavailable');
    }

    public function test_retry_is_owner_or_manager_only_and_resets_failure_before_dispatch(): void
    {
        $owner = $this->phaseFiveUser();
        $other = $this->phaseFiveUser(department: $this->otherImportDepartment);
        $documentImport = $this->createDocumentImport($owner, DocumentImportStatus::Failed, [
            'active_extraction_attempt' => 1,
            'failure_stage' => 'extracting_text',
            'failure_code' => 'TEXT_EXTRACTION_UNAVAILABLE',
            'failure_message' => 'No text layer.',
        ]);
        $this->createExtractionRun(
            $documentImport,
            1,
            AiExtractionRunStatus::Failed,
            [
                'finished_at' => now(),
                'failure_code' => 'TEXT_EXTRACTION_UNAVAILABLE',
                'failure_message' => 'No text layer.',
            ],
        );

        $this->actingAs($other)
            ->postJson("/api/v2/imports/{$documentImport->public_id}/retry")
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson("/api/v2/imports/{$documentImport->public_id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.processing_stage', 'validating')
            ->assertJsonPath('data.failure', null);

        $documentImport->refresh();
        $this->assertSame(2, $documentImport->active_extraction_attempt);
        $this->assertDatabaseHas('ai_extraction_runs', [
            'document_import_id' => $documentImport->id,
            'attempt_no' => 2,
            'status' => AiExtractionRunStatus::Queued->value,
        ]);
        $this->assertNull($documentImport->failure_stage);
        $this->assertNull($documentImport->failure_code);
        $this->assertNull($documentImport->failure_message);
        Queue::assertPushed(
            ProcessDocumentImport::class,
            fn (ProcessDocumentImport $job): bool => $job->documentImportId === $documentImport->id,
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_import.retry_requested',
            'auditable_id' => $documentImport->id,
            'user_id' => $owner->id,
        ]);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_upload_and_retry_reject_synchronous_production_queue_before_mutating_state(): void
    {
        $owner = $this->phaseFiveUser();
        $import = $this->createDocumentImport($owner, DocumentImportStatus::Failed, [
            'failure_code' => 'TEXT_EXTRACTION_UNAVAILABLE',
        ]);
        $this->app->instance('env', 'production');
        config()->set('queue.default', 'sync');

        $this->actingAs($owner)->post('/api/v2/imports', [
            'document' => UploadedFile::fake()->createWithContent('valid.pdf', "%PDF-1.4\n%%EOF\n"),
        ], ['Accept' => 'application/json'])
            ->assertServiceUnavailable()->assertJsonPath('code', 'import_queue_unavailable');
        $this->postJson("/api/v2/imports/{$import->public_id}/retry")
            ->assertServiceUnavailable()->assertJsonPath('code', 'import_queue_unavailable');

        $this->assertDatabaseCount('document_imports', 1);
        $this->assertDatabaseCount('ai_extraction_runs', 0);
        $this->assertSame(DocumentImportStatus::Failed, $import->refresh()->status);
        $this->assertSame('TEXT_EXTRACTION_UNAVAILABLE', $import->failure_code);
        Queue::assertNothingPushed();
        $this->assertNoCanonicalImportWrites();
    }

    public function test_upload_queue_failure_preserves_original_and_marks_import_retryable(): void
    {
        $owner = $this->phaseFiveUser();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue is unavailable.'));

        $this->actingAs($owner)->post('/api/v2/imports', [
            'document' => UploadedFile::fake()->createWithContent('valid.pdf', "%PDF-1.4\n%%EOF\n"),
        ], ['Accept' => 'application/json'])
            ->assertServiceUnavailable()->assertJsonPath('code', 'import_queue_unavailable');

        $import = DocumentImport::query()->sole();
        $this->assertSame(DocumentImportStatus::Failed, $import->status);
        $this->assertSame('IMPORT_QUEUE_DISPATCH_FAILED', $import->failure_code);
        $this->assertNull($import->active_extraction_attempt);
        Storage::disk('project-imports')->assertExists($import->storage_path);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_retry_queue_failure_terminates_only_the_new_queued_attempt(): void
    {
        $owner = $this->phaseFiveUser();
        $import = $this->createDocumentImport($owner, DocumentImportStatus::Failed, ['active_extraction_attempt' => 1]);
        $priorRun = $this->createExtractionRun($import, 1, AiExtractionRunStatus::Failed, ['finished_at' => now()]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue is unavailable.'));

        $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/retry")
            ->assertServiceUnavailable()->assertJsonPath('code', 'import_queue_unavailable');

        $this->assertSame(DocumentImportStatus::Failed, $import->refresh()->status);
        $this->assertSame(2, $import->active_extraction_attempt);
        $this->assertDatabaseHas('ai_extraction_runs', [
            'document_import_id' => $import->id,
            'attempt_no' => 2,
            'status' => AiExtractionRunStatus::Failed->value,
            'failure_code' => 'IMPORT_QUEUE_DISPATCH_FAILED',
        ]);
        $this->assertSame(AiExtractionRunStatus::Failed, $priorRun->refresh()->status);
        $this->assertNull($priorRun->failure_code);
        $this->assertNoCanonicalImportWrites();
    }
}
