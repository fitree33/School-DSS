<?php

namespace Tests\Feature\Api\V2;

use App\Models\AuditLog;
use App\Models\DocumentContent;
use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\TestCase;

class ImportedDocumentLegacyLifecycleTest extends TestCase
{
    use BuildsPhaseFiveImports;
    use RefreshDatabase;

    private const LEGACY_TOKEN = 'phase-five-legacy-test-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPhaseFiveImports();
        Storage::fake('project-imports');
        Storage::fake('local');
        config()->set('filesystems.default', 'local');
        config()->set('services.n8n.document_webhook_url', 'https://legacy-provider.test/extract');
        config()->set('services.n8n.webhook_token', self::LEGACY_TOKEN);
        Http::preventStrayRequests();
    }

    public function test_legacy_callback_cannot_target_an_imported_original_or_mutate_its_project(): void
    {
        [$project, $original] = $this->confirmedProject();
        $projectBefore = $project->getRawOriginal();
        $documentBefore = $original->getRawOriginal();
        $contentBefore = $original->content->getRawOriginal();
        $bytes = Storage::disk($original->storage_disk)->get($original->path);
        $auditCount = AuditLog::query()->count();

        $this->withToken(self::LEGACY_TOKEN)->postJson("/api/projects/{$project->id}/ai-summary", [
            'document_id' => $original->id,
            'summary' => 'Legacy callback must not rewrite imported provenance.',
        ])->assertConflict()->assertJsonPath('code', 'imported_original_immutable');

        $this->assertSame($projectBefore, $project->refresh()->getRawOriginal());
        $this->assertSame($documentBefore, $original->refresh()->getRawOriginal());
        $this->assertSame($contentBefore, $original->content->getRawOriginal());
        $this->assertSame($bytes, Storage::disk($original->storage_disk)->get($original->path));
        $this->assertDatabaseCount('audit_logs', $auditCount);
    }

    public function test_ordinary_legacy_upload_and_provider_callback_work_alongside_imported_original(): void
    {
        Http::fake(['legacy-provider.test/*' => Http::response(['accepted' => true])]);
        [$project, $original] = $this->confirmedProject();
        $originalBefore = $original->getRawOriginal();
        $contentBefore = $original->content->getRawOriginal();
        $bytes = Storage::disk($original->storage_disk)->get($original->path);

        $this->post("/projects/{$project->id}/documents", [
            'document' => UploadedFile::fake()->createWithContent($original->original_name, "%PDF-1.4\nLegacy upload\n%%EOF\n"),
            'document_id' => $original->id,
            'source_import_id' => $original->source_import_id,
            'path' => $original->path,
            'storage_disk' => $original->storage_disk,
        ])->assertRedirect();

        $legacy = $project->documents()->whereNull('source_import_id')->sole();
        $this->assertSame('local', $legacy->storage_disk);
        $this->assertSame('processing', $legacy->processing_status);
        $this->assertNotSame($original->path, $legacy->path);
        Storage::disk('local')->assertExists($legacy->path);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($legacy, $project): bool {
            $fields = collect($request->data())->pluck('contents', 'name');

            return $request->url() === 'https://legacy-provider.test/extract'
                && (int) $fields['document_id'] === $legacy->id
                && (int) $fields['project_id'] === $project->id;
        });

        $this->withToken(self::LEGACY_TOKEN)->postJson("/api/projects/{$project->id}/ai-summary", [
            'document_id' => $legacy->id,
            'summary' => 'Ordinary legacy summary.',
        ])->assertOk()->assertJsonPath('project_id', $project->id);

        $this->assertSame('Ordinary legacy summary.', $project->refresh()->ai_summary);
        $this->assertSame('completed', $legacy->refresh()->processing_status);
        $this->assertNotNull($legacy->processed_at);
        $this->assertNull($legacy->processing_error);

        try {
            $legacy->update(['original_name' => 'renamed.pdf']);
            $this->fail('The versioned source name was changed.');
        } catch (LogicException) {
            $this->assertSame($original->original_name, $legacy->refresh()->original_name);
        }
        $legacy->update(['version' => 2]);
        $this->assertSame(1, $legacy->initialVersion->revision_no);
        $legacyContent = $legacy->content()->create(['extracted_text' => 'Legacy text.']);
        $legacyContent->update(['extracted_text' => 'Revised legacy text.']);
        $this->assertSame('Revised legacy text.', $legacyContent->refresh()->extracted_text);
        $legacyContent->delete();
        try {
            $legacy->delete();
            $this->fail('The versioned source was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('project_documents', ['id' => $legacy->id]);
        }

        $this->assertSame($originalBefore, $original->refresh()->getRawOriginal());
        $this->assertSame($contentBefore, $original->content->getRawOriginal());
        $this->assertSame($bytes, Storage::disk($original->storage_disk)->get($original->path));
        $this->assertDatabaseCount('project_documents', 2);
        $this->assertDatabaseCount('document_versions', 2);
        $this->assertDatabaseCount('document_contents', 1);
    }

    public function test_legacy_callback_without_document_id_preserves_imported_original(): void
    {
        [$project, $original] = $this->confirmedProject();
        $original->update(['processing_status' => 'processing']);
        $documentBefore = $original->getRawOriginal();
        $bytes = Storage::disk($original->storage_disk)->get($original->path);

        $this->withToken(self::LEGACY_TOKEN)->postJson("/api/projects/{$project->id}/ai-summary", [
            'summary' => 'Project-only legacy summary.',
        ])->assertOk();

        $this->assertSame('Project-only legacy summary.', $project->refresh()->ai_summary);
        $this->assertSame($documentBefore, $original->refresh()->getRawOriginal());
        $this->assertSame($bytes, Storage::disk($original->storage_disk)->get($original->path));
    }

    public function test_legacy_callback_rejects_foreign_document_before_updating_project(): void
    {
        [$project] = $this->confirmedProject();
        [, $foreignDocument] = $this->confirmedProject();
        $projectBefore = $project->getRawOriginal();

        $this->withToken(self::LEGACY_TOKEN)->postJson("/api/projects/{$project->id}/ai-summary", [
            'document_id' => $foreignDocument->id,
            'summary' => 'Wrong project.',
        ])->assertNotFound();

        $this->assertSame($projectBefore, $project->refresh()->getRawOriginal());
    }

    public function test_legacy_upload_provider_failure_preserves_imported_blob_and_records_legacy_failure(): void
    {
        [$project, $original] = $this->confirmedProject();
        $originalBefore = $original->getRawOriginal();
        $bytes = Storage::disk($original->storage_disk)->get($original->path);
        Http::fake(['legacy-provider.test/*' => Http::response(['error' => 'Unavailable'], 503)]);

        $this->post("/projects/{$project->id}/documents", [
            'document' => UploadedFile::fake()->createWithContent('legacy.pdf', "%PDF-1.4\nLegacy upload\n%%EOF\n"),
        ])->assertRedirect()->assertSessionHas('warning');

        $legacy = $project->documents()->whereNull('source_import_id')->sole();
        $this->assertSame('failed', $legacy->processing_status);
        $this->assertNotNull($legacy->processing_error);
        Storage::disk($legacy->storage_disk)->assertExists($legacy->path);
        $this->assertSame($originalBefore, $original->refresh()->getRawOriginal());
        $this->assertSame($bytes, Storage::disk($original->storage_disk)->get($original->path));
        $this->assertDatabaseCount('document_contents', 1);
    }

    /** @return array{Project, ProjectDocument} */
    private function confirmedProject(): array
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $response = $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $revision->id,
            'idempotency_key' => 'legacy-confirm-'.$import->public_id,
        ])->assertCreated();
        $project = Project::query()->findOrFail($response->json('data.project.id'));
        $document = $project->documents()->where('source_import_id', $import->id)->sole();
        $this->assertInstanceOf(DocumentContent::class, $document->content);

        return [$project, $document];
    }
}
