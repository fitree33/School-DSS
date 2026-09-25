<?php

namespace Tests\Feature;

use App\Contracts\Signatures\SignatureImageNormalizer;
use App\DTOs\Signatures\OwnedSignatureFile;
use App\DTOs\Signatures\OwnedSignatureWorkspace;
use App\Exceptions\ApiProblemException;
use App\Models\AuditLog;
use App\Models\SignatureAsset;
use App\Models\User;
use App\Policies\SignatureAssetPolicy;
use App\Services\Signatures\GdSignatureImageNormalizer;
use App\Services\Signatures\PngStructureValidator;
use App\Services\Signatures\SignatureAssetService;
use App\Services\Signatures\SignatureAssetStorage;
use Closure;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\SignatureAssetApiTestCase;
use Tests\Support\SignaturePngFixture;

class SignatureAssetFailureCompensationTest extends SignatureAssetApiTestCase
{
    /** @var list<SignatureCompensationStorage> */
    private array $compensationStorages = [];

    protected function tearDown(): void
    {
        try {
            // Release test-observed handles even if an assertion fails during an interleaving.
            foreach ($this->compensationStorages as $storage) {
                foreach ($storage->files as $file) {
                    $storage->preserve($file);
                }
                foreach ($storage->workspaces as $workspace) {
                    $storage->cleanup($workspace);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('normalizationFailures')]
    public function test_validation_decode_and_encode_failures_leave_no_persisted_asset(string $failure, string $code, int $status): void
    {
        $storage = $this->recordingStorage();
        $normalizer = new class(new PngStructureValidator) extends GdSignatureImageNormalizer
        {
            public string $failure;

            protected function decode(string $path): GdImage|false
            {
                return $this->failure === 'decode' ? false : parent::decode($path);
            }

            protected function encode(GdImage $image, mixed $stream): bool
            {
                return $this->failure === 'encode' ? false : parent::encode($image, $stream);
            }
        };
        $normalizer->failure = $failure;
        $source = match ($failure) {
            'validation' => 'not a PNG',
            'structure' => substr(SignaturePngFixture::rgba(), 0, -1),
            default => SignaturePngFixture::rgba(),
        };
        $upload = $this->assetUpload($source);

        $this->assertSignatureProblem(fn () => $this->service($storage, $normalizer)->upload($this->assetUser(), $upload), $code, $status);

        $this->assertNoDatabaseMutation();
        $this->assertSame([], $this->signatureStoredPngs());
        $this->assertSame([], $storage->files);
        $this->assertSame([], $storage->rolledBack);
        $this->assertSame($source, file_get_contents($upload->getPathname()));
        $this->assertSignatureTemporaryEmpty();
    }

    public static function normalizationFailures(): array
    {
        return [
            'validation' => ['validation', 'signature_format_unsupported', 422],
            'truncated PNG structure' => ['structure', 'signature_image_invalid', 422],
            'decode' => ['decode', 'signature_image_invalid', 422],
            'encode' => ['encode', 'signature_asset_unavailable', 503],
        ];
    }

    #[DataProvider('finalWriteFailures')]
    public function test_final_write_failures_remove_only_owned_output_and_preserve_the_blocker(string $suffix): void
    {
        $storage = $this->recordingStorage();
        $storage->forcedKey = str_repeat('a', 64).'.png';
        $path = config('signature_assets.storage_root').DIRECTORY_SEPARATOR.$storage->forcedKey;
        // An existing directory deterministically refuses exclusive creation without ACL changes.
        $blocker = $path.$suffix;
        $this->assertTrue(mkdir($blocker));
        $marker = $blocker.DIRECTORY_SEPARATOR.'foreign-marker';
        $this->assertSame(9, file_put_contents($marker, 'untouched'));
        $upload = $this->assetUpload();
        $source = file_get_contents($upload->getPathname());

        $this->assertSignatureProblem(fn () => $this->service($storage)->upload($this->assetUser(), $upload));

        $this->assertNoDatabaseMutation();
        $this->assertDirectoryExists($blocker);
        $this->assertSame('untouched', file_get_contents($marker));
        $this->assertSame(['foreign-marker'], array_values(array_diff(scandir($blocker), ['.', '..'])));
        if ($suffix !== '') {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertFileExists($path.'.lock');
        $this->assertSame('', file_get_contents($path.'.lock'));
        $this->assertSame([], $storage->files);
        $this->assertSame($source, file_get_contents($upload->getPathname()));
        $this->assertSignatureTemporaryEmpty();
    }

    public static function finalWriteFailures(): array
    {
        return ['final PNG create' => [''], 'receipt write after final PNG' => ['.json']];
    }

    public function test_database_insert_failure_definitely_rolls_back_and_removes_owned_file_and_receipt(): void
    {
        $storage = $this->recordingStorage();
        $attempted = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$attempted): void {
            if (preg_match('/\Ainsert into ["`]signature_assets["`]/i', $query)) {
                $attempted++;
                throw new RuntimeException('private insert failure bindings /fixture/path');
            }
        });

        $this->assertSignatureProblem(fn () => $this->service($storage)->upload($this->assetUser(), $this->assetUpload()));

        $this->assertSame(1, $attempted);
        $this->assertNoDatabaseMutation();
        $this->assertOwnedRollback($storage);
    }

    public function test_audit_insert_failure_rolls_back_the_already_inserted_asset_and_owned_bytes(): void
    {
        $storage = $this->recordingStorage();
        $assetWasInserted = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$assetWasInserted): void {
            if (preg_match('/\Ainsert into ["`]audit_logs["`]/i', $query)) {
                $assetWasInserted = SignatureAsset::query()->count() === 1 && DB::transactionLevel() === 1;
                throw new RuntimeException('private audit insert failure');
            }
        });

        $this->assertSignatureProblem(fn () => $this->service($storage)->upload($this->assetUser(), $this->assetUpload()));

        $this->assertTrue($assetWasInserted);
        $this->assertNoDatabaseMutation();
        $this->assertOwnedRollback($storage);
    }

    public function test_rollback_cleanup_failure_preserves_private_orphan_and_logs_only_safe_diagnostics(): void
    {
        Log::spy();
        $storage = $this->recordingStorage();
        $storage->refuseRemoval = true;
        $service = $this->auditFailureService($storage);

        $this->assertSignatureProblem(fn () => $service->upload($this->assetUser(), $this->assetUpload()));

        $this->assertNoDatabaseMutation();
        $this->assertCount(1, $storage->files);
        $file = $storage->files[0];
        $this->assertSame([$file->storageKey], $storage->rolledBack);
        $this->assertPreservedFile($file);
        $this->assertSignatureTemporaryEmpty();
        Log::shouldHaveReceived('warning')->once()->with('signature_asset.cleanup_incomplete', [
            'error_code' => 'signature_asset_cleanup_incomplete',
        ]);
    }

    public function test_temporary_cleanup_failure_does_not_mask_validation_or_delete_unowned_nested_content(): void
    {
        Log::spy();
        $storage = $this->recordingStorage();
        $normalizer = new class(new PngStructureValidator) extends GdSignatureImageNormalizer
        {
            public string $blocker;

            public function normalize(string $inputPath, string $workingDirectory): string
            {
                $this->blocker = $workingDirectory.DIRECTORY_SEPARATOR.'foreign-directory';
                if (! mkdir($this->blocker) || file_put_contents($this->blocker.DIRECTORY_SEPARATOR.'marker', 'untouched') !== 9) {
                    throw new RuntimeException('Fixture creation failed.');
                }

                return parent::normalize($inputPath, $workingDirectory);
            }
        };
        $upload = $this->assetUpload('not a PNG');

        $this->assertSignatureProblem(fn () => $this->service($storage, $normalizer)->upload($this->assetUser(), $upload), 'signature_format_unsupported', 422);

        $this->assertNoDatabaseMutation();
        $this->assertSame([], $this->signatureStoredPngs());
        $this->assertSame('untouched', file_get_contents($normalizer->blocker.DIRECTORY_SEPARATOR.'marker'));
        $this->assertSame('not a PNG', file_get_contents($upload->getPathname()));
        $this->assertCount(1, $storage->workspaces);
        $workspace = $storage->workspaces[0];
        $this->assertFalse(is_resource($workspace->lease));
        $this->assertSame(['foreign-directory'], array_values(array_diff(scandir($workspace->directory()), ['.', '..'])));
        Log::shouldHaveReceived('warning')->twice()->with('signature_asset.cleanup_incomplete', [
            'error_code' => 'signature_asset_cleanup_incomplete',
        ]);
    }

    public function test_storage_key_collision_keeps_committed_owner_bytes_metadata_and_receipt_unchanged(): void
    {
        $storage = $this->recordingStorage();
        $service = $this->service($storage);
        $ownerB = $this->assetUser();
        $assetB = $service->upload($ownerB, $this->assetUpload());
        $path = $this->assetPath($assetB);
        $before = [$assetB->refresh()->getRawOriginal(), file_get_contents($path), file_get_contents($path.'.json'), file_get_contents($path.'.lock')];
        $storage->forcedKey = $assetB->storage_key;

        $this->assertSignatureProblem(fn () => $service->upload($this->assetUser(), $this->assetUpload()));

        $this->assertSame($before, [$assetB->refresh()->getRawOriginal(), file_get_contents($path), file_get_contents($path.'.json'), file_get_contents($path.'.lock')]);
        $this->assertDatabaseCount('signature_assets', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame($ownerB->id, AuditLog::query()->sole()->user_id);
        $this->assertSame([$path], $this->signatureStoredPngs());
        $this->assertSame([], $storage->rolledBack);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSignatureTemporaryEmpty();
    }

    #[DataProvider('ambiguousCommitOutcomes')]
    public function test_ambiguous_commit_always_preserves_file_even_when_database_outcome_differs(bool $serverCommitted): void
    {
        $storage = $this->recordingStorage();
        $service = new class($storage, app(SignatureImageNormalizer::class), new SignatureAssetPolicy) extends SignatureAssetService
        {
            public bool $serverCommitted;

            protected function commitTransaction(): void
            {
                if ($this->serverCommitted) {
                    parent::commitTransaction();
                }
                // The caller cannot distinguish these outcomes from the failed acknowledgement.
                throw new RuntimeException('private ambiguous commit acknowledgement');
            }
        };
        $service->serverCommitted = $serverCommitted;
        $owner = $this->assetUser();

        $this->assertSignatureProblem(fn () => $service->upload($owner, $this->assetUpload()));

        $this->assertDatabaseCount('signature_assets', $serverCommitted ? 1 : 0);
        $this->assertDatabaseCount('audit_logs', $serverCommitted ? 1 : 0);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], $storage->rolledBack);
        $this->assertCount(1, $storage->files);
        $file = $storage->files[0];
        $this->assertPreservedFile($file);
        if ($serverCommitted) {
            $asset = SignatureAsset::query()->sole();
            $this->assertSame($owner->id, $asset->owner_id);
            $this->assertSame($file->storageKey, $asset->storage_key);
            $this->assertSame($file->sha256, $asset->sha256);
            $this->assertSame('signature_asset.uploaded', AuditLog::query()->sole()->action);
        }
        $this->assertSignatureTemporaryEmpty();
    }

    public static function ambiguousCommitOutcomes(): array
    {
        return ['uncommitted acknowledgement failure' => [false], 'committed acknowledgement failure' => [true]];
    }

    public function test_post_commit_response_failure_keeps_asset_audit_and_private_bytes(): void
    {
        $storage = $this->recordingStorage();
        $this->app->instance(SignatureAssetService::class, $this->service($storage));
        $this->app['router']->pushMiddlewareToGroup('api', SignaturePostCommitResponseFailure::class);
        $owner = $this->assetUser();

        $response = $this->actingAs($owner)->postJson('/api/v2/signature-assets', ['image' => $this->assetUpload()]);

        $response->assertStatus(503)->assertJsonPath('code', 'signature_response_unavailable');
        $this->assertDatabaseCount('signature_assets', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $asset = SignatureAsset::query()->sole();
        $this->assertSame($owner->id, $asset->owner_id);
        $this->assertSame('signature_asset.uploaded', AuditLog::query()->sole()->action);
        $this->assertSame([], $storage->rolledBack);
        $this->assertCount(1, $storage->files);
        $this->assertPreservedFile($storage->files[0]);
        $this->assertSame($asset->sha256, hash_file('sha256', $this->assetPath($asset)));
        foreach ([$asset->storage_key, $asset->sha256, $this->signatureTestDirectory] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSignatureTemporaryEmpty();
    }

    public function test_request_a_rollback_cannot_delete_request_b_committed_identical_image(): void
    {
        $storageA = $this->recordingStorage();
        $storageB = $this->compensationStorages[] = new SignatureCompensationStorage(new PngStructureValidator);
        $ownerA = $this->assetUser();
        $ownerB = $this->assetUser();
        $assetB = null;
        $beforeB = null;
        $storageA->afterStore = function () use ($storageB, $ownerB, &$assetB, &$beforeB): void {
            // A owns its final file but has not begun its database transaction. B commits now.
            $this->assertSame(0, DB::transactionLevel());
            $assetB = $this->service($storageB)->upload($ownerB, $this->assetUpload());
            $beforeB = [$assetB->refresh()->getRawOriginal(), file_get_contents($this->assetPath($assetB)), file_get_contents($this->assetPath($assetB).'.json')];
        };

        $this->assertSignatureProblem(fn () => $this->auditFailureService($storageA)->upload($ownerA, $this->assetUpload()));

        $this->assertInstanceOf(SignatureAsset::class, $assetB);
        $this->assertCount(1, $storageA->files);
        $this->assertCount(1, $storageB->files);
        $this->assertSame($storageA->files[0]->sha256, $storageB->files[0]->sha256);
        $this->assertNotSame($storageA->files[0]->storageKey, $storageB->files[0]->storageKey);
        $this->assertSame([$storageA->files[0]->storageKey], $storageA->rolledBack);
        $this->assertSame([], $storageB->rolledBack);
        $pathA = $storageA->files[0]->root.DIRECTORY_SEPARATOR.$storageA->files[0]->storageKey;
        $this->assertFileDoesNotExist($pathA);
        $this->assertFileDoesNotExist($pathA.'.json');
        $this->assertFileExists($pathA.'.lock');
        $this->assertPreservedFile($storageB->files[0]);
        $this->assertSame($beforeB, [$assetB->refresh()->getRawOriginal(), file_get_contents($this->assetPath($assetB)), file_get_contents($this->assetPath($assetB).'.json')]);
        $this->assertDatabaseCount('signature_assets', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame($ownerB->id, SignatureAsset::query()->sole()->owner_id);
        $this->assertSame($ownerB->id, AuditLog::query()->sole()->user_id);
        $this->assertSame([$this->assetPath($assetB)], $this->signatureStoredPngs());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSignatureTemporaryEmpty();
    }

    private function recordingStorage(): SignatureCompensationStorage
    {
        return $this->signatureStorage = $this->compensationStorages[] = new SignatureCompensationStorage(new PngStructureValidator);
    }

    private function service(SignatureAssetStorage $storage, ?SignatureImageNormalizer $normalizer = null): SignatureAssetService
    {
        return new SignatureAssetService($storage, $normalizer ?? app(SignatureImageNormalizer::class), new SignatureAssetPolicy);
    }

    private function auditFailureService(SignatureAssetStorage $storage): SignatureAssetService
    {
        return new class($storage, app(SignatureImageNormalizer::class), new SignatureAssetPolicy) extends SignatureAssetService
        {
            protected function audit(string $action, User $actor, SignatureAsset $asset, array $before, array $after): void
            {
                parent::audit($action, $actor, $asset, $before, $after);
                throw new RuntimeException('private failure after audit insert');
            }
        };
    }

    private function assertNoDatabaseMutation(): void
    {
        $this->assertDatabaseCount('signature_assets', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(0, DB::transactionLevel());
    }

    private function assertOwnedRollback(SignatureCompensationStorage $storage): void
    {
        $this->assertCount(1, $storage->files);
        $file = $storage->files[0];
        $path = $file->root.DIRECTORY_SEPARATOR.$file->storageKey;
        $this->assertSame([$file->storageKey], $storage->rolledBack);
        $this->assertTrue($file->released);
        $this->assertFalse(is_resource($file->handle));
        $this->assertFalse(is_resource($file->lease));
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path.'.json');
        $this->assertFileExists($path.'.lock');
        $this->assertSame([], $this->signatureStoredPngs());
        $this->assertSignatureTemporaryEmpty();
    }

    private function assertPreservedFile(OwnedSignatureFile $file): void
    {
        $path = $file->root.DIRECTORY_SEPARATOR.$file->storageKey;
        $this->assertTrue($file->released);
        $this->assertFalse(is_resource($file->handle));
        $this->assertFalse(is_resource($file->lease));
        $this->assertFileExists($path);
        $this->assertFileExists($path.'.json');
        $this->assertFileExists($path.'.lock');
        $this->assertSame($file->sha256, hash_file('sha256', $path));
        $this->assertSame($file->sizeBytes, filesize($path));
        $receipt = json_decode(file_get_contents($path.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($file->publicId, $receipt['public_id']);
        $this->assertSame($file->storageKey, $receipt['key']);
        $this->assertSame($file->sha256, $receipt['sha256']);
        $this->assertSame($file->sizeBytes, $receipt['size_bytes']);
    }
}

/** Records service effects while retaining all real storage checks and ownership operations. */
class SignatureCompensationStorage extends SignatureAssetStorage
{
    /** @var list<OwnedSignatureFile> */
    public array $files = [];

    /** @var list<OwnedSignatureWorkspace> */
    public array $workspaces = [];

    /** @var list<string> */
    public array $rolledBack = [];

    public ?string $forcedKey = null;

    public bool $refuseRemoval = false;

    public ?Closure $afterStore = null;

    public function beginRequest(): OwnedSignatureWorkspace
    {
        return $this->workspaces[] = parent::beginRequest();
    }

    public function storeNormalized(string $bytes, string $publicId, OwnedSignatureWorkspace $workspace): OwnedSignatureFile
    {
        $file = parent::storeNormalized($bytes, $publicId, $workspace);
        $this->files[] = $file;
        $this->afterStore?->__invoke();

        return $file;
    }

    public function rollback(OwnedSignatureFile $file): void
    {
        $this->rolledBack[] = $file->storageKey;
        parent::rollback($file);
    }

    protected function newStorageKey(): string
    {
        return $this->forcedKey ?? parent::newStorageKey();
    }

    protected function removeCandidateFile(string $path): bool
    {
        return ! $this->refuseRemoval && parent::removeCandidateFile($path);
    }
}

class SignaturePostCommitResponseFailure
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        if ($request->isMethod('post') && $request->is('api/v2/signature-assets') && $response->getStatusCode() === 201) {
            throw new ApiProblemException('The upload response is unavailable.', 'signature_response_unavailable', 503);
        }

        return $response;
    }
}
