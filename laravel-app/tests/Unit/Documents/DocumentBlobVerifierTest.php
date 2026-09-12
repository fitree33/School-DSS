<?php

namespace Tests\Unit\Documents;

use App\Enums\DocumentVersionIntegrityBasis;
use App\Exceptions\ApiProblemException;
use App\Models\DocumentVersion;
use App\Models\ProjectDocument;
use App\Services\Documents\DocumentBlobVerifier;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DocumentBlobVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('version-private-temp');
        config()->set('document_versions.temporary_directory', Storage::disk('version-private-temp')->path('downloads'));
    }

    public function test_large_download_is_verified_with_bounded_memory_and_reliable_cleanup(): void
    {
        $disk = Storage::disk('local');
        $path = 'large.txt';
        $source = fopen($disk->path($path), 'wb');
        $chunk = str_repeat('bounded-memory-original ', 2800);
        $hash = hash_init('sha256');
        $size = 0;
        for ($i = 0; $i < 512; $i++) {
            fwrite($source, $chunk);
            hash_update($hash, $chunk);
            $size += strlen($chunk);
        }
        fclose($source);
        $version = new DocumentVersion([
            'storage_disk' => 'local', 'storage_path' => $path,
            'original_name' => 'large.txt', 'mime_type' => 'text/plain',
            'size_bytes' => $size, 'sha256' => hash_final($hash),
        ]);
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $file = app(DocumentBlobVerifier::class)->verifyVersionToTemporaryFile($version);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        $this->assertSame($size, $file->sizeBytes);
        $this->assertSame($version->sha256, hash_file('sha256', $file->path));
        $temporaryPath = $file->path;
        $file->close();
        $file->close();
        $this->assertFileDoesNotExist($temporaryPath);
        $this->assertSame($version->sha256, hash_file('sha256', $disk->path($path)));
    }

    public function test_prepared_download_survives_source_change_and_destructor_cleans_temp(): void
    {
        $version = $this->version('original bytes');
        $file = app(DocumentBlobVerifier::class)->verifyVersionToTemporaryFile($version);
        $path = $file->path;
        Storage::disk('local')->put('source.txt', 'changed later');
        $this->assertSame('original bytes', stream_get_contents($file->stream()));
        unset($file);
        $this->assertFileDoesNotExist($path);
    }

    public function test_same_size_tampering_fails_before_response_and_removes_temp(): void
    {
        $version = $this->version('original');
        Storage::disk('local')->put('source.txt', 'tampered');
        try {
            app(DocumentBlobVerifier::class)->verifyVersionToTemporaryFile($version);
            $this->fail('Tampered source was accepted.');
        } catch (ApiProblemException $exception) {
            $this->assertSame('document_version_integrity_failed', $exception->errorCode);
        }
        $this->assertSame([], Storage::disk('version-private-temp')->allFiles());
        $this->assertSame('tampered', Storage::disk('local')->get('source.txt'));
    }

    public function test_unwritable_temporary_destination_fails_without_changing_source(): void
    {
        $version = $this->version('original');
        Storage::disk('version-private-temp')->put('blocked', 'not a directory');
        config()->set('document_versions.temporary_directory', Storage::disk('version-private-temp')->path('blocked/child'));
        try {
            app(DocumentBlobVerifier::class)->verifyVersionToTemporaryFile($version);
            $this->fail('Invalid temporary destination was accepted.');
        } catch (ApiProblemException $exception) {
            $this->assertSame(503, $exception->status);
        }
        $this->assertSame('original', Storage::disk('local')->get('source.txt'));
    }

    #[DataProvider('unsafePaths')]
    public function test_ambiguous_paths_are_rejected_before_read(string $path): void
    {
        $this->expectException(ApiProblemException::class);
        app(DocumentBlobVerifier::class)->resolvePrivatePath('local', $path);
    }

    public static function unsafePaths(): array
    {
        return array_map(static fn ($path) => [$path], [
            '../source.txt', '/source.txt', 'C:/source.txt', '//server/source.txt',
            'a\\source.txt', 'a//source.txt', './source.txt', 'a/../source.txt',
            "source\0.txt", 'a./source.txt', 'a /source.txt',
        ]);
    }

    public function test_public_root_disguised_as_allowed_local_disk_is_rejected(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('source.txt', 'public file');
        config()->set('filesystems.disks.public.root', Storage::disk('public')->path(''));
        config()->set('filesystems.disks.local.root', Storage::disk('public')->path(''));
        Storage::forgetDisk('local');
        $this->expectException(ApiProblemException::class);
        app(DocumentBlobVerifier::class)->resolvePrivatePath('local', 'source.txt');
    }

    public function test_symlink_or_junction_escape_is_rejected_before_source_read(): void
    {
        Storage::fake('version-outside-private');
        $outside = Storage::disk('version-outside-private');
        $outside->put('original.txt', 'protected outside original');
        $target = $outside->path('original.txt');
        $link = Storage::disk('local')->path('escaped');
        $linkCreated = false;
        $source = null;

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $process = new Process([
                    'powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
                    'New-Item -ItemType Junction -Path $env:PHASE6_LINK_PATH -Target $env:PHASE6_TARGET_PATH -ErrorAction Stop | Out-Null',
                ], null, [
                    'PHASE6_LINK_PATH' => $link,
                    'PHASE6_TARGET_PATH' => $outside->path(''),
                ]);
                $process->run();
                if (! $process->isSuccessful()) {
                    $this->markTestSkipped('Environment cannot create the test junction: '.trim($process->getErrorOutput()));
                }
            } elseif (! @symlink($outside->path(''), $link)) {
                $this->markTestSkipped('Environment cannot create the test directory symlink.');
            }
            $linkCreated = true;

            clearstatcache();
            $this->assertSame(realpath($target), realpath($link.DIRECTORY_SEPARATOR.'original.txt'));
            $source = fopen($target, 'rb');
            $this->assertIsResource($source);
            // Reaching the source reader would fail its shared lock, not storage validation.
            $this->assertTrue(flock($source, LOCK_EX | LOCK_NB));
            $version = new DocumentVersion([
                'storage_disk' => 'local', 'storage_path' => 'escaped/original.txt',
                'original_name' => 'original.txt', 'mime_type' => 'text/plain',
                'size_bytes' => strlen('protected outside original'),
                'sha256' => hash('sha256', 'protected outside original'),
            ]);

            try {
                app(DocumentBlobVerifier::class)->verifyVersionToTemporaryFile($version);
                $this->fail('A storage link escaping the allowed private root was accepted.');
            } catch (ApiProblemException $exception) {
                $this->assertSame('document_version_storage_invalid', $exception->errorCode);
            }
            $this->assertSame([], Storage::disk('version-private-temp')->allFiles());
        } finally {
            if (is_resource($source)) {
                flock($source, LOCK_UN);
                fclose($source);
            }
            // Remove only the link itself; never recursively traverse its target.
            if ($linkCreated) {
                if (PHP_OS_FAMILY === 'Windows') {
                    $cleanup = new Process([
                        'powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
                        '$ErrorActionPreference = "Stop"; '
                        .'$junction = Get-Item -LiteralPath $env:PHASE6_LINK_PATH -Force; '
                        .'if ($junction.FullName -ne [IO.Path]::GetFullPath($env:PHASE6_LINK_PATH) '
                        .'-or ($junction.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0) '
                        .'{ throw "Refusing to remove a path other than the created test link." }; '
                        .'[IO.Directory]::Delete($junction.FullName); '
                        .'if (Test-Path -LiteralPath $env:PHASE6_LINK_PATH) { throw "Test junction still exists after cleanup." }',
                    ], null, ['PHASE6_LINK_PATH' => $link]);
                    $cleanup->run();
                    $this->assertTrue($cleanup->isSuccessful(), $cleanup->getErrorOutput());
                } else {
                    $this->assertTrue(unlink($link));
                }
                clearstatcache(true, $link);
            }
        }

        $this->assertSame('protected outside original', file_get_contents($target));
        $this->assertDirectoryDoesNotExist($link);
    }

    #[DataProvider('invalidRecordedMetadata')]
    public function test_recorded_size_or_checksum_disagreement_fails(array $overrides): void
    {
        $document = $this->document($overrides);
        $this->expectException(ApiProblemException::class);
        app(DocumentBlobVerifier::class)->verifyDocument($document);
    }

    public static function invalidRecordedMetadata(): array
    {
        return [
            [['checksum' => 'broken']],
            [['checksum' => str_repeat('0', 64)]],
            [['checksum' => str_repeat(' ', 64)]],
            [['size' => 1]],
            [['size' => -1]],
        ];
    }

    public function test_observed_checksum_is_not_claimed_as_historical_verification(): void
    {
        $document = $this->document(['checksum' => null, 'size' => null]);
        $blob = app(DocumentBlobVerifier::class)->verifyDocument($document);
        $this->assertSame(DocumentVersionIntegrityBasis::ObservedSha256, $blob->integrityBasis);
        $this->assertSame(hash('sha256', 'original bytes'), $blob->sha256);
        $this->assertNull($document->checksum);
        $this->assertNull($document->size);
    }

    private function version(string $bytes): DocumentVersion
    {
        Storage::disk('local')->put('source.txt', $bytes);

        return new DocumentVersion([
            'storage_disk' => 'local', 'storage_path' => 'source.txt',
            'original_name' => 'source.txt', 'mime_type' => 'text/plain',
            'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
        ]);
    }

    private function document(array $overrides = []): ProjectDocument
    {
        Storage::disk('local')->put('source.txt', 'original bytes');

        return new ProjectDocument(array_replace([
            'source_import_id' => 1, 'storage_disk' => 'local', 'path' => 'source.txt',
            'original_name' => 'source.txt', 'mime_type' => 'text/plain',
            'size' => strlen('original bytes'), 'checksum' => hash('sha256', 'original bytes'),
        ], $overrides));
    }
}
