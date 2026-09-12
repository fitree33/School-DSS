<?php

namespace Tests\Feature\Api\V2;

use App\Enums\DocumentImportStatus;
use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Services\Documents\DocumentVersionDownloadService;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\TestCase;

class DocumentVersionDownloadTest extends TestCase
{
    use BuildsPhaseFiveImports;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPhaseFiveImports();
        Storage::fake('project-imports');
        Storage::fake('version-download-source');
        Storage::fake('version-download-temp');
        config()->set('filesystems.disks.version-download-source', [
            'driver' => 'local',
            'root' => Storage::disk('version-download-source')->path(''),
            'visibility' => 'private',
        ]);
        config()->set('document_versions.allowed_disks', ['local', 'project-imports', 'version-download-source']);
        config()->set('document_versions.temporary_directory', Storage::disk('version-download-temp')->path(''));
    }

    public function test_project_detail_exposes_authorized_initial_version_and_downloads_verified_pdf_without_locator_leakage(): void
    {
        $owner = $this->phaseFiveUser();
        [$project, $document, $version, $bytes] = $this->versionFixture($owner);

        $detail = $this->actingAs($owner)->getJson("/api/v2/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.documents.0.initial_version.public_id', $version->public_id)
            ->assertJsonPath('data.documents.0.initial_version.revision_no', 1)
            ->assertJsonPath('data.documents.0.initial_version.download_url', $this->url($version))
            ->assertJsonPath('data.documents.0.download_url', null);

        foreach (['path', 'storage_path', 'storage_disk', 'sha256', 'checksum'] as $field) {
            $detail->assertJsonMissingPath("data.documents.0.initial_version.{$field}");
        }
        $this->assertStringNotContainsString($document->path, $detail->getContent());

        $download = $this->get($detail->json('data.documents.0.initial_version.download_url'))
            ->assertOk()->assertDownload('baseline.pdf')
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Length', (string) strlen($bytes))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
        $this->assertTrue($download->headers->hasCacheControlDirective('private'));
        $this->assertTrue($download->headers->hasCacheControlDirective('no-store'));
        $download->assertStreamedContent($bytes);
        $this->assertSame([], Storage::disk('version-download-temp')->allFiles());
        $this->assertSame($bytes, Storage::disk($version->storage_disk)->get($version->storage_path));
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_non_pdf_bytes_keep_their_mime_type_and_unicode_filename_is_sanitized(): void
    {
        $owner = $this->phaseFiveUser();
        [, , $version, $bytes] = $this->versionFixture(
            $owner,
            bytes: "เอกสารโครงการ\nPlain text baseline\n",
            overrides: ['original_name' => "รายงาน/โครงการ\\100%\r\n.txt", 'mime_type' => 'text/plain'],
        );

        $response = $this->actingAs($owner)->get($this->url($version))
            ->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertStreamedContent($bytes);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString(rawurlencode('รายงาน_โครงการ_100%__.txt'), $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertSame([], Storage::disk('version-download-temp')->allFiles());
    }

    public function test_authentication_and_project_authorization_run_before_reading_file_integrity(): void
    {
        $owner = $this->phaseFiveUser();
        $other = $this->phaseFiveUser();
        [, , $version] = $this->versionFixture($owner);
        Storage::disk($version->storage_disk)->delete($version->storage_path);

        $this->getJson($this->url($version))->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->actingAs($other)->getJson($this->url($version))->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->assertSame([], Storage::disk('version-download-temp')->allFiles());
    }

    public function test_nested_route_rejects_cross_project_and_cross_document_identifiers_and_unknown_uuid(): void
    {
        $owner = $this->phaseFiveUser();
        [$project, $document, $version] = $this->versionFixture($owner);
        [$otherProject, $otherDocument, $otherVersion] = $this->versionFixture($owner);

        $this->actingAs($owner);
        foreach ([
            "/api/v2/projects/{$otherProject->id}/documents/{$document->id}/versions/{$version->public_id}/download",
            "/api/v2/projects/{$project->id}/documents/{$document->id}/versions/{$otherVersion->public_id}/download",
            "/api/v2/projects/{$project->id}/documents/{$otherDocument->id}/versions/{$version->public_id}/download",
            "/api/v2/projects/{$project->id}/documents/{$document->id}/versions/".Str::uuid().'/download',
        ] as $url) {
            $this->getJson($url)->assertNotFound()->assertJsonPath('code', 'not_found');
        }
        $this->assertSame([], Storage::disk('version-download-temp')->allFiles());
    }

    public function test_inactive_owner_cannot_download_even_with_project_access(): void
    {
        $owner = $this->phaseFiveUser();
        [, , $version] = $this->versionFixture($owner);
        $owner->update(['is_active' => false]);
        Storage::disk($version->storage_disk)->delete($version->storage_path);

        $this->actingAs($owner)->getJson($this->url($version))->assertForbidden();
        $this->assertSame([], Storage::disk('version-download-temp')->allFiles());
    }

    public function test_temporary_storage_failure_returns_no_attachment_bytes_or_private_locator(): void
    {
        $owner = $this->phaseFiveUser();
        [, , $version, $bytes] = $this->versionFixture($owner);
        Storage::disk('version-download-temp')->put('blocked', 'not a directory');
        config()->set('document_versions.temporary_directory', Storage::disk('version-download-temp')->path('blocked/child'));

        $response = $this->actingAs($owner)->getJson($this->url($version))
            ->assertStatus(503)->assertJsonPath('code', 'document_version_download_unavailable');

        $this->assertFalse($response->headers->has('Content-Disposition'));
        $this->assertStringNotContainsString($version->storage_path, $response->getContent());
        $this->assertStringNotContainsString('blocked/child', $response->getContent());
        $this->assertSame(['blocked'], Storage::disk('version-download-temp')->allFiles());
        $this->assertSame($bytes, Storage::disk($version->storage_disk)->get($version->storage_path));
    }

    public function test_imported_versions_require_import_acl_in_addition_to_project_access(): void
    {
        $owner = $this->phaseFiveUser();
        $shared = $this->phaseFiveUser();
        [$project, , $version, $bytes] = $this->versionFixture($owner, imported: true);
        $this->shareProject($project, $owner, $shared);

        $this->actingAs($shared)->getJson("/api/v2/projects/{$project->id}")->assertOk()
            ->assertJsonPath('data.documents.0.download_url', null)
            ->assertJsonPath('data.documents.0.initial_version', null);
        $this->getJson($this->url($version))->assertForbidden();

        foreach ([$owner, $this->phaseFiveUser('department_head'), $this->phaseFiveUser('director', $this->otherImportDepartment)] as $allowed) {
            $this->actingAs($allowed)->get($this->url($version))->assertOk()->assertStreamedContent($bytes);
        }
        $this->actingAs($this->phaseFiveUser('department_head', $this->otherImportDepartment))
            ->getJson($this->url($version))->assertForbidden();
    }

    public function test_import_access_alone_does_not_grant_access_to_a_different_owners_project(): void
    {
        $uploader = $this->phaseFiveUser();
        $projectOwner = $this->phaseFiveUser();
        [, , $version] = $this->versionFixture($projectOwner, imported: true, importUploader: $uploader);

        $this->actingAs($uploader)->getJson($this->url($version))->assertForbidden();
        $this->actingAs($projectOwner)->getJson($this->url($version))->assertForbidden();
    }

    public function test_legacy_shared_project_can_download_but_unregistered_documents_have_no_initial_version(): void
    {
        $owner = $this->phaseFiveUser();
        $viewer = $this->phaseFiveUser();
        [$project, , $version, $bytes] = $this->versionFixture($owner);
        $this->shareProject($project, $owner, $viewer);
        $project->documents()->create([
            'original_name' => 'not-backfilled.txt', 'path' => 'legacy/unregistered.txt', 'uploaded_by' => $owner->id,
        ]);

        $this->actingAs($viewer)->get($this->url($version))->assertOk()->assertStreamedContent($bytes);
        $this->getJson("/api/v2/projects/{$project->id}")->assertOk()
            ->assertJsonPath('data.documents.1.initial_version', null);
    }

    public function test_missing_size_changed_and_same_size_tampered_sources_fail_without_exposing_locators_or_returning_bytes(): void
    {
        $owner = $this->phaseFiveUser();
        foreach (['missing', 'shorter', 'longer', 'same_size_tampered'] as $failure) {
            [, , $version, $bytes] = $this->versionFixture($owner);
            $disk = Storage::disk($version->storage_disk);
            if ($failure === 'missing') {
                $disk->delete($version->storage_path);
            } else {
                $changed = match ($failure) {
                    'shorter' => substr($bytes, 0, -1),
                    'longer' => $bytes.'X',
                    default => str_replace('baseline', 'tampered', $bytes),
                };
                $disk->put($version->storage_path, $changed);
            }

            $response = $this->actingAs($owner)->getJson($this->url($version))->assertConflict()
                ->assertJsonPath('code', $failure === 'missing' ? 'document_version_unavailable' : 'document_version_integrity_failed');
            $this->assertStringNotContainsString($version->storage_disk, $response->getContent());
            $this->assertStringNotContainsString($version->storage_path, $response->getContent());
            $this->assertFalse($response->headers->has('Content-Disposition'));
            $this->assertSame([], Storage::disk('version-download-temp')->allFiles());
        }
    }

    public function test_invalid_storage_locators_fail_closed(): void
    {
        $owner = $this->phaseFiveUser();
        foreach ([['storage_path' => '../outside.pdf'], ['storage_disk' => 'missing-disk']] as $overrides) {
            [, , $version] = $this->versionFixture($owner, overrides: $overrides);
            $response = $this->actingAs($owner)->getJson($this->url($version))->assertConflict()
                ->assertJsonPath('code', 'document_version_storage_invalid');
            $this->assertStringNotContainsString($version->storage_path, $response->getContent());
        }
        $this->assertSame([], Storage::disk('version-download-temp')->allFiles());
    }

    public function test_verified_response_uses_only_temporary_bytes_when_the_original_changes_before_streaming(): void
    {
        $owner = $this->phaseFiveUser();
        [, , $version, $bytes] = $this->versionFixture($owner);
        $response = app(DocumentVersionDownloadService::class)->download($version);
        $this->assertCount(1, Storage::disk('version-download-temp')->allFiles());
        Storage::disk($version->storage_disk)->put($version->storage_path, 'Changed after verification.');

        ob_start();
        try {
            $response->sendContent();
            $actual = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertSame($bytes, $actual);
        $this->assertSame([], Storage::disk('version-download-temp')->allFiles());
    }

    public function test_abandoned_response_releases_temporary_file_without_creating_permanent_duplicate(): void
    {
        [, , $version] = $this->versionFixture($this->phaseFiveUser());
        $response = app(DocumentVersionDownloadService::class)->download($version);
        $this->assertCount(1, Storage::disk('version-download-temp')->allFiles());
        unset($response);
        gc_collect_cycles();

        $this->assertSame([], Storage::disk('version-download-temp')->allFiles());
        $this->assertCount(1, Storage::disk('version-download-source')->allFiles());
    }

    public function test_new_integrity_contract_does_not_change_phase_five_original_download_behavior(): void
    {
        $owner = $this->phaseFiveUser();
        [, $document, $version] = $this->versionFixture($owner, imported: true);
        $changed = "%PDF-1.4\nChanged outside application\n%%EOF\n";
        Storage::disk($version->storage_disk)->put($version->storage_path, $changed);

        $this->actingAs($owner)->getJson($this->url($version))->assertConflict()
            ->assertJsonPath('code', 'document_version_integrity_failed');
        $this->get("/api/v2/imports/{$document->sourceImport->public_id}/original")
            ->assertOk()->assertDownload('baseline.pdf')->assertStreamedContent($changed);
    }

    public function test_fiscal_lock_keeps_version_readable_and_soft_deleted_project_is_not_accessible(): void
    {
        $owner = $this->phaseFiveUser();
        [$project, , $version, $bytes] = $this->versionFixture($owner);
        $this->importFiscalYear->update(['is_locked' => true]);
        $this->actingAs($owner)->get($this->url($version))->assertOk()->assertStreamedContent($bytes);
        $project->delete();

        $this->getJson($this->url($version))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->assertDatabaseHas('document_versions', ['id' => $version->id]);
        $this->assertSame($bytes, Storage::disk($version->storage_disk)->get($version->storage_path));
    }

    /** @return array{Project, ProjectDocument, DocumentVersion, string} */
    private function versionFixture(
        User $owner,
        bool $imported = false,
        string $bytes = "%PDF-1.4\nVerified baseline document\n%%EOF\n",
        array $overrides = [],
        ?User $importUploader = null,
    ): array {
        $payload = $this->validImportPreviewPayload();
        unset($payload['indicators']);
        $project = app(ProjectService::class)->create($owner, $payload);
        $disk = $imported ? 'project-imports' : 'version-download-source';
        $path = 'originals/'.Str::uuid().'.pdf';
        Storage::disk($disk)->put($path, $bytes);
        $sourceImport = $imported ? $this->createDocumentImport(
            $importUploader ?? $owner,
            DocumentImportStatus::Confirmed,
            [
                'confirmed_project_id' => $project->id,
                'confirmed_by' => $owner->id,
                'confirmed_at' => now(),
                'storage_disk' => $disk,
                'storage_path' => $path,
                'original_name' => 'baseline.pdf',
            ],
            $bytes,
        ) : null;
        $document = $project->documents()->create([
            'source_import_id' => $sourceImport?->id,
            'original_name' => $overrides['original_name'] ?? 'baseline.pdf',
            'path' => $path,
            'storage_disk' => $disk,
            'mime_type' => $overrides['mime_type'] ?? 'application/pdf',
            'size' => strlen($bytes),
            'uploaded_by' => ($importUploader ?? $owner)->id,
            'checksum' => hash('sha256', $bytes),
            'version' => 7,
        ]);
        $version = $document->versions()->create(array_merge([
            'revision_no' => 1,
            'created_via' => DocumentVersionCreatedVia::Backfill,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'integrity_basis' => DocumentVersionIntegrityBasis::RecordedSha256,
            'created_by' => null,
            'verified_at' => now(),
        ], $overrides));

        return [$project, $document, $version, $bytes];
    }

    private function url(DocumentVersion $version): string
    {
        return route('api.v2.projects.documents.versions.download', [
            'project' => $version->document->project_id,
            'projectDocument' => $version->project_document_id,
            'documentVersion' => $version->public_id,
        ], false);
    }

    private function shareProject(Project $project, User $owner, User $viewer): void
    {
        ProjectAccess::query()->create([
            'project_id' => $project->id,
            'user_id' => $viewer->id,
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'granted_by' => $owner->id,
        ]);
    }
}
