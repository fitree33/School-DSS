<?php

namespace Tests\Feature;

use App\Enums\DocumentImportStatus;
use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Exceptions\ApiProblemException;
use App\Models\AuditLog;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Services\Documents\DocumentBlobVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\TestCase;

class DocumentVersionWritePathsTest extends TestCase
{
    use BuildsPhaseFiveImports;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhaseFiveImports();
        Storage::fake('local');
        Storage::fake('project-imports');
        config()->set('filesystems.default', 'local');
        config()->set('services.n8n.document_webhook_url', null);
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_confirmation_registers_revision_one_atomically_and_replay_preserves_exact_version_identity(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $bytes = Storage::disk('project-imports')->get($import->storage_path);
        $url = "/api/v2/imports/{$import->public_id}/confirm";
        $request = ['preview_revision_id' => $revision->id, 'idempotency_key' => 'version-confirm'];

        $this->actingAs($owner)->postJson($url, $request)->assertCreated();

        $document = ProjectDocument::query()->sole();
        $version = DocumentVersion::query()->sole();
        $this->assertSame($document->id, $version->project_document_id);
        $this->assertSame(1, $version->revision_no);
        $this->assertSame(DocumentVersionCreatedVia::PhaseFiveConfirm, $version->created_via);
        $this->assertSame(DocumentVersionIntegrityBasis::RecordedSha256, $version->integrity_basis);
        $this->assertSame($owner->id, $version->created_by);
        $this->assertSame($import->storage_disk, $version->storage_disk);
        $this->assertSame($import->storage_path, $version->storage_path);
        $this->assertSame($import->original_name, $version->original_name);
        $this->assertSame($import->mime_type, $version->mime_type);
        $this->assertSame($import->size_bytes, $version->size_bytes);
        $this->assertSame($import->sha256, $version->sha256);
        $this->assertNotNull($version->verified_at);
        $before = $version->getRawOriginal();
        $auditCount = AuditLog::query()->count();
        $this->travel(1)->day();

        $this->postJson($url, $request)->assertOk();
        $this->postJson($url, array_replace($request, ['idempotency_key' => 'another-request']))
            ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');

        $this->assertDatabaseCount('document_versions', 1);
        $this->assertSame($before, $version->refresh()->getRawOriginal());
        $this->assertDatabaseCount('project_documents', 1);
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertSame($bytes, Storage::disk('project-imports')->get($import->storage_path));
        $this->assertCount(1, Storage::disk('project-imports')->allFiles());
    }

    public function test_version_verification_failure_rolls_back_all_confirmation_rows_and_preserves_original(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $before = $import->refresh()->getRawOriginal();
        $bytes = Storage::disk('project-imports')->get($import->storage_path);
        $this->mock(DocumentBlobVerifier::class)->shouldReceive('verifyDocument')->once()
            ->andReturnUsing(function (ProjectDocument $document): never {
                $this->assertDatabaseCount('projects', 1);
                $this->assertDatabaseCount('project_documents', 1);
                $this->assertDatabaseCount('document_contents', 1);
                $this->assertTrue($document->isImportedOriginal());

                throw new ApiProblemException('Injected verification failure.', 'document_version_integrity_failed', 409);
            });

        $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/confirm", [
            'preview_revision_id' => $revision->id, 'idempotency_key' => 'failed-version-confirm',
        ])->assertConflict()->assertJsonPath('code', 'document_version_integrity_failed');

        $this->assertNoCanonicalImportWrites();
        $this->assertSame($before, $import->refresh()->getRawOriginal());
        $this->assertSame($bytes, Storage::disk('project-imports')->get($import->storage_path));
    }

    public function test_late_confirmation_failure_rolls_back_an_already_inserted_version(): void
    {
        $owner = $this->phaseFiveUser();
        [$import, , $revision] = $this->createReviewableImport($owner);
        $reject = true;
        AuditLog::creating(function (AuditLog $audit) use (&$reject): void {
            if ($reject && $audit->action === 'document_import.confirmed') {
                $this->assertDatabaseCount('document_versions', 1);
                throw new RuntimeException('Injected failure after version registration.');
            }
        });

        try {
            $this->actingAs($owner)->postJson("/api/v2/imports/{$import->public_id}/confirm", [
                'preview_revision_id' => $revision->id, 'idempotency_key' => 'late-version-failure',
            ])->assertInternalServerError();
        } finally {
            $reject = false;
        }

        $this->assertNoCanonicalImportWrites();
        $this->assertSame(DocumentImportStatus::NeedsReview, $import->refresh()->status);
        Storage::disk('project-imports')->assertExists($import->storage_path);
    }

    #[DataProvider('uploadFormats')]
    public function test_new_upload_registers_verified_revision_one(string $name, string $mime, string $bytes): void
    {
        [$owner, $project] = $this->uploadProject();

        $this->actingAs($owner)->post("/projects/{$project->id}/documents", [
            'document' => UploadedFile::fake()->createWithContent($name, $bytes),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = ProjectDocument::query()->sole();
        $version = DocumentVersion::query()->sole();
        $this->assertSame($document->id, $version->project_document_id);
        $this->assertSame(1, $version->revision_no);
        $this->assertSame(DocumentVersionCreatedVia::LegacyUpload, $version->created_via);
        $this->assertSame(DocumentVersionIntegrityBasis::RecordedSha256, $version->integrity_basis);
        $this->assertSame($owner->id, $version->created_by);
        $this->assertSame('local', $version->storage_disk);
        $this->assertSame($document->path, $version->storage_path);
        $this->assertSame($name, $version->original_name);
        $this->assertSame($mime, $version->mime_type);
        $this->assertSame(strlen($bytes), $version->size_bytes);
        $this->assertSame(hash('sha256', $bytes), $version->sha256);
        $this->assertSame($bytes, Storage::disk('local')->get($document->path));
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('audit_logs', ['action' => 'project_document.uploaded', 'auditable_id' => $document->id]);
        $this->assertDatabaseCount('document_contents', 0);
        Http::assertNothingSent();
    }

    public static function uploadFormats(): array
    {
        return [
            'pdf' => ['new.pdf', 'application/pdf', "%PDF-1.4\nNew upload\n%%EOF\n"],
            'text' => ['new.txt', 'text/plain', "New non-PDF upload.\n"],
        ];
    }

    public function test_revision_one_exists_when_the_legacy_webhook_is_dispatched(): void
    {
        [$owner, $project] = $this->uploadProject();
        $bytes = "%PDF-1.4\nWebhook ordering\n%%EOF\n";
        $atDispatch = [];
        config()->set('services.n8n.document_webhook_url', 'https://legacy-provider.test/extract');
        Http::fake(function ($request) use (&$atDispatch) {
            $fields = collect($request->data())->pluck('contents', 'name');
            $document = ProjectDocument::query()->findOrFail((int) $fields['document_id']);
            // Capture persisted state during the outbound invocation, before
            // the controller receives the provider's response.
            $atDispatch[] = [
                'url' => $request->url(),
                'project_id' => (int) $fields['project_id'],
                'document_id' => $document->id,
                'revisions' => $document->versions()->pluck('revision_no')->all(),
                'version' => $document->initialVersion?->getRawOriginal(),
                'bytes' => Storage::disk($document->storage_disk)->get($document->path),
            ];

            return Http::response(['accepted' => true]);
        });

        $this->actingAs($owner)->post("/projects/{$project->id}/documents", [
            'document' => UploadedFile::fake()->createWithContent('new.pdf', $bytes),
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('warning');

        Http::assertSentCount(1);
        $this->assertCount(1, $atDispatch);
        $observed = $atDispatch[0];
        $this->assertSame('https://legacy-provider.test/extract', $observed['url']);
        $this->assertSame($project->id, $observed['project_id']);
        $this->assertSame([1], $observed['revisions']);
        $this->assertSame($observed['document_id'], $observed['version']['project_document_id']);
        $this->assertSame(DocumentVersionCreatedVia::LegacyUpload->value, $observed['version']['created_via']);
        $this->assertSame(hash('sha256', $bytes), $observed['version']['sha256']);
        $this->assertSame($bytes, $observed['bytes']);
        $this->assertSame($observed['version'], DocumentVersion::query()->sole()->getRawOriginal());
    }

    public function test_failed_upload_registration_rolls_back_metadata_and_removes_only_the_new_file(): void
    {
        [$owner, $project] = $this->uploadProject();
        $import = $this->createDocumentImport($owner);
        $bytes = Storage::disk('project-imports')->get($import->storage_path);
        Storage::disk('local')->put('documents/existing.txt', 'existing bytes');
        $this->mock(DocumentBlobVerifier::class)->shouldReceive('verifyDocument')->once()
            ->andThrow(new ApiProblemException('Injected verification failure.', 'document_version_integrity_failed', 409));

        $this->actingAs($owner)->post("/projects/{$project->id}/documents", [
            'document' => UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4\nNew upload\n%%EOF\n"),
        ])->assertRedirect()->assertSessionHasErrors('document');

        $this->assertDatabaseCount('project_documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(['documents/existing.txt'], Storage::disk('local')->allFiles());
        $this->assertSame('existing bytes', Storage::disk('local')->get('documents/existing.txt'));
        $this->assertSame($bytes, Storage::disk('project-imports')->get($import->storage_path));
        Http::assertNothingSent();
    }

    public function test_partial_storage_exception_is_caught_and_compensated_before_metadata_creation(): void
    {
        [$owner, $project] = $this->uploadProject();
        $source = UploadedFile::fake()->createWithContent('new.txt', 'new upload');
        $file = new class($source->getPathname(), 'new.txt', 'text/plain', null, true) extends UploadedFile
        {
            public function storeAs($path, $name = null, $options = [])
            {
                Storage::disk($options)->put($path.'/'.$name, 'partial bytes');
                throw new RuntimeException('Injected storage failure.');
            }
        };

        $this->actingAs($owner)->post("/projects/{$project->id}/documents", ['document' => $file])
            ->assertRedirect()->assertSessionHasErrors('document');

        $this->assertDatabaseCount('project_documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_existing_upload_destination_is_never_overwritten_or_compensated(): void
    {
        [$owner, $project] = $this->uploadProject();
        $file = UploadedFile::fake()->createWithContent('new.txt', 'new upload');
        $path = "project-documents/{$project->id}/".$file->hashName();
        Storage::disk('local')->put($path, 'existing bytes');

        $this->actingAs($owner)->post("/projects/{$project->id}/documents", ['document' => $file])
            ->assertRedirect()->assertSessionHasErrors('document');

        $this->assertDatabaseCount('project_documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame('existing bytes', Storage::disk('local')->get($path));
    }

    /** @return array{User, Project} */
    private function uploadProject(): array
    {
        $owner = $this->phaseFiveUser();
        $project = Project::query()->create([
            'name' => 'Document upload foundation',
            'objective' => 'Register the verified source with its upload.',
            'budget' => 0,
            'actual_spent' => 0,
            'user_id' => $owner->id,
            'department_id' => $this->importDepartment->id,
            'project_category_id' => $this->importCategory->id,
            'academic_year_id' => $this->importAcademicYear->id,
            'fiscal_year_id' => $this->importFiscalYear->id,
            'project_status_id' => ProjectStatus::query()->where('code', 'draft')->value('id'),
        ]);

        return [$owner, $project];
    }
}
