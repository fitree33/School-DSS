<?php

namespace Tests\Feature\Api\V2;

use App\Contracts\Signatures\SignatureImageNormalizer;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SignatureAsset;
use App\Models\User;
use App\Policies\SignatureAssetPolicy;
use App\Services\Signatures\SignatureAssetService;
use App\Services\Signatures\SignatureAssetStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SignatureAssetApiTestCase;
use Tests\Support\SignaturePngFixture;

class SignatureAssetApiTest extends SignatureAssetApiTestCase
{
    public function test_owner_upload_list_metadata_preview_and_retirement_preserve_normalized_bytes_and_private_metadata(): void
    {
        $owner = $this->assetUser();
        $other = $this->assetUser();
        $foreign = app(SignatureAssetService::class)->upload($other, $this->assetUpload());
        $source = SignaturePngFixture::rgba(SignaturePngFixture::chunk('tEXt', "Comment\0private-original-marker"));
        $upload = $this->assetUpload($source);
        $response = $this->actingAs($owner)->postJson('/api/v2/signature-assets', [
            'image' => $upload, 'owner_id' => $other->id, 'storage_key' => 'injected.png',
            'sha256' => str_repeat('0', 64), 'status' => 'retired',
        ], ['User-Agent' => 'private-original-marker']);
        $response->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.eligible_for_signing', true);
        $asset = SignatureAsset::query()->where('public_id', $response->json('data.public_id'))->firstOrFail();
        $this->assertSame($owner->id, $asset->owner_id);
        $this->assertSafeAssetMetadata($response->json('data'), $asset);
        $stored = file_get_contents($this->assetPath($asset));
        $this->assertIsString($stored);
        $this->assertNotSame($source, $stored);
        $this->assertSame(['IHDR', 'IDAT', 'IEND'], array_column(SignaturePngFixture::chunks($stored), 'type'));
        $this->assertSame(hash('sha256', $stored), $asset->sha256);
        $this->assertSame(strlen($stored), $asset->size_bytes);
        $this->assertSame(2, $asset->width);
        $this->assertSame(2, $asset->height);
        $this->assertSame('image/png', $asset->mime_type);
        foreach (glob(config('signature_assets.storage_root').DIRECTORY_SEPARATOR.'*') as $path) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\.png(?:\.json|\.lock)?\z/D', basename($path));
            $this->assertStringNotContainsString('private-original-marker', file_get_contents($path));
        }
        foreach ([$source, $asset->storage_key, $asset->sha256, $this->assetPath($asset), $upload->getPathname()] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        $list = $this->getJson('/api/v2/signature-assets?owner_id='.$other->id)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($asset->public_id, $list->json('data.0.public_id'));
        $this->assertStringNotContainsString($foreign->public_id, $list->getContent());
        $this->assertSafeAssetMetadata($list->json('data.0'), $asset);
        $metadata = $this->getJson('/api/v2/signature-assets/'.$asset->public_id)->assertOk();
        $this->assertSafeAssetMetadata($metadata->json('data'), $asset);
        $preview = $this->get('/api/v2/signature-assets/'.$asset->public_id.'/preview', ['Accept' => 'image/png'])->assertOk();
        $preview->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $preview->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $preview->headers->get('Cache-Control'));
        $this->assertSame($stored, $preview->getContent());

        $this->postJson('/api/v2/signature-assets/'.$asset->public_id.'/retire', ['reason' => 'private-retirement-marker'])
            ->assertOk()->assertJsonPath('data.status', 'retired')->assertJsonPath('data.eligible_for_signing', false);
        $retired = $asset->refresh()->getRawOriginal();
        $this->assertSame($owner->id, $asset->retired_by);
        $this->assertSame('private-retirement-marker', $asset->retirement_reason);
        $this->getJson('/api/v2/signature-assets/'.$asset->public_id)->assertOk()->assertJsonPath('data.eligible_for_signing', false);
        $this->get('/api/v2/signature-assets/'.$asset->public_id.'/preview')->assertOk()->assertContent($stored);
        $this->postJson('/api/v2/signature-assets/'.$asset->public_id.'/retire', ['reason' => 'second reason'])->assertOk();
        $this->assertSame($retired, $asset->refresh()->getRawOriginal());
        $this->assertSame($stored, file_get_contents($this->assetPath($asset)));
        $this->assertFalse($asset->isEligibleForSigning());

        $audits = AuditLog::query()->where('auditable_type', SignatureAsset::class)->where('auditable_id', $asset->id)->orderBy('id')->get();
        $this->assertSame(['signature_asset.uploaded', 'signature_asset.previewed', 'signature_asset.retired', 'signature_asset.previewed'], $audits->pluck('action')->all());
        foreach ($audits as $audit) {
            $this->assertSame($owner->id, $audit->user_id);
            $this->assertSame(['public_id', 'status'], array_keys($audit->new_values));
            $this->assertSame($asset->public_id, $audit->new_values['public_id']);
            $this->assertNull($audit->ip_address);
            $this->assertNull($audit->user_agent);
            $this->assertContains($audit->old_values, [null, ['status' => 'active']]);
        }
        $auditJson = $audits->toJson();
        foreach ([$asset->storage_key, $asset->sha256, base64_encode($source), 'private-original-marker', 'private-retirement-marker', $this->assetPath($asset), $upload->getPathname()] as $secret) {
            $this->assertStringNotContainsString($secret, $auditJson);
        }
        $this->assertSignatureTemporaryEmpty();
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_cross_owner_uuid_is_hidden_from_admin_director_deputy_and_project_manager_without_role_bypass(): void
    {
        $owner = $this->assetUser();
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $before = $asset->refresh()->getRawOriginal();
        $audits = AuditLog::query()->count();
        foreach (['admin', 'director', 'deputy_director', 'project_manager'] as $roleCode) {
            $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => 'Privileged fixture '.$roleCode]);
            $role->permissions()->syncWithoutDetaching(Permission::query()->pluck('id')->all());
            $actor = $this->assetUser(['role_id' => $role->id]);
            $this->assertTrue($actor->hasPermission('users.manage'));
            $this->assertTrue($actor->hasPermission('projects.edit_all'));
            $this->actingAs($actor)->getJson('/api/v2/signature-assets?owner_id='.$owner->id)->assertOk()->assertJsonCount(0, 'data');
            $denied = [];
            foreach ([$asset->public_id, (string) Str::uuid()] as $publicId) {
                $denied[] = [
                    $this->getJson('/api/v2/signature-assets/'.$publicId)->assertNotFound()->json(),
                    $this->getJson('/api/v2/signature-assets/'.$publicId.'/preview')->assertNotFound()->json(),
                    $this->postJson('/api/v2/signature-assets/'.$publicId.'/retire', ['reason' => ['invalid input']])->assertNotFound()->json(),
                ];
            }
            $this->assertSame($denied[0], $denied[1]);
        }
        $this->assertSame($before, $asset->refresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', $audits);
        $this->assertCount(1, $this->signatureStoredPngs());
    }

    public function test_every_endpoint_requires_authentication_and_an_active_actor(): void
    {
        $publicId = (string) Str::uuid();
        $endpoints = [
            ['GET', '/api/v2/signature-assets'],
            ['POST', '/api/v2/signature-assets'],
            ['GET', '/api/v2/signature-assets/'.$publicId],
            ['GET', '/api/v2/signature-assets/'.$publicId.'/preview'],
            ['POST', '/api/v2/signature-assets/'.$publicId.'/retire'],
        ];
        foreach ($endpoints as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }
        $inactive = $this->assetUser(['is_active' => false]);
        foreach ($endpoints as [$method, $url]) {
            $this->actingAs($inactive)->json($method, $url)->assertForbidden()->assertJsonPath('code', 'account_inactive');
        }
        $this->assertDatabaseCount('signature_assets', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], $this->signatureStoredPngs());
    }

    public function test_malformed_oversized_and_missing_uploads_leave_no_asset_audit_or_request_workspace(): void
    {
        $this->actingAs($this->assetUser());
        $this->postJson('/api/v2/signature-assets')->assertUnprocessable();
        $this->postJson('/api/v2/signature-assets', ['image' => $this->assetUpload('not a PNG')])->assertUnprocessable();
        $this->postJson('/api/v2/signature-assets', ['image' => $this->assetUpload(str_repeat('x', 2097153))])
            ->assertStatus(413)->assertJsonPath('code', 'signature_upload_too_large');
        $this->assertDatabaseCount('signature_assets', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], $this->signatureStoredPngs());
        $this->assertSignatureTemporaryEmpty();
    }

    public function test_metadata_reason_bounds_and_absent_mutation_routes_cannot_replace_reactivate_or_delete_an_asset(): void
    {
        $owner = $this->assetUser();
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $url = '/api/v2/signature-assets/'.$asset->public_id;
        $before = $asset->refresh()->getRawOriginal();
        $this->actingAs($owner)->postJson($url.'/retire', ['reason' => str_repeat('ก', 501)])->assertUnprocessable();
        $this->assertSame($before, $asset->refresh()->getRawOriginal());
        $this->postJson($url.'/retire', ['reason' => str_repeat('ก', 500)])->assertOk();
        $retired = $asset->refresh()->getRawOriginal();
        $this->patchJson($url, ['status' => 'active'])->assertStatus(405);
        $this->putJson($url, ['sha256' => str_repeat('0', 64)])->assertStatus(405);
        $this->deleteJson($url)->assertStatus(405);
        $this->assertSame($retired, $asset->refresh()->getRawOriginal());
        $this->assertCount(1, $this->signatureStoredPngs());
    }

    #[DataProvider('rejectedApiUploads')]
    public function test_upload_rejects_actual_type_apng_dimensions_and_pixel_limit_without_persisting_input(string $bytes, string $code, int $status): void
    {
        $upload = $this->assetUpload($bytes);
        $response = $this->actingAs($this->assetUser())->postJson('/api/v2/signature-assets', ['image' => $upload]);

        $response->assertStatus($status)->assertJsonPath('code', $code);
        foreach ([$upload->getPathname(), $this->signatureTestDirectory, 'private-original-marker'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertDatabaseCount('signature_assets', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], array_values(array_diff(scandir(config('signature_assets.storage_root')), ['.', '..'])));
        $this->assertSignatureTemporaryEmpty();
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function rejectedApiUploads(): iterable
    {
        $corrupt = SignaturePngFixture::rgba();
        $corrupt[29] = chr(ord($corrupt[29]) ^ 1);
        yield 'PNG CRC mismatch' => [$corrupt, 'signature_image_invalid', 422];
        yield 'ancillary byte limit' => [SignaturePngFixture::rgba(SignaturePngFixture::chunk('vpAg', str_repeat('x', 65525))), 'signature_image_limits_exceeded', 422];
        yield 'GIF bytes with PNG filename and MIME' => ['GIF89a00private-original-marker', 'signature_format_unsupported', 422];
        yield 'JPEG bytes with PNG filename and MIME' => ["\xff\xd8\xff\xe0private-original-marker", 'signature_format_unsupported', 422];
        yield 'APNG animation control' => [SignaturePngFixture::rgba(SignaturePngFixture::chunk('acTL', pack('NN', 1, 0))), 'signature_format_unsupported', 422];
        yield 'APNG frame control' => [SignaturePngFixture::rgba(SignaturePngFixture::chunk('fcTL', str_repeat("\0", 26))), 'signature_format_unsupported', 422];
        yield 'APNG frame data' => [SignaturePngFixture::rgba(SignaturePngFixture::chunk('fdAT', str_repeat("\0", 8))), 'signature_format_unsupported', 422];
        foreach ([[2049, 1], [1, 1025], [2048, 513]] as [$width, $height]) {
            yield "dimensions $width x $height" => [
                SignaturePngFixture::png(SignaturePngFixture::header($width, $height), SignaturePngFixture::chunk('IDAT', gzcompress("\0\xff\0\0\xff")), SignaturePngFixture::chunk('IEND')),
                'signature_image_limits_exceeded', 422,
            ];
        }
    }

    #[DataProvider('untrustedUploadLabels')]
    public function test_upload_uses_actual_png_bytes_instead_of_client_mime_or_extension(string $filename, string $mime): void
    {
        $upload = new UploadedFile($this->signatureUploadFixture(SignaturePngFixture::rgba()), $filename, $mime, UPLOAD_ERR_OK, true);
        $response = $this->actingAs($this->assetUser())->postJson('/api/v2/signature-assets', ['image' => $upload]);
        $response->assertCreated()->assertJsonPath('data.mime_type', 'image/png');
        $asset = SignatureAsset::query()->sole();
        $stored = file_get_contents($this->assetPath($asset));
        $this->assertSame(hash('sha256', $stored), $asset->sha256);
        $this->assertSame(strlen($stored), $asset->size_bytes);
        $this->assertSame(['IHDR', 'IDAT', 'IEND'], array_column(SignaturePngFixture::chunks($stored), 'type'));
        $this->assertSafeAssetMetadata($response->json('data'), $asset);
        $this->assertSignatureTemporaryEmpty();
    }

    public static function untrustedUploadLabels(): array
    {
        // Current contract validates actual content; client labels are untrusted hints.
        return [
            'wrong MIME' => ['signature.png', 'image/jpeg'],
            'wrong extension' => ['signature.jpg', 'image/png'],
            'both wrong' => ['signature.txt', 'text/plain'],
        ];
    }

    public function test_upload_obeys_existing_api_rate_limit_before_staging_or_persistence(): void
    {
        $this->actingAs($this->assetUser());
        $this->travelTo(now('UTC')->startOfMinute());
        try {
            for ($attempt = 0; $attempt < 60; $attempt++) {
                $this->postJson('/api/v2/signature-assets')->assertUnprocessable();
            }
            $response = $this->postJson('/api/v2/signature-assets', ['image' => $this->assetUpload()]);
            $response->assertStatus(429)->assertHeader('X-RateLimit-Limit', '60');
            $this->assertTrue($response->headers->has('Retry-After'));
            $this->assertDatabaseCount('signature_assets', 0);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertSame([], $this->signatureStoredPngs());
            $this->assertSignatureTemporaryEmpty();
        } finally {
            $this->travelBack();
        }
    }

    public function test_preview_active_and_retired_owner_receives_png_with_private_security_headers(): void
    {
        $owner = $this->assetUser();
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $stored = file_get_contents($this->assetPath($asset));
        $this->actingAs($owner);
        foreach (['active', 'retired'] as $state) {
            if ($state === 'retired') {
                $this->postJson('/api/v2/signature-assets/'.$asset->public_id.'/retire')->assertOk();
            }
            $preview = $this->get('/api/v2/signature-assets/'.$asset->public_id.'/preview')->assertOk()->assertContent($stored);
            $preview->assertHeader('Content-Type', 'image/png')
                ->assertHeader('Content-Length', (string) strlen($stored))
                ->assertHeader('Content-Disposition', 'inline; filename="signature.png"')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
                ->assertHeader('Pragma', 'no-cache');
            $this->assertStringContainsString('private', $preview->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $preview->headers->get('Cache-Control'));
            $this->assertSame(hash('sha256', $preview->getContent()), $asset->sha256);
            $this->assertSame($state, AuditLog::query()->where('action', 'signature_asset.previewed')->latest('id')->firstOrFail()->new_values['status']);
            foreach ([$asset->storage_key, $asset->sha256, $this->signatureTestDirectory] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($preview->headers->all(), JSON_THROW_ON_ERROR));
            }
        }
        $this->assertSame($stored, file_get_contents($this->assetPath($asset)));
        $this->assertSame(2, AuditLog::query()->where('action', 'signature_asset.previewed')->count());
    }

    #[DataProvider('previewIntegrityFailures')]
    public function test_preview_rejects_size_hash_and_png_corruption_before_audit_or_image_bytes(string $failure): void
    {
        $owner = $this->assetUser();
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $original = file_get_contents($this->assetPath($asset));
        $bytes = $failure === 'png' ? substr_replace($original, 'X', 0, 1) : $original;
        $this->assertSame(strlen($bytes), file_put_contents($this->assetPath($asset), $bytes));
        // Isolate each required verifier: valid original bytes for size/hash,
        // and matching size/hash for malformed PNG framing. A different failing
        // check must not hide removal of the check this dataset exercises.
        $metadata = match ($failure) {
            'size' => ['size_bytes' => strlen($bytes) + 1],
            'hash' => ['sha256' => str_repeat('0', 64)],
            'png' => ['sha256' => hash('sha256', $bytes)],
        };
        if ($failure === 'hash') {
            $this->assertNotSame($metadata['sha256'], hash('sha256', $bytes));
        }
        $service = $this->bindPreviewService($metadata);
        $response = $this->actingAs($owner)->getJson('/api/v2/signature-assets/'.$asset->public_id.'/preview');

        $response->assertStatus(409)->assertJsonPath('code', 'signature_asset_integrity_failed');
        $this->assertPreviewDeniedWithoutBytes($response, $asset, $original);
        $this->assertSame(0, $service->previewAuditAttempts);
        $this->assertSame(0, AuditLog::query()->where('action', 'signature_asset.previewed')->count());
        $this->assertSame($bytes, file_get_contents($this->assetPath($asset)));
        $this->assertSame(hash('sha256', $original), $asset->refresh()->sha256);
    }

    public static function previewIntegrityFailures(): array
    {
        return [['size'], ['hash'], ['png']];
    }

    public function test_preview_audit_failure_returns_no_image_bytes_or_sensitive_error_detail(): void
    {
        $owner = $this->assetUser();
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $stored = file_get_contents($this->assetPath($asset));
        $before = $asset->refresh()->getRawOriginal();
        $service = $this->bindPreviewService([], true);
        $response = $this->actingAs($owner)->getJson('/api/v2/signature-assets/'.$asset->public_id.'/preview');

        $response->assertStatus(503)->assertJsonPath('code', 'signature_asset_unavailable');
        $this->assertPreviewDeniedWithoutBytes($response, $asset, $stored);
        $this->assertStringNotContainsString('private-preview-audit-detail', $response->getContent());
        $this->assertSame(1, $service->previewAuditAttempts);
        $this->assertSame(0, AuditLog::query()->where('action', 'signature_asset.previewed')->count());
        $this->assertSame($before, $asset->refresh()->getRawOriginal());
        $this->assertSame($stored, file_get_contents($this->assetPath($asset)));
    }

    public function test_retirement_is_permanent_idempotent_and_preserves_timestamp_audit_bytes_and_owner_preview(): void
    {
        $owner = $this->assetUser();
        $asset = app(SignatureAssetService::class)->upload($owner, $this->assetUpload());
        $stored = file_get_contents($this->assetPath($asset));
        $url = '/api/v2/signature-assets/'.$asset->public_id;
        $this->assertTrue($asset->isEligibleForSigning());
        $this->actingAs($owner);
        $this->travelTo(now('UTC')->startOfSecond());
        try {
            $this->postJson($url.'/retire', ['reason' => 'owner retirement'])->assertOk()
                ->assertJsonPath('data.status', 'retired')->assertJsonPath('data.eligible_for_signing', false);
            $retired = $asset->refresh()->getRawOriginal();
            $this->assertNotNull($asset->retired_at);
            $this->assertTrue($asset->retired_at->equalTo(now('UTC')));
            $this->assertSame($owner->id, $asset->retired_by);
            $this->assertSame('owner retirement', $asset->retirement_reason);
            $this->travel(1)->days();
            $this->postJson($url.'/retire', ['reason' => 'must not replace original reason'])->assertOk()
                ->assertJsonPath('data.eligible_for_signing', false);
            $this->assertSame($retired, $asset->refresh()->getRawOriginal());
            $this->assertFalse($asset->isEligibleForSigning());
            $this->assertSame(1, AuditLog::query()->where('action', 'signature_asset.retired')->count());
            $this->assertSame($stored, file_get_contents($this->assetPath($asset)));
            $this->get($url.'/preview')->assertOk()->assertContent($stored);
            $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'retired')->assertJsonPath('data.eligible_for_signing', false);
        } finally {
            $this->travelBack();
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    private function assertPreviewDeniedWithoutBytes(\Illuminate\Testing\TestResponse $response, SignatureAsset $asset, string $original): void
    {
        $response->assertHeader('Content-Type', 'application/json');
        foreach ([SignaturePngFixture::MAGIC, $original, $asset->storage_key, $asset->sha256, $this->signatureTestDirectory, 'private-original-marker'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertFalse($response->headers->has('Content-Disposition'));
    }

    private function bindPreviewService(array $metadata = [], bool $failAudit = false): SignatureAssetService
    {
        $service = new class(app(SignatureAssetStorage::class), app(SignatureImageNormalizer::class), app(SignatureAssetPolicy::class), $metadata, $failAudit) extends SignatureAssetService
        {
            public int $previewAuditAttempts = 0;

            public function __construct(SignatureAssetStorage $storage, SignatureImageNormalizer $normalizer, SignatureAssetPolicy $policy, private readonly array $metadata, private readonly bool $failAudit)
            {
                parent::__construct($storage, $normalizer, $policy);
            }

            public function own(User $actor, string $publicId, bool $lock = false): SignatureAsset
            {
                return parent::own($actor, $publicId, $lock)->forceFill($this->metadata);
            }

            protected function audit(string $action, User $actor, SignatureAsset $asset, array $before, array $after): void
            {
                if ($action === 'signature_asset.previewed') {
                    $this->previewAuditAttempts++;
                    if ($this->failAudit) {
                        throw new \RuntimeException('private-preview-audit-detail '.$asset->storage_key.' '.$asset->sha256);
                    }
                }
                parent::audit($action, $actor, $asset, $before, $after);
            }
        };
        $this->app->instance(SignatureAssetService::class, $service);

        return $service;
    }
}
