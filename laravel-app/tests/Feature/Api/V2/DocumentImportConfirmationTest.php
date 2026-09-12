<?php

namespace Tests\Feature\Api\V2;

use App\Enums\DocumentImportStatus;
use App\Models\AuditLog;
use App\Models\DocumentContent;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Services\Imports\ImportPreviewService;
use App\Services\Projects\ProjectService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\TestCase;

class DocumentImportConfirmationTest extends TestCase
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

    public function test_preview_revisions_are_append_only_and_idempotent_with_stale_edit_protection(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, $run, $original] = $this->createReviewableImport($owner);
        $this->mock(ProjectService::class)->shouldNotReceive('createInTransaction');
        $payload = $this->validImportPreviewPayload(['name' => 'Reviewed project title']);
        $request = ['base_revision_id' => $original->id, 'payload' => $payload, 'idempotency_key' => 'preview-save-1'];
        $url = "/api/v2/imports/{$import->public_id}/preview-revisions";

        $created = $this->actingAs($owner)->postJson($url, $request)
            ->assertCreated()
            ->assertJsonPath('data.revision_no', 2)
            ->assertJsonPath('data.source', 'user')
            ->assertJsonPath('data.payload.name', 'Reviewed project title')
            ->assertJsonPath('data.edited_by.id', $owner->id);

        $reordered = array_reverse($payload, true);
        $this->postJson($url, array_replace($request, ['payload' => $reordered]))
            ->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'));

        $this->postJson($url, array_replace($request, ['payload' => array_replace($payload, ['budget' => '2000.00'])]))
            ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->postJson($url, array_replace($request, ['idempotency_key' => 'stale-edit']))
            ->assertConflict()->assertJsonPath('code', 'stale_preview_revision');

        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('import_preview_revisions', 2);
        $this->assertSame(2, $import->refresh()->current_preview_revision);
        // JSON object key order is not preserved by MySQL; values and list order must be.
        $this->assertJsonStringEqualsJsonString(
            json_encode($this->validImportPreviewPayload(), JSON_THROW_ON_ERROR),
            json_encode($original->refresh()->payload, JSON_THROW_ON_ERROR),
        );
        $this->assertJsonStringEqualsJsonString(
            json_encode($this->validImportPreviewPayload(), JSON_THROW_ON_ERROR),
            json_encode($run->refresh()->normalized_result, JSON_THROW_ON_ERROR),
        );
        $this->assertNoCanonicalImportWrites();
    }

    public function test_invalid_preview_can_be_saved_but_cannot_be_confirmed(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $original] = $this->createReviewableImport($owner);
        $payload = $this->validImportPreviewPayload([
            'name' => null,
            'budget' => '-1.00',
            'end_date' => '2026-09-01',
            'indicators' => [['name' => 'Invalid precision', 'target_value' => '1.123', 'unit' => 'items']],
        ]);
        $revision = $this->actingAs($owner)
            ->postJson("/api/v2/imports/{$import->public_id}/preview-revisions", [
                'base_revision_id' => $original->id, 'payload' => $payload, 'idempotency_key' => 'invalid-preview',
            ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['validation_errors' => ['name', 'budget', 'end_date', 'indicators.0.target_value']]]);

        $this->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $revision->json('data.id'), 'idempotency_key' => 'invalid-confirm',
        ])->assertUnprocessable()->assertJsonPath('code', 'validation_failed');

        $this->assertSame(DocumentImportStatus::NeedsReview, $import->refresh()->status);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_preview_and_confirmation_reject_injected_fields_and_foreign_revisions(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        [, , $foreignRevision] = $this->createReviewableImport($owner);
        $this->actingAs($owner);

        $this->postJson("/api/v2/imports/{$import->public_id}/preview-revisions", [
            'base_revision_id' => $revision->id,
            'payload' => $this->validImportPreviewPayload(['user_id' => 999, 'project_status_id' => 999]),
            'idempotency_key' => 'injected-preview',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['_payload']]);

        $this->postJson("/api/v2/imports/{$import->public_id}/preview-revisions", [
            'base_revision_id' => $foreignRevision->id,
            'payload' => $this->validImportPreviewPayload(),
            'idempotency_key' => 'foreign-preview',
        ])->assertConflict()->assertJsonPath('code', 'stale_preview_revision');

        $this->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $revision->id, 'idempotency_key' => 'injected-confirm', 'payload' => ['budget' => 0],
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['_request']]);
        $this->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $foreignRevision->id, 'idempotency_key' => 'foreign-confirm',
        ])->assertConflict()->assertJsonPath('code', 'stale_preview_revision');

        $this->assertNoCanonicalImportWrites();
    }

    public function test_malformed_preview_field_types_return_validation_errors_without_server_errors(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);

        $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/preview-revisions", [
            'base_revision_id' => $revision->id,
            'payload' => $this->validImportPreviewPayload([
                'department_id' => ['unexpected'],
                'fiscal_year_id' => ['nested' => ['unexpected']],
                'start_date' => ['unexpected'],
                'indicators' => ['unexpected'],
            ]),
            'idempotency_key' => 'malformed-preview',
        ])->assertCreated()->assertJsonStructure([
            'data' => ['validation_errors' => ['department_id', 'fiscal_year_id', 'start_date', 'indicators.0']],
        ]);

        $this->assertNoCanonicalImportWrites();
    }

    public function test_confirmation_creates_canonical_rows_once_and_preserves_exact_source_provenance(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, $run, $revision] = $this->createReviewableImport($owner);
        $request = ['preview_revision_id' => $revision->id, 'idempotency_key' => 'confirm-project-1'];
        $url = "/api/v2/imports/{$import->public_id}/confirm";

        $response = $this->actingAs($owner)->postJson($url, $request)
            ->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('data.document_import.status', 'confirmed');
        $project = Project::query()->sole();
        $document = ProjectDocument::query()->sole();
        $content = DocumentContent::query()->sole();
        $auditCount = AuditLog::query()->count();

        $this->postJson($url, $request)->assertOk()->assertJsonPath('data.project.id', $project->id);
        $this->postJson($url, array_replace($request, ['idempotency_key' => 'different-confirm']))
            ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame($project->id, $response->json('data.project.id'));
        $this->assertSame($owner->id, $project->user_id);
        $this->assertSame('draft', $project->status->code);
        $this->assertSame('not_started', $project->executionStatus->code);
        $this->assertSame('pending', $project->evaluationStatus->code);
        $this->assertSame('0.00', $project->actual_spent);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_kpis', 2);
        $this->assertDatabaseCount('project_access', 1);
        $this->assertDatabaseCount('project_status_histories', 1);
        $this->assertDatabaseCount('project_execution_status_histories', 1);
        $this->assertDatabaseCount('project_documents', 1);
        $this->assertDatabaseCount('document_contents', 1);
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame($import->id, $document->source_import_id);
        $this->assertSame($import->storage_path, $document->path);
        $this->assertSame($import->storage_disk, $document->storage_disk);
        $this->assertSame($import->sha256, $document->checksum);
        $this->assertSame($import->size_bytes, $document->size);
        $this->assertSame($owner->id, $document->uploaded_by);
        $this->assertSame($run->extracted_text, $content->extracted_text);
        $this->assertSame(hash('sha256', 'confirm-project-1'), $import->refresh()->confirmation_idempotency_key_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_import.confirmed', 'auditable_id' => $import->id]);
        Storage::disk('project-imports')->assertExists($document->path);
        $this->assertCount(1, Storage::disk('project-imports')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_confirmation_uses_current_user_revision_and_rejects_stale_revision(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $original] = $this->createReviewableImport($owner);
        $edited = $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/preview-revisions", [
            'base_revision_id' => $original->id,
            'payload' => $this->validImportPreviewPayload(['name' => 'Human reviewed title', 'indicators' => []]),
            'idempotency_key' => 'review-before-confirm',
        ])->assertCreated();

        $this->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $original->id, 'idempotency_key' => 'old-revision',
        ])->assertConflict()->assertJsonPath('code', 'stale_preview_revision');
        $this->assertNoCanonicalImportWrites();

        $this->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $edited->json('data.id'), 'idempotency_key' => 'current-revision',
        ])->assertCreated()->assertJsonPath('data.project.name', 'Human reviewed title');
        $this->assertDatabaseCount('project_kpis', 0);
        $this->assertSame(2, $import->refresh()->confirmed_preview_revision);
    }

    public function test_preview_and_confirm_use_revision_row_ids_when_numbers_identify_other_rows(): void
    {
        $owner = $this->phaseFiveUser();
        [, , $foreignRevision] = $this->createReviewableImport($owner);
        [$import, , $original] = $this->createReviewableImport($owner);
        $this->assertNotSame($original->revision_no, $original->id);
        $this->actingAs($owner);
        $previewUrl = "/api/v2/imports/{$import->public_id}/preview-revisions";
        $confirmUrl = "/api/v2/imports/{$import->public_id}/confirm";

        $this->postJson($previewUrl, [
            'base_revision_id' => $original->revision_no,
            'payload' => $this->validImportPreviewPayload(),
            'idempotency_key' => 'incorrect-base-number',
        ])->assertConflict()->assertJsonPath('code', 'stale_preview_revision');

        $edited = $this->postJson($previewUrl, [
            'base_revision_id' => $original->id,
            'payload' => $this->validImportPreviewPayload(['name' => 'Confirmed by revision row ID']),
            'idempotency_key' => 'correct-base-row-id',
        ])->assertCreated()->assertJsonPath('data.revision_no', 2);
        $currentId = $edited->json('data.id');
        $this->assertNotSame($edited->json('data.revision_no'), $currentId);
        $this->getJson("/api/v2/imports/{$import->public_id}")
            ->assertOk()->assertJsonPath('data.current_preview.id', $currentId);

        foreach ([$edited->json('data.revision_no'), $foreignRevision->id] as $staleId) {
            $this->postJson($confirmUrl, [
                'preview_revision_id' => $staleId,
                'idempotency_key' => 'current-row-confirm',
            ])->assertConflict()->assertJsonPath('code', 'stale_preview_revision');
            $this->assertNoCanonicalImportWrites();
            $this->assertDatabaseCount('audit_logs', 1);
        }

        $request = ['preview_revision_id' => $currentId, 'idempotency_key' => 'current-row-confirm'];
        $confirmed = $this->postJson($confirmUrl, $request)->assertCreated()
            ->assertJsonPath('data.project.name', 'Confirmed by revision row ID');
        $auditCount = AuditLog::query()->count();
        $this->postJson($confirmUrl, $request)->assertOk()
            ->assertJsonPath('data.project.id', $confirmed->json('data.project.id'));
        $this->postJson($confirmUrl, array_replace($request, ['preview_revision_id' => $original->id]))
            ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_documents', 1);
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertSame(2, $import->refresh()->confirmed_preview_revision);
    }

    public function test_confirmed_import_cannot_append_preview_even_with_a_stale_reviewable_service_instance(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, $run, $revision] = $this->createReviewableImport($owner);
        $staleImport = $import->fresh();
        $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $revision->id, 'idempotency_key' => 'final-preview-confirm',
        ])->assertCreated();
        $importBefore = $import->refresh()->getRawOriginal();
        $runBefore = $run->refresh()->getRawOriginal();
        $revisionBefore = $revision->refresh()->getRawOriginal();
        $document = ProjectDocument::query()->sole();
        $documentBefore = $document->getRawOriginal();
        $auditCount = AuditLog::query()->count();
        $payload = $this->validImportPreviewPayload(['name' => 'Unacceptable post-confirm edit']);

        $this->postJson("/api/v2/imports/{$import->public_id}/preview-revisions", [
            'base_revision_id' => $revision->id,
            'payload' => $payload,
            'idempotency_key' => 'post-confirm-api-edit',
        ])->assertForbidden();
        $this->assertSame(DocumentImportStatus::NeedsReview, $staleImport->status);

        try {
            app(ImportPreviewService::class)->appendUserRevision(
                $owner, $staleImport, $revision->id, $payload, 'post-confirm-stale-service-edit',
            );
            $this->fail('A stale reviewable instance appended to a confirmed import.');
        } catch (AuthorizationException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('import_preview_revisions', 1);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_kpis', 2);
        $this->assertDatabaseCount('project_documents', 1);
        $this->assertDatabaseCount('document_contents', 1);
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertSame($importBefore, $import->refresh()->getRawOriginal());
        $this->assertSame($runBefore, $run->refresh()->getRawOriginal());
        $this->assertSame($revisionBefore, $revision->refresh()->getRawOriginal());
        $this->assertSame($documentBefore, $document->refresh()->getRawOriginal());
    }

    public function test_locked_fiscal_year_keeps_preview_readable_and_draft_editable_but_cannot_create_a_project(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $this->importFiscalYear->update(['is_locked' => true]);
        $originalRevision = $revision->refresh()->getRawOriginal();
        $payload = $this->validImportPreviewPayload(['name' => 'Draft awaiting an open fiscal year']);

        $this->actingAs($owner)->getJson("/api/v2/imports/{$import->public_id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.current_preview.id', $revision->id);
        $saved = $this->postJson("/api/v2/imports/{$import->public_id}/preview-revisions", [
            'base_revision_id' => $revision->id,
            'payload' => $payload,
            'idempotency_key' => 'locked-year-draft',
        ])->assertCreated()
            ->assertJsonPath('data.revision_no', 2)
            ->assertJsonPath('data.payload.name', $payload['name'])
            ->assertJsonStructure(['data' => ['validation_errors' => ['fiscal_year_id']]]);
        $this->getJson("/api/v2/imports/{$import->public_id}/preview-revisions")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $saved->json('data.id'),
            'idempotency_key' => 'locked-year-confirm',
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['fiscal_year_id']]);

        $this->assertSame($originalRevision, $revision->refresh()->getRawOriginal());
        $this->assertSame(DocumentImportStatus::NeedsReview, $import->refresh()->status);
        $this->assertSame(2, $import->current_preview_revision);
        $this->assertDatabaseCount('import_preview_revisions', 2);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_confirmation_revalidates_fiscal_lock_and_department_at_confirmation_time(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $this->importFiscalYear->update(['is_locked' => true]);
        $url = "/api/v2/imports/{$import->public_id}/confirm";
        $request = ['preview_revision_id' => $revision->id, 'idempotency_key' => 'locked-confirm'];

        $this->actingAs($owner)->postJson($url, $request)
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['fiscal_year_id']]);
        $this->importFiscalYear->update(['is_locked' => false]);
        $owner->update(['department_id' => $this->otherImportDepartment->id]);
        $this->postJson($url, $request)
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['department_id']]);

        $this->assertNoCanonicalImportWrites();
    }

    public function test_unauthorized_user_cannot_read_revisions_edit_or_confirm(): void
    {
        $owner = $this->phaseFiveUser();
        $other = $this->phaseFiveUser(department: $this->otherImportDepartment);
        [$import, , $revision] = $this->createReviewableImport($owner);
        $this->actingAs($other)->getJson("/api/v2/imports/{$import->public_id}/preview-revisions")->assertForbidden();
        $this->postJson("/api/v2/imports/{$import->public_id}/preview-revisions", [
            'base_revision_id' => $revision->id, 'payload' => $this->validImportPreviewPayload(), 'idempotency_key' => 'forbidden-edit',
        ])->assertForbidden();
        $this->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $revision->id, 'idempotency_key' => 'forbidden-confirm',
        ])->assertForbidden();
        $this->assertNoCanonicalImportWrites();
    }

    public function test_confirmation_rolls_back_project_kpis_documents_histories_and_audits_on_late_failure(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $reject = true;
        AuditLog::creating(function (AuditLog $audit) use (&$reject): void {
            if ($reject && $audit->action === 'document_import.confirmed') {
                throw new RuntimeException('Forced confirmation audit failure.');
            }
        });
        $request = ['preview_revision_id' => $revision->id, 'idempotency_key' => 'atomic-confirm'];

        try {
            $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/confirm", $request)
                ->assertInternalServerError();
        } finally {
            $reject = false;
        }

        $this->assertNoCanonicalImportWrites();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(DocumentImportStatus::NeedsReview, $import->refresh()->status);
        $this->assertNull($import->confirmed_project_id);
        $this->assertNull($import->confirmation_idempotency_key_hash);
        Storage::disk('project-imports')->assertExists($import->storage_path);

        $this->postJson("/api/v2/imports/{$import->public_id}/confirm", $request)->assertCreated();
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_missing_or_changed_original_blob_rejects_confirmation_before_any_canonical_write(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, $run, $revision] = $this->createReviewableImport($owner);
        $this->mock(ProjectService::class)->shouldNotReceive('createInTransaction');
        $original = Storage::disk('project-imports')->get($import->storage_path);
        $importBefore = $import->refresh()->getRawOriginal();
        $runBefore = $run->refresh()->getRawOriginal();
        $revisionBefore = $revision->refresh()->getRawOriginal();
        $url = "/api/v2/imports/{$import->public_id}/confirm";
        $request = ['preview_revision_id' => $revision->id, 'idempotency_key' => 'blob-confirm'];
        $this->actingAs($owner);

        foreach ([null, str_repeat('x', strlen($original)), substr($original, 0, -1), '', $original.'extra bytes'] as $changed) {
            if ($changed === null) {
                Storage::disk('project-imports')->delete($import->storage_path);
            } else {
                Storage::disk('project-imports')->put($import->storage_path, $changed);
            }

            $this->postJson($url, $request)->assertConflict()->assertJsonPath(
                'code',
                $changed === null ? 'original_document_unavailable' : 'original_document_integrity_failed',
            );

            $this->assertNoCanonicalImportWrites();
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertSame($importBefore, $import->refresh()->getRawOriginal());
            $this->assertSame($runBefore, $run->refresh()->getRawOriginal());
            $this->assertSame($revisionBefore, $revision->refresh()->getRawOriginal());
        }
    }

    public function test_confirmation_rejects_unverified_text_and_stale_extraction_source(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, $run, $revision] = $this->createReviewableImport($owner);
        $url = "/api/v2/imports/{$import->public_id}/confirm";
        $request = ['preview_revision_id' => $revision->id, 'idempotency_key' => 'source-confirm'];
        DB::table('ai_extraction_runs')->where('id', $run->id)->update(['extracted_text' => 'Tampered text']);
        $this->actingAs($owner)->postJson($url, $request)
            ->assertConflict()->assertJsonPath('code', 'extraction_source_unavailable');
        DB::table('ai_extraction_runs')->where('id', $run->id)->update(['extracted_text' => $run->extracted_text]);
        $import->update(['active_extraction_attempt' => 2]);
        $this->postJson($url, $request)
            ->assertConflict()->assertJsonPath('code', 'extraction_source_unavailable');
        $this->assertNoCanonicalImportWrites();
    }

    public function test_confirmed_source_models_reject_replacement_and_deletion(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $otherImport = $this->createDocumentImport($owner);
        $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $revision->id, 'idempotency_key' => 'immutable-confirm',
        ])->assertCreated();
        $document = ProjectDocument::query()->sole();
        $content = DocumentContent::query()->sole();
        $documentBefore = $document->getRawOriginal();
        $contentBefore = $content->getRawOriginal();
        $originalBytes = Storage::disk('project-imports')->get($import->storage_path);

        foreach ([
            fn () => $import->refresh()->update(['original_name' => 'replacement.pdf']),
            fn () => $import->refresh()->update(['confirmed_project_id' => null]),
            fn () => $import->refresh()->delete(),
            fn () => $revision->refresh()->update(['payload' => []]),
            fn () => $revision->refresh()->delete(),
            fn () => $document->refresh()->update(['path' => 'replacement.pdf']),
            fn () => $document->refresh()->update(['source_import_id' => null]),
            fn () => $document->refresh()->update(['source_import_id' => $otherImport->id]),
            fn () => $document->refresh()->update(['project_id' => $document->project_id + 1]),
            fn () => $document->refresh()->update(['original_name' => 'replacement.pdf']),
            fn () => $document->refresh()->update(['storage_disk' => 'local']),
            fn () => $document->refresh()->update(['mime_type' => 'text/plain']),
            fn () => $document->refresh()->update(['size' => $document->size + 1]),
            fn () => $document->refresh()->update(['uploaded_by' => $owner->id + 1]),
            fn () => $document->refresh()->update(['checksum' => hash('sha256', 'replacement')]),
            fn () => $document->refresh()->update(['version' => 2]),
            fn () => $document->refresh()->delete(),
            fn () => $content->refresh()->update(['extracted_text' => 'replacement text']),
            fn () => $content->refresh()->update(['document_id' => $document->id + 1]),
            fn () => $content->refresh()->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Immutable imported provenance accepted a mutation.');
            } catch (LogicException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }

            $this->assertSame($documentBefore, $document->refresh()->getRawOriginal());
            $this->assertSame($contentBefore, $content->refresh()->getRawOriginal());
            $this->assertSame($originalBytes, Storage::disk('project-imports')->get($import->storage_path));
        }

        $this->assertDatabaseCount('document_imports', 2);
        $this->assertDatabaseCount('import_preview_revisions', 1);
        $this->assertDatabaseCount('project_documents', 1);
        $this->assertDatabaseCount('document_contents', 1);
    }

    public function test_legacy_document_upload_and_lifecycle_preserve_imported_original_and_provenance(): void
    {
        Storage::fake('local');
        config()->set('filesystems.default', 'local');
        config()->set('services.n8n.document_webhook_url', null);
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $revision->id, 'idempotency_key' => 'legacy-lifecycle-confirm',
        ])->assertCreated();
        $importedDocument = ProjectDocument::query()->sole();
        $documentBefore = $importedDocument->getRawOriginal();
        $originalBytes = Storage::disk('project-imports')->get($import->storage_path);
        $url = "/projects/{$importedDocument->project_id}/documents";

        $this->post($url, [
            'document' => UploadedFile::fake()->createWithContent($importedDocument->original_name, "%PDF-1.4\nLegacy upload\n%%EOF\n"),
        ])->assertRedirect();
        $legacy = ProjectDocument::query()->whereNull('source_import_id')->sole();
        $this->assertNotSame($importedDocument->path, $legacy->path);
        Storage::disk('local')->assertExists($legacy->path);

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, $url, ['document_id' => $importedDocument->id])->assertStatus(405);
            $this->json($method, "{$url}/{$importedDocument->id}")->assertNotFound();
            $this->assertSame($documentBefore, $importedDocument->refresh()->getRawOriginal());
            $this->assertSame($originalBytes, Storage::disk('project-imports')->get($import->storage_path));
        }

        try {
            $legacy->update(['source_import_id' => $import->id]);
            $this->fail('A legacy document accepted imported provenance reassignment.');
        } catch (LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        try {
            $legacy->refresh()->update(['original_name' => 'updated-legacy.pdf']);
            $this->fail('The versioned source name was changed.');
        } catch (LogicException) {
            $this->assertSame($importedDocument->original_name, $legacy->refresh()->original_name);
        }
        $legacy->update(['version' => 2]);
        $this->assertSame(1, $legacy->initialVersion->revision_no);
        $legacyContent = $legacy->content()->create(['extracted_text' => 'Legacy text']);
        $legacyContent->update(['extracted_text' => 'Updated legacy text']);
        $this->assertSame('Updated legacy text', $legacyContent->refresh()->extracted_text);
        $this->assertSame($importedDocument->original_name, $legacy->refresh()->original_name);
        $this->assertNull($legacy->source_import_id);
        $legacyContent->delete();
        try {
            $legacy->delete();
            $this->fail('The versioned source was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('project_documents', ['id' => $legacy->id]);
        }
        $this->assertDatabaseCount('project_documents', 2);
        $this->assertDatabaseCount('document_versions', 2);
        $this->assertDatabaseCount('document_contents', 1);
        $this->assertSame($documentBefore, $importedDocument->refresh()->getRawOriginal());
        $this->assertSame($originalBytes, Storage::disk('project-imports')->get($import->storage_path));
        $this->assertSame($import->sha256, hash('sha256', $originalBytes));
    }
}
