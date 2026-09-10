<?php

namespace Tests\Feature\Api\V2;

use App\Models\DocumentContent;
use App\Models\Project;
use App\Models\ProjectAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\TestCase;

class ImportedProjectDetailTest extends TestCase
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

    public function test_teacher_can_read_confirmed_kpis_and_download_the_linked_original_from_project_detail(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, $run, $revision] = $this->createReviewableImport(
            $owner,
            importOverrides: ['original_name' => 'text-project.pdf'],
            revisionOverrides: ['payload' => $this->validImportPreviewPayload([
                'indicators' => [['name' => 'Completion', 'target_value' => '100.00', 'unit' => 'percent']],
            ])],
        );
        $originalBytes = Storage::disk($import->storage_disk)->get($import->storage_path);
        $projectId = $this->actingAs($owner)
            ->postJson("/api/v2/imports/{$import->public_id}/confirm", [
                'preview_revision_id' => $revision->id,
                'idempotency_key' => 'project-detail-confirm',
            ])
            ->assertCreated()
            ->json('data.project.id');
        $project = Project::query()->findOrFail($projectId);
        $kpi = $project->kpis()->sole();
        $document = $project->documents()->sole();
        $content = $document->content;

        $this->assertSame($import->id, $document->source_import_id);
        $this->assertSame($import->storage_path, $document->path);
        $this->assertSame($import->storage_disk, $document->storage_disk);
        $this->assertSame($import->sha256, $document->checksum);
        $this->assertSame($import->id, $document->sourceImport->id);
        $this->assertInstanceOf(DocumentContent::class, $content);
        $this->assertSame($document->id, $content->document_id);
        $this->assertSame($run->extracted_text, $content->extracted_text);

        $downloadUrl = "/api/v2/imports/{$import->public_id}/original";
        $response = $this->getJson("/api/v2/projects/{$projectId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.kpis')
            ->assertJsonPath('data.kpis.0.id', $kpi->id)
            ->assertJsonPath('data.kpis.0.name', 'Completion')
            ->assertJsonPath('data.kpis.0.target_value', '100.00')
            ->assertJsonPath('data.kpis.0.actual_value', null)
            ->assertJsonPath('data.kpis.0.unit', 'percent')
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.id', $document->id)
            ->assertJsonPath('data.documents.0.original_name', 'text-project.pdf')
            ->assertJsonPath('data.documents.0.mime_type', 'application/pdf')
            ->assertJsonPath('data.documents.0.size_bytes', strlen($originalBytes))
            ->assertJsonPath('data.documents.0.source_import_id', $import->id)
            ->assertJsonPath('data.documents.0.download_url', $downloadUrl);

        foreach (['path', 'storage_path', 'storage_disk', 'extracted_text', 'checksum', 'content', 'source_import'] as $field) {
            $response->assertJsonMissingPath("data.documents.0.{$field}");
        }

        $this->assertStringNotContainsString($run->extracted_text, $response->getContent());
        $this->assertStringNotContainsString($document->path, $response->getContent());
        $this->assertStringNotContainsString($document->checksum, $response->getContent());
        $this->get($response->json('data.documents.0.download_url'))
            ->assertOk()
            ->assertDownload('text-project.pdf')
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertStreamedContent($originalBytes);

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_kpis', 1);
        $this->assertDatabaseCount('project_documents', 1);
        $this->assertDatabaseCount('document_contents', 1);
        $this->assertSame($originalBytes, Storage::disk($document->storage_disk)->get($document->path));
        $this->assertSame($run->extracted_text, $content->refresh()->extracted_text);
        $this->assertCount(1, Storage::disk('project-imports')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_shared_project_metadata_does_not_grant_access_to_the_import_original(): void
    {
        $owner = $this->phaseFiveUser();
        $viewer = $this->phaseFiveUser();
        $unrelated = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $projectId = $this->actingAs($owner)
            ->postJson("/api/v2/imports/{$import->public_id}/confirm", [
                'preview_revision_id' => $revision->id,
                'idempotency_key' => 'shared-project-detail-confirm',
            ])
            ->assertCreated()
            ->json('data.project.id');

        ProjectAccess::query()->create([
            'project_id' => $projectId,
            'user_id' => $viewer->id,
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($viewer)->getJson("/api/v2/projects/{$projectId}")
            ->assertOk()
            ->assertJsonCount(2, 'data.kpis')
            ->assertJsonPath('data.kpis.0.name', 'Completion')
            ->assertJsonPath('data.kpis.0.target_value', '100.00')
            ->assertJsonPath('data.kpis.0.unit', 'percent')
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.original_name', $import->original_name)
            ->assertJsonPath('data.documents.0.source_import_id', $import->id)
            ->assertJsonPath('data.documents.0.download_url', null);

        $this->getJson("/api/v2/imports/{$import->public_id}/original")
            ->assertForbidden();
        $this->actingAs($unrelated)->getJson("/api/v2/projects/{$projectId}")
            ->assertForbidden()
            ->assertJsonMissingPath('data.kpis')
            ->assertJsonMissingPath('data.documents');

        $this->assertDatabaseCount('project_kpis', 2);
        $this->assertDatabaseCount('project_documents', 1);
        $this->assertDatabaseCount('document_contents', 1);
    }

    public function test_empty_detail_returns_empty_kpi_and_document_arrays_without_adding_them_to_project_lists(): void
    {
        $owner = $this->phaseFiveUser();
        $payload = $this->validImportPreviewPayload();
        unset($payload['indicators']);

        $projectId = $this->actingAs($owner)->postJson('/api/v2/projects', $payload)
            ->assertCreated()
            ->json('data.id');

        $this->getJson("/api/v2/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('data.kpis', [])
            ->assertJsonPath('data.documents', []);

        $this->getJson('/api/v2/projects')
            ->assertOk()
            ->assertJsonPath('data.0.id', $projectId)
            ->assertJsonMissingPath('data.0.kpis')
            ->assertJsonMissingPath('data.0.documents');

        $this->assertDatabaseCount('project_kpis', 0);
        $this->assertDatabaseCount('project_documents', 0);
        $this->assertDatabaseCount('document_contents', 0);
    }
}
