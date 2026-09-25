<?php

namespace Tests\Feature;

use App\Enums\DocumentImportStatus;
use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Models\DocumentImport;
use App\Models\DocumentVersion;
use App\Models\SignatureAsset;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Services\Signatures\SignatureAssetService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\Support\SignatureAssetApiTestCase;
use Tests\Support\SignaturePngFixture;

class SignatureAssetDocumentIsolationTest extends SignatureAssetApiTestCase
{
    use BuildsPhaseFiveImports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhaseFiveImports();
        Storage::fake('local');
        Storage::fake('project-imports');
        config()->set('document_versions.temporary_directory', $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'document-downloads');
    }

    public function test_signature_roots_are_outside_document_disks_public_roots_and_configured_links(): void
    {
        // Inspect the deployed roots as well as the private test override.
        $defaults = require config_path('signature_assets.php');
        $roots = [$defaults['storage_root'], $defaults['temporary_directory'], config('signature_assets.storage_root')];
        $disks = config('filesystems.disks');
        $this->assertNotContains('signature-assets', config('document_versions.allowed_disks'));
        $this->assertArrayNotHasKey('signature-assets', $disks);
        foreach ($roots as $root) {
            $this->assertFalse($this->withinRoot($root, public_path()));
            foreach ($disks as $disk) {
                if (($disk['driver'] ?? null) === 'local') {
                    $this->assertFalse($this->withinRoot($root, $disk['root']), 'No registered filesystem disk may contain signature storage.');
                }
            }
            foreach (config('filesystems.links') as $target) {
                $this->assertFalse($this->withinRoot($root, $target), 'No configured public link may expose signature storage.');
            }
        }
        foreach (config('document_versions.allowed_disks') as $disk) {
            $this->assertArrayHasKey($disk, $disks);
            $this->assertSame('local', $disks[$disk]['driver']);
        }
    }

    public function test_signature_api_cannot_resolve_document_ids_or_query_supplied_document_locators(): void
    {
        $owner = $this->phaseFiveUser();
        $import = $this->createDocumentImport($owner);
        $version = $this->documentVersion($owner);
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $signatureBytes = file_get_contents($this->assetPath($asset));
        $documentBytes = Storage::disk($version->storage_disk)->get($version->storage_path);
        $this->actingAs($owner);

        foreach ([$import->public_id, $version->public_id, $asset->storage_key] as $identifier) {
            $this->getJson('/api/v2/signature-assets/'.$identifier)->assertNotFound();
            $response = $this->getJson('/api/v2/signature-assets/'.$identifier.'/preview')->assertNotFound();
            $this->assertStringNotContainsString($documentBytes, $response->getContent());
        }
        $query = http_build_query([
            'disk' => $version->storage_disk, 'storage_disk' => $version->storage_disk,
            'path' => $version->storage_path, 'storage_key' => $version->storage_path,
            'public_id' => $version->public_id,
        ]);
        $this->get('/api/v2/signature-assets/'.$asset->public_id.'/preview?'.$query)
            ->assertOk()->assertContent($signatureBytes);
        $this->getJson('/api/v2/signature-assets?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $asset->public_id);
        $this->assertSame($documentBytes, Storage::disk($version->storage_disk)->get($version->storage_path));
    }

    public function test_signature_preview_does_not_search_document_disks_even_for_exact_name_hash_and_png_metadata(): void
    {
        $owner = $this->phaseFiveUser();
        $png = SignaturePngFixture::rgba();
        $key = bin2hex(random_bytes(32)).'.png';
        Storage::disk('local')->put($key, $png);
        Storage::disk('project-imports')->put($key, $png);
        $asset = SignatureAsset::query()->create([
            'public_id' => (string) Str::uuid(), 'owner_id' => $owner->id, 'storage_key' => $key,
            'sha256' => hash('sha256', $png), 'size_bytes' => strlen($png), 'width' => 2, 'height' => 2,
            'mime_type' => 'image/png', 'normalization_version' => SignatureAsset::NORMALIZATION_VERSION,
        ]);

        $response = $this->actingAs($owner)->getJson('/api/v2/signature-assets/'.$asset->public_id.'/preview')
            ->assertStatus(503)->assertJsonPath('code', 'signature_asset_unavailable');
        $this->assertStringNotContainsString($png, $response->getContent());
        $this->assertStringNotContainsString($key, $response->getContent());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'signature_asset.previewed']);
        $this->assertSame($png, Storage::disk('local')->get($key));
        $this->assertSame($png, Storage::disk('project-imports')->get($key));
        $this->assertSame([], $this->signatureStoredPngs());
    }

    public function test_document_download_and_import_original_ignore_signature_ids_and_locator_query_overrides(): void
    {
        $owner = $this->phaseFiveUser();
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $version = $this->documentVersion($owner);
        $import = $this->createDocumentImport($owner);
        $query = http_build_query([
            'disk' => 'signature-assets', 'storage_disk' => 'signature-assets',
            'storage_key' => $asset->storage_key, 'storage_path' => $this->assetPath($asset),
            'path' => $this->assetPath($asset), 'public_id' => $asset->public_id,
        ]);
        $this->actingAs($owner)->get($this->documentUrl($version).'?'.$query)->assertOk()
            ->assertStreamedContent(Storage::disk($version->storage_disk)->get($version->storage_path));
        $this->get('/api/v2/imports/'.$import->public_id.'/original?'.$query)->assertOk()
            ->assertStreamedContent(Storage::disk($import->storage_disk)->get($import->storage_path));
        $this->getJson(str_replace($version->public_id, $asset->public_id, $this->documentUrl($version)))->assertNotFound();
        $this->getJson('/api/v2/imports/'.$asset->public_id.'/original')->assertNotFound();
        $this->assertSame($asset->sha256, hash_file('sha256', $this->assetPath($asset)));
    }

    public function test_document_versions_with_signature_locators_and_matching_metadata_fail_without_signature_bytes(): void
    {
        $owner = $this->phaseFiveUser();
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $png = file_get_contents($this->assetPath($asset));
        $this->actingAs($owner);
        foreach ([
            ['signature-assets', $asset->storage_key, 'document_version_storage_invalid'],
            ['local', '../private/signature-assets/'.$asset->storage_key, 'document_version_storage_invalid'],
            ['local', $this->assetPath($asset), 'document_version_storage_invalid'],
            ['local', $asset->storage_key, 'document_version_unavailable'],
            ['project-imports', $asset->storage_key, 'document_version_unavailable'],
        ] as [$disk, $path, $code]) {
            $version = $this->documentVersion($owner, [
                'storage_disk' => $disk, 'storage_path' => $path, 'mime_type' => 'image/png',
                'size_bytes' => $asset->size_bytes, 'sha256' => $asset->sha256,
            ]);
            $response = $this->getJson($this->documentUrl($version))->assertConflict()->assertJsonPath('code', $code);
            $this->assertFalse($response->headers->has('Content-Disposition'));
            foreach ([$png, $asset->storage_key, $asset->sha256, $this->signatureTestDirectory] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $this->assertSame($png, file_get_contents($this->assetPath($asset)));
    }

    public function test_import_original_with_unregistered_signature_disk_or_parent_traversal_cannot_return_signature_bytes(): void
    {
        $owner = $this->phaseFiveUser();
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $png = file_get_contents($this->assetPath($asset));
        foreach ([
            ['signature-assets', $asset->storage_key],
            ['local', '../private/signature-assets/'.$asset->storage_key],
        ] as [$disk, $path]) {
            // Insert corrupt historical metadata directly; never create a disk alias or copy signature bytes.
            $import = DocumentImport::query()->create([
                'uploaded_by' => $owner->id, 'uploader_department_id' => $owner->department_id,
                'status' => DocumentImportStatus::Uploaded, 'original_name' => 'misdirected.pdf',
                'storage_disk' => $disk, 'storage_path' => $path, 'mime_type' => 'application/pdf',
                'size_bytes' => $asset->size_bytes, 'sha256' => $asset->sha256,
            ]);
            $response = $this->actingAs($owner)->getJson('/api/v2/imports/'.$import->public_id.'/original')
                ->assertStatus(500)->assertJsonPath('code', 'server_error');
            $this->assertFalse($response->headers->has('Content-Disposition'));
            foreach ([$png, $asset->storage_key, $asset->sha256, $this->signatureTestDirectory] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $this->assertSame($png, file_get_contents($this->assetPath($asset)));
    }

    public function test_signature_upload_creates_no_public_file_link_or_public_url(): void
    {
        $owner = $this->phaseFiveUser();
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $metadata = $this->actingAs($owner)->getJson('/api/v2/signature-assets/'.$asset->public_id)->assertOk();
        $this->assertSafeAssetMetadata($metadata->json('data'), $asset);
        foreach (['url', 'download_url', 'storage_url', 'path', 'storage_disk', 'storage_key'] as $field) {
            $metadata->assertJsonMissingPath('data.'.$field);
        }
        $this->assertFalse(is_link($this->assetPath($asset)));
        foreach (['storage/'.$asset->storage_key, 'storage/signature-assets/'.$asset->storage_key, 'signature-assets/'.$asset->storage_key] as $path) {
            $this->assertFileDoesNotExist(public_path($path));
            $this->assertFalse(is_link(public_path($path)));
            $this->getJson('/'.$path)->assertNotFound();
        }
        $this->assertFileDoesNotExist(storage_path('app/public/'.$asset->storage_key));
        $this->assertFileDoesNotExist(storage_path('app/public/signature-assets/'.$asset->storage_key));
        $this->assertCount(1, $this->signatureStoredPngs());
    }

    private function documentVersion(User $owner, array $overrides = []): DocumentVersion
    {
        $payload = $this->validImportPreviewPayload();
        unset($payload['indicators']);
        $project = app(ProjectService::class)->create($owner, $payload);
        $bytes = "%PDF-1.4\nDocument isolation source bytes\n%%EOF\n";
        $path = 'isolation/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $document = $project->documents()->create([
            'original_name' => 'isolation.pdf', 'path' => $path, 'storage_disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => strlen($bytes), 'uploaded_by' => $owner->id,
            'checksum' => hash('sha256', $bytes),
        ]);

        return $document->versions()->create(array_replace([
            'revision_no' => 1, 'created_via' => DocumentVersionCreatedVia::Backfill,
            'storage_disk' => 'local', 'storage_path' => $path, 'original_name' => 'isolation.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'integrity_basis' => DocumentVersionIntegrityBasis::RecordedSha256, 'created_by' => null,
            'verified_at' => now(),
        ], $overrides));
    }

    private function documentUrl(DocumentVersion $version): string
    {
        return route('api.v2.projects.documents.versions.download', [
            'project' => $version->document->project_id,
            'projectDocument' => $version->project_document_id,
            'documentVersion' => $version->public_id,
        ], false);
    }

    private function withinRoot(string $path, string $root): bool
    {
        $normalize = static fn (string $value): string => strtolower(rtrim(str_replace('\\', '/', $value), '/'));
        $path = $normalize($path);
        $root = $normalize($root);

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
