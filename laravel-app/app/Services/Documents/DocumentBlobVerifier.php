<?php

namespace App\Services\Documents;

use App\DTOs\Documents\VerifiedDocumentBlob;
use App\DTOs\Documents\VerifiedDownloadFile;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Exceptions\ApiProblemException;
use App\Models\DocumentImport;
use App\Models\DocumentVersion;
use App\Models\ProjectDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentBlobVerifier
{
    private const CHUNK_BYTES = 65536;

    private const MIME_SAMPLE_BYTES = 8192;

    public function verifyDocument(ProjectDocument $document, ?array $mapping = null): VerifiedDocumentBlob
    {
        $disk = $document->storage_disk;
        if ($mapping !== null) {
            $this->validateMapping($document, $mapping);
            $disk ??= $mapping['storage_disk'];
        }
        if (! is_string($disk) || $disk === '') {
            $this->fail('document_version_storage_invalid');
        }
        $path = $this->resolvePrivatePath($disk, $document->path);
        $this->rejectImportedAlias($document, $disk, $path);
        $expectedHash = $this->expectedHash($document->checksum);
        $expectedSize = $this->expectedSize($document->size);
        [$bytes, $hash, $sample] = $this->readVerifiedSource($path, $expectedSize, $expectedHash);

        if ($mapping !== null && ($bytes !== $mapping['size_bytes'] || ! hash_equals(strtolower($mapping['sha256']), $hash))) {
            $this->fail('document_version_integrity_failed');
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($sample) ?: 'application/octet-stream';
        $recorded = $document->mime_type;
        // MIME classification is conservative; generic ZIP/OLE can represent Office files.
        if ($recorded === 'application/pdf' && $detected !== 'application/pdf') {
            $this->fail('document_version_integrity_failed');
        }
        if ($detected === 'application/pdf' && filled($recorded) && ! in_array($recorded, ['application/pdf', 'application/octet-stream'], true)) {
            $this->fail('document_version_integrity_failed');
        }
        $mime = $detected;
        if (in_array($detected, ['application/zip', 'application/x-ole-storage', 'application/CDFV2'], true)
            && in_array($recorded, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
            $mime = $recorded;
        }
        if (! is_string($document->original_name) || $document->original_name === '' || mb_strlen($document->original_name) > 255) {
            $this->fail('document_version_integrity_failed');
        }

        return new VerifiedDocumentBlob(
            $disk, $document->path, $document->original_name, $mime, $bytes, $hash,
            $expectedHash === null ? DocumentVersionIntegrityBasis::ObservedSha256 : DocumentVersionIntegrityBasis::RecordedSha256,
            CarbonImmutable::now('UTC'),
        );
    }

    public function verifyVersionToTemporaryFile(DocumentVersion $version): VerifiedDownloadFile
    {
        $path = $this->resolvePrivatePath($version->storage_disk, $version->storage_path);
        $temporaryPath = null;
        $temporary = null;
        try {
            [$temporaryPath, $temporary] = $this->createPrivateTemporaryFile();
            [$bytes] = $this->readVerifiedSource(
                $path, $this->expectedSize($version->size_bytes), $this->expectedHash($version->sha256), $temporary,
            );
            if (! fflush($temporary) || fseek($temporary, 0) !== 0) {
                $this->fail('document_version_download_unavailable', 503);
            }

            return new VerifiedDownloadFile($temporaryPath, $bytes, $version->mime_type, $version->original_name, $temporary);
        } catch (Throwable $exception) {
            if (is_resource($temporary)) {
                fclose($temporary);
            }
            if ($temporaryPath !== null) {
                @unlink($temporaryPath);
            }
            if ($exception instanceof ApiProblemException) {
                throw $exception;
            }
            $this->fail('document_version_download_unavailable', 503);
        }
    }

    /** Resolve an explicit local private locator without guessing or following aliases. */
    public function resolvePrivatePath(string $diskName, string $relativePath): string
    {
        if (! in_array($diskName, config('document_versions.allowed_disks', []), true)
            || config("filesystems.disks.{$diskName}.driver") !== 'local'
            || config("filesystems.disks.{$diskName}.visibility") === 'public') {
            $this->fail('document_version_storage_invalid');
        }
        if ($relativePath === '' || strlen($relativePath) > 255
            || preg_match('/[\\\\\x00-\x1f\x7f:*?"<>|]/', $relativePath)
            || str_starts_with($relativePath, '/')) {
            $this->fail('document_version_storage_invalid');
        }
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[. ]$/', $segment)) {
                $this->fail('document_version_storage_invalid');
            }
        }
        try {
            $root = rtrim(Storage::disk($diskName)->path(''), '/\\');
            $resolvedRoot = realpath($root);
            $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            clearstatcache(true, $candidate);
            $resolved = realpath($candidate);
            if ($resolvedRoot === false || $resolved === false || ! is_file($resolved)) {
                $this->fail('document_version_unavailable');
            }
            if ($this->normalize($root) !== $this->normalize($resolvedRoot)
                || $this->normalize($candidate) !== $this->normalize($resolved)
                || ! $this->within($resolved, $resolvedRoot)) {
                $this->fail('document_version_storage_invalid');
            }
            $this->assertNotPublic($resolved);
            // Broad local root may contain the private import root; never grant an alias.
            $importRoot = config('filesystems.disks.project-imports.root');
            if ($diskName !== 'project-imports' && is_string($importRoot) && ($realImportRoot = realpath($importRoot)) !== false
                && $this->within($resolved, $realImportRoot)) {
                $this->fail('document_version_storage_invalid');
            }

            return $resolved;
        } catch (ApiProblemException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('document_version_storage_invalid');
        }
    }

    private function rejectImportedAlias(ProjectDocument $document, string $disk, string $path): void
    {
        if ($document->source_import_id !== null) {
            return;
        }
        if ($disk === 'project-imports') {
            $this->fail('document_version_storage_invalid');
        }
        // Compare canonical objects, not content hashes: independently uploaded bytes are valid.
        foreach (DocumentImport::query()->select(['storage_disk', 'storage_path'])->cursor() as $import) {
            try {
                if (config("filesystems.disks.{$import->storage_disk}.driver") !== 'local') {
                    continue;
                }
                $importPath = realpath(Storage::disk($import->storage_disk)->path($import->storage_path));
                if ($importPath !== false && $this->normalize($importPath) === $this->normalize($path)) {
                    $this->fail('document_version_storage_invalid');
                }
            } catch (ApiProblemException $exception) {
                throw $exception;
            } catch (Throwable) {
                $this->fail('document_version_storage_invalid');
            }
        }
    }

    private function validateMapping(ProjectDocument $document, array $mapping): void
    {
        if (array_diff(array_keys($mapping), ['storage_disk', 'storage_path', 'sha256', 'size_bytes']) !== []
            || ! is_string($mapping['storage_disk'] ?? null)
            || ! is_string($mapping['storage_path'] ?? null)
            || ! is_string($mapping['sha256'] ?? null)
            || ! is_int($mapping['size_bytes'] ?? null)
            || $mapping['size_bytes'] < 0
            || preg_match('/\A[0-9a-fA-F]{64}\z/', $mapping['sha256']) !== 1
            || $mapping['storage_path'] !== $document->path
            || ($document->storage_disk !== null && $mapping['storage_disk'] !== $document->storage_disk)) {
            $this->fail('document_version_storage_invalid');
        }
    }

    private function expectedHash(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/\A[0-9a-fA-F]{64}\z/', $value) !== 1) {
            $this->fail('document_version_integrity_failed');
        }

        return strtolower($value);
    }

    private function expectedSize(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            $this->fail('document_version_integrity_failed');
        }

        return (int) $value;
    }

    /** @param resource|null $destination
     * @return array{int, string, string}
     */
    private function readVerifiedSource(string $path, ?int $expectedSize, ?string $expectedHash, mixed $destination = null): array
    {
        $source = @fopen($path, 'rb');
        if (! is_resource($source)) {
            $this->fail('document_version_unavailable');
        }
        try {
            if (! flock($source, LOCK_SH | LOCK_NB)) {
                $this->fail('document_version_unavailable');
            }
            $before = fstat($source);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000
                || ($expectedSize !== null && $before['size'] !== $expectedSize)) {
                $this->fail('document_version_integrity_failed');
            }
            $bytes = 0;
            $sample = '';
            $hash = hash_init('sha256');
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);
                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    $this->fail('document_version_unavailable');
                }
                $bytes += strlen($chunk);
                if ($bytes > $before['size'] || ($expectedSize !== null && $bytes > $expectedSize)) {
                    $this->fail('document_version_integrity_failed');
                }
                hash_update($hash, $chunk);
                if (strlen($sample) < self::MIME_SAMPLE_BYTES) {
                    $sample .= substr($chunk, 0, self::MIME_SAMPLE_BYTES - strlen($sample));
                }
                if (is_resource($destination)) {
                    $offset = 0;
                    while ($offset < strlen($chunk)) {
                        $written = fwrite($destination, substr($chunk, $offset));
                        if ($written === false || $written === 0) {
                            $this->fail('document_version_download_unavailable', 503);
                        }
                        $offset += $written;
                    }
                }
            }
            $actualHash = hash_final($hash);
            $after = fstat($source);
            clearstatcache(true, $path);
            $current = @stat($path);
            foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key) {
                if ($after === false || $current === false || $before[$key] !== $after[$key] || $before[$key] !== $current[$key]) {
                    $this->fail('document_version_integrity_failed');
                }
            }
            if ($bytes !== $before['size'] || ($expectedSize !== null && $bytes !== $expectedSize)
                || ($expectedHash !== null && ! hash_equals($expectedHash, $actualHash))) {
                $this->fail('document_version_integrity_failed');
            }

            return [$bytes, $actualHash, $sample];
        } catch (ApiProblemException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('document_version_unavailable');
        } finally {
            flock($source, LOCK_UN);
            fclose($source);
        }
    }

    /** @return array{string, resource} */
    private function createPrivateTemporaryFile(): array
    {
        $directory = config('document_versions.temporary_directory');
        if (! is_string($directory) || $directory === '') {
            $this->fail('document_version_download_unavailable', 503);
        }
        // Check exposure before creating anything, including a configured public path.
        $this->assertNotPublic($directory);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->fail('document_version_download_unavailable', 503);
        }
        $resolved = realpath($directory);
        if ($resolved === false || $this->normalize($resolved) !== $this->normalize($directory) || ! @chmod($resolved, 0700)) {
            $this->fail('document_version_download_unavailable', 503);
        }
        $this->assertNotPublic($resolved);
        $path = $resolved.DIRECTORY_SEPARATOR.bin2hex(random_bytes(24)).'.tmp';
        $handle = @fopen($path, 'x+b');
        if (! is_resource($handle)) {
            $this->fail('document_version_download_unavailable', 503);
        }
        if (! @chmod($path, 0600)) {
            fclose($handle);
            @unlink($path);
            $this->fail('document_version_download_unavailable', 503);
        }

        return [$path, $handle];
    }

    private function assertNotPublic(string $path): void
    {
        $roots = [public_path(), storage_path('app/public')];
        foreach (config('filesystems.disks', []) as $configuration) {
            if (($configuration['visibility'] ?? null) === 'public' && ($configuration['driver'] ?? null) === 'local') {
                $roots[] = $configuration['root'];
            }
        }
        foreach ($roots as $root) {
            if ($this->within($path, $root) || (($resolved = realpath($root)) !== false && $this->within($path, $resolved))) {
                $this->fail('document_version_storage_invalid');
            }
        }
    }

    private function within(string $path, string $root): bool
    {
        return str_starts_with($this->normalize($path).'/', $this->normalize($root).'/');
    }

    private function normalize(string $path): string
    {
        $value = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
    }

    private function fail(string $code, int $status = 409): never
    {
        throw new ApiProblemException('The document version could not be verified or made available.', $code, $status);
    }
}
