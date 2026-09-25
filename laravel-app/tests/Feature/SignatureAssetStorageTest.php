<?php

namespace Tests\Feature;

use App\Models\SignatureAsset;
use App\Services\Signatures\PngStructureValidator;
use App\Services\Signatures\SignatureAssetStorage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\UsesPrivateSignatureStorage;
use Tests\TestCase;

class SignatureAssetStorageTest extends TestCase
{
    use UsesPrivateSignatureStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateSignatureStorage();
    }

    protected function tearDown(): void
    {
        $this->tearDownPrivateSignatureStorage();
        parent::tearDown();
    }

    public function test_private_staging_final_readback_metadata_and_cleanup(): void
    {
        $workspace = $this->privateSignatureWorkspace();
        $original = $this->signatureUploadFixture($this->signaturePng());
        $staged = $this->signatureStorage->stageUpload($original, $workspace);
        $this->assertSame($workspace->directory(), dirname($staged));
        $this->assertSame($this->signaturePng(), file_get_contents($staged));
        $receipt = $this->privateSignatureReceipt($workspace);
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        // Production retains exclusive ownership through the commit outcome.
        $this->assertFalse($receipt->released);
        $this->assertSame(0, fseek($receipt->handle, 0));
        $this->assertSame(hash('sha256', stream_get_contents($receipt->handle)), $receipt->sha256);
        $this->assertSame(fstat($receipt->handle)['size'], $receipt->sizeBytes);
        $this->assertSame([2, 1], [$receipt->width, $receipt->height]);
        $this->signatureStorage->preserve($receipt);
        // A fresh handle may read the final PNG only after preserve releases it.
        $this->assertSame(hash_file('sha256', $path), $receipt->sha256);
        $this->assertSame(filesize($path), $receipt->sizeBytes);
        $this->assertSame($this->signaturePng(), $this->signatureStorage->readVerified($this->signatureAssetForReceipt($receipt)));
        $this->signatureStorage->cleanup($workspace);
        $this->assertDirectoryDoesNotExist($workspace->directory());
        $this->assertFileExists($original);
        $this->assertFileExists($path);
        $this->assertArrayNotHasKey('signature-assets', config('filesystems.disks'));
        $this->assertNotContains('signature-assets', config('document_versions.allowed_disks', []));
    }

    public function test_staging_rejects_empty_and_oversized_uploads_and_cleans_owned_temporaries(): void
    {
        $workspace = $this->privateSignatureWorkspace();
        $this->assertSignatureProblem(fn () => $this->signatureStorage->stageUpload($this->signatureUploadFixture(''), $workspace), 'signature_image_invalid', 422);
        $this->assertSignatureProblem(fn () => $this->signatureStorage->stageUpload($this->signatureUploadFixture(str_repeat('x', 2097153)), $workspace), 'signature_upload_too_large', 413);
        $this->signatureStorage->cleanup($workspace);
        $this->assertDirectoryDoesNotExist($workspace->directory());
        $this->assertSame([], glob(config('signature_assets.storage_root').'/*.png'));
    }

    public function test_upload_source_refuses_unconfigured_temp_and_nonprivate_fallback(): void
    {
        $workspace = $this->privateSignatureWorkspace();
        $outside = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'os-fallback.upload';
        file_put_contents($outside, $this->signaturePng());
        $this->assertSignatureProblem(fn () => $this->signatureStorage->stageUpload($outside, $workspace));
        config()->set('signature_assets.php_upload_directory', null);
        $this->assertSignatureProblem(fn () => $this->signatureStorage->assertUploadSource($outside));
        config()->set('signature_assets.php_upload_directory', storage_path('app'));
        $this->assertSignatureProblem(fn () => $this->signatureStorage->assertUploadSource($outside));
        $this->assertFileExists($outside);
    }

    public function test_roots_refuse_storage_disks_public_links_and_overlapping_namespaces(): void
    {
        $root = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'must-not-be-created';
        config()->set('signature_assets.storage_root', $root);
        config()->set('filesystems.disks.signature-alias', ['driver' => 'local', 'root' => $root]);
        $this->assertSignatureProblem(fn () => $this->signatureStorage->beginRequest());
        config()->set('filesystems.disks.signature-alias', null);
        config()->set('filesystems.links', [public_path('signature-alias') => $root]);
        $this->assertSignatureProblem(fn () => $this->signatureStorage->beginRequest());
        config()->set('filesystems.links', []);
        config()->set('signature_assets.temporary_directory', $root.DIRECTORY_SEPARATOR.'tmp');
        $this->assertSignatureProblem(fn () => $this->signatureStorage->beginRequest());
        config()->set('signature_assets.temporary_directory', $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'temporary');
        config()->set('signature_assets.storage_root', storage_path('app/private/signatures'));
        $this->assertSignatureProblem(fn () => $this->signatureStorage->beginRequest());
        $this->assertDirectoryDoesNotExist($root);
    }

    #[DataProvider('invalidRoots')]
    public function test_rejects_noncanonical_or_device_root_spellings(string $root): void
    {
        config()->set('signature_assets.storage_root', str_replace('{root}', $this->signatureTestDirectory, $root));
        $this->assertSignatureProblem(fn () => $this->signatureStorage->beginRequest());
    }

    public static function invalidRoots(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            'relative/assets', '../assets', '\\\\server\\share\\assets', '\\\\?\\C:\\assets',
            '{root}/../assets', '{root}/./assets', '{root}/assets:stream',
            '{root}/assets.', '{root}/assets ', '{root}/NUL', '{root}/COM1.png', '{root}//assets',
        ]);
    }

    public function test_rejects_every_untrusted_storage_key_before_opening_it(): void
    {
        foreach (['../secret', '/absolute.png', 'C:\\asset.png', '\\\\server\\share', 'a.png:stream', 'NUL', str_repeat('A', 64).'.png', str_repeat('a', 64).'.jpg'] as $key) {
            $this->assertSignatureProblem(fn () => $this->signatureStorage->readVerified(new SignatureAsset(['storage_key' => $key])), 'signature_asset_integrity_failed', 409);
        }
    }

    public function test_exclusive_creation_preserves_existing_bytes_and_ownership_journal(): void
    {
        $this->signatureStorage = new class(new PngStructureValidator) extends SignatureAssetStorage
        {
            protected function newStorageKey(): string
            {
                return str_repeat('c', 64).'.png';
            }
        };
        $workspace = $this->privateSignatureWorkspace();
        $first = $this->privateSignatureReceipt($workspace);
        $this->signatureStorage->preserve($first);
        $path = $first->root.DIRECTORY_SEPARATOR.$first->storageKey;
        $journal = file_get_contents($path.'.json');
        $this->assertSignatureProblem(fn () => $this->signatureStorage->storeNormalized($this->signaturePng(), (string) Str::uuid(), $workspace));
        $this->assertSame($this->signaturePng(), file_get_contents($path));
        $this->assertSame($journal, file_get_contents($path.'.json'));
    }

    public function test_rollback_deletes_only_this_requests_object_even_for_identical_images(): void
    {
        $first = $this->privateSignatureReceipt();
        $second = $this->privateSignatureReceipt();
        $this->assertSame($first->sha256, $second->sha256);
        $this->assertNotSame($first->storageKey, $second->storageKey);
        $this->signatureStorage->rollback($first);
        $this->assertFileDoesNotExist($first->root.DIRECTORY_SEPARATOR.$first->storageKey);
        $this->assertFileDoesNotExist($first->root.DIRECTORY_SEPARATOR.$first->storageKey.'.json');
        $this->assertFileExists($second->root.DIRECTORY_SEPARATOR.$second->storageKey);
        $this->signatureStorage->rollback($first);
        $this->signatureStorage->preserve($second);
    }

    public function test_rollback_closes_png_but_holds_candidate_lease_through_cleanup(): void
    {
        $storage = $this->signatureStorage = new class(new PngStructureValidator) extends SignatureAssetStorage
        {
            public ?\Closure $observe = null;

            protected function reconciliationCheckpoint(string $phase, string $path): void
            {
                $this->observe?->__invoke($phase, $path);
            }

            protected function removeCandidateFile(string $path): bool
            {
                $this->observe?->__invoke('before-delete', $path);
                $removed = parent::removeCandidateFile($path);
                $this->observe?->__invoke('after-delete', $path);

                return $removed;
            }
        };
        $receipt = $this->privateSignatureReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $observations = [];
        $storage->observe = static function (string $phase, string $removedPath) use ($receipt, $path, &$observations): void {
            $probe = @fopen($path.'.lock', 'r+b');
            $wouldBlock = 0;
            $acquired = is_resource($probe) && @flock($probe, LOCK_EX | LOCK_NB, $wouldBlock);
            clearstatcache(true, $removedPath);
            $observations[] = [
                $phase, str_ends_with($removedPath, '.json') ? 'journal' : 'png',
                is_resource($receipt->handle), is_resource($receipt->lease), $receipt->released,
                is_resource($probe), $acquired, $wouldBlock, file_exists($removedPath),
            ];
            if (is_resource($probe)) {
                fclose($probe);
            }
        };

        $storage->rollback($receipt);

        $this->assertSame([
            ['rollback-before-delete', 'png', false, true, false, true, false, 1, true],
            ['before-delete', 'png', false, true, false, true, false, 1, true],
            ['after-delete', 'png', false, true, false, true, false, 1, false],
            ['before-delete', 'journal', false, true, false, true, false, 1, true],
            ['after-delete', 'journal', false, true, false, true, false, 1, false],
        ], $observations);
        $this->assertTrue($receipt->released);
        $this->assertFalse(is_resource($receipt->handle));
        $this->assertFalse(is_resource($receipt->lease));
        $this->assertFileExists($path.'.lock');
        $probe = fopen($path.'.lock', 'r+b');
        try {
            $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB));
        } finally {
            fclose($probe);
        }
    }

    public function test_deleted_candidate_keeps_same_lease_and_prevents_storage_key_reuse(): void
    {
        $this->signatureStorage = new class(new PngStructureValidator) extends SignatureAssetStorage
        {
            protected function newStorageKey(): string
            {
                return str_repeat('d', 64).'.png';
            }
        };
        $workspace = $this->privateSignatureWorkspace();
        $receipt = $this->privateSignatureReceipt($workspace);
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        // A marker also distinguishes replacement on Windows, whose stat inode may be zero.
        $marker = bin2hex(random_bytes(16));
        $this->assertSame(strlen($marker), fwrite($receipt->lease, $marker));
        $this->assertTrue(fflush($receipt->lease));
        $identityFields = array_flip(['dev', 'ino', 'mode', 'nlink']);
        $identity = array_intersect_key(fstat($receipt->lease), $identityFields);

        $this->signatureStorage->rollback($receipt);

        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path.'.json');
        $this->assertFileExists($path.'.lock');
        clearstatcache(true, $path.'.lock');
        $this->assertSame($identity, array_intersect_key(lstat($path.'.lock'), $identityFields));
        $this->assertSame($marker, file_get_contents($path.'.lock'));
        $this->assertSignatureProblem(fn () => $this->signatureStorage->storeNormalized($this->signaturePng(), (string) Str::uuid(), $workspace));
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path.'.json');
        clearstatcache(true, $path.'.lock');
        $this->assertSame($identity, array_intersect_key(lstat($path.'.lock'), $identityFields));
        $this->assertSame($marker, file_get_contents($path.'.lock'));
    }

    public function test_ambiguous_commit_preserve_is_terminal_and_never_deletes_final_bytes(): void
    {
        $receipt = $this->privateSignatureReceipt();
        $this->signatureStorage->preserve($receipt);
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $journal = file_get_contents($path.'.json');
        $this->signatureStorage->rollback($receipt);
        $this->signatureStorage->preserve($receipt);
        $this->signatureStorage->rollback($receipt);
        $this->assertTrue($receipt->released);
        $this->assertFalse(is_resource($receipt->handle));
        $this->assertFalse(is_resource($receipt->lease));
        $this->assertSame($this->signaturePng(), file_get_contents($path));
        $this->assertSame($journal, file_get_contents($path.'.json'));
        $this->assertFileExists($path.'.lock');
    }

    public function test_readback_refuses_tampering_and_database_metadata_mismatch(): void
    {
        $receipt = $this->privateSignatureReceipt();
        $this->signatureStorage->preserve($receipt);
        foreach (['sha256' => str_repeat('0', 64), 'size_bytes' => $receipt->sizeBytes + 1, 'width' => 3, 'height' => 2, 'mime_type' => 'image/jpeg'] as $field => $value) {
            $asset = $this->signatureAssetForReceipt($receipt);
            $asset->{$field} = $value;
            $this->assertSignatureProblem(fn () => $this->signatureStorage->readVerified($asset), 'signature_asset_integrity_failed', 409);
        }
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        file_put_contents($path, $this->signaturePng().'payload');
        $this->assertSignatureProblem(fn () => $this->signatureStorage->readVerified($this->signatureAssetForReceipt($receipt)), 'signature_asset_integrity_failed', 409);
    }

    public function test_final_write_requires_real_gd_decode_and_compensates_its_own_failed_file(): void
    {
        $workspace = $this->privateSignatureWorkspace();
        $bytes = $this->signaturePng();
        $length = unpack('Nlength', substr($bytes, 33, 4))['length'];
        $badData = str_repeat('x', $length);
        $bad = substr($bytes, 0, 41).$badData.hash('crc32b', 'IDAT'.$badData, true).substr($bytes, 45 + $length);
        (new PngStructureValidator)->validateNormalized($bad);
        $this->assertSignatureProblem(fn () => $this->signatureStorage->storeNormalized($bad, (string) Str::uuid(), $workspace), 'signature_asset_integrity_failed', 409);
        $files = glob(config('signature_assets.storage_root').'/*');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\.png\.lock\z/D', basename($files[0]));
        $this->assertSame('', file_get_contents($files[0]));
    }
}
