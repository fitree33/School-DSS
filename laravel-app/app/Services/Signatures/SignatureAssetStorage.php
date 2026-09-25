<?php

namespace App\Services\Signatures;

use App\DTOs\Signatures\OwnedSignatureFile;
use App\DTOs\Signatures\OwnedSignatureWorkspace;
use App\Exceptions\ApiProblemException;
use App\Models\SignatureAsset;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class SignatureAssetStorage
{
    private const OUTPUT_LIMIT = 8388608;

    public function __construct(
        private readonly PngStructureValidator $validator,
        private readonly SignatureAssetReferenceGuard $references = new SignatureAssetReferenceGuard,
        private readonly SignatureReconciliationPolicy $reconciliation = new SignatureReconciliationPolicy,
    ) {}

    public function beginRequest(): OwnedSignatureWorkspace
    {
        [$root, $temporary] = $this->roots();
        $directory = $temporary.DIRECTORY_SEPARATOR.bin2hex(random_bytes(32));
        if (! @mkdir($directory, 0700)) {
            $this->fail();
        }
        $this->assertPath($directory);
        $lease = @fopen($directory.DIRECTORY_SEPARATOR.'.lease', 'x+b');
        if (! is_resource($lease) || ! @flock($lease, LOCK_EX | LOCK_NB)) {
            if (is_resource($lease)) {
                fclose($lease);
                @unlink($directory.DIRECTORY_SEPARATOR.'.lease');
            }
            @rmdir($directory);
            $this->fail();
        }

        return new OwnedSignatureWorkspace($directory, $lease);
    }

    /** Copy a framework-owned upload; caller/framework retains ownership of the input path. */
    public function stageUpload(#[\SensitiveParameter] string $sourcePath, OwnedSignatureWorkspace $workspace): string
    {
        $this->assertWorkspace($workspace);
        $this->assertUploadSource($sourcePath);
        $path = $workspace->directory().DIRECTORY_SEPARATOR.bin2hex(random_bytes(24)).'.upload';
        $input = @fopen($sourcePath, 'rb');
        $output = @fopen($path, 'x+b');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
                $this->removeCandidateFile($path);
            }
            $this->fail();
        }
        try {
            $bytes = 0;
            while (! feof($input)) {
                $chunk = @fread($input, 65536);
                if ($chunk === false || ($chunk === '' && ! feof($input))) {
                    $this->fail();
                }
                $bytes += strlen($chunk);
                if ($bytes > 2097152) {
                    $this->fail('signature_upload_too_large', 413);
                }
                $this->writeAll($output, $chunk);
            }
            if ($bytes === 0) {
                $this->fail('signature_image_invalid', 422);
            }
            if (! @fflush($output)) {
                $this->fail();
            }

            return $path;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** Refuse PHP's OS/default-temp fallback and every unapproved source namespace. */
    public function assertUploadSource(#[\SensitiveParameter] string $sourcePath): void
    {
        $directory = config('signature_assets.php_upload_directory');
        if (! is_string($directory) || $directory === '') {
            $this->fail();
        }
        $this->assertAbsolute($directory);
        $this->assertPath($directory);
        foreach (array_merge($this->forbiddenRoots(), $this->roots()) as $other) {
            if ($this->overlap($directory, $other)
                || (($real = realpath($other)) !== false && $this->overlap($directory, $real))) {
                $this->fail();
            }
        }
        $allowed = config('signature_assets.php_upload_allowed_service_sids', []);
        if (! is_array($allowed)) {
            $this->fail();
        }
        foreach ($allowed as $sid) {
            if (! is_string($sid) || preg_match('/\AS-1-5-21-[0-9]+-[0-9]+-[0-9]+-[0-9]+\z/D', $sid) !== 1) {
                $this->fail();
            }
        }
        $this->assertPrivateDirectory($directory, $allowed);
        $this->assertPath($sourcePath);
        $stat = @lstat($sourcePath);
        if ($this->normalize(dirname($sourcePath)) !== $this->normalize($directory)
            || ! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['nlink'] ?? 1) !== 1) {
            $this->fail();
        }
    }

    public function storeNormalized(#[\SensitiveParameter] string $bytes, string $publicId, OwnedSignatureWorkspace $workspace): OwnedSignatureFile
    {
        $this->assertWorkspace($workspace);
        [$root] = $this->roots();
        if (strlen($bytes) < 1 || strlen($bytes) > self::OUTPUT_LIMIT) {
            $this->fail('signature_image_limits_exceeded', 422);
        }
        $this->validator->validateNormalized($bytes);
        $key = $this->newStorageKey();
        $this->assertKey($key);
        $path = $root.DIRECTORY_SEPARATOR.$key;
        $leasePath = $path.'.lock';
        $lease = @fopen($leasePath, 'x+b');
        if (! is_resource($lease)) {
            $this->fail();
        }
        $file = null;
        $created = false;
        $receiptCreated = false;
        try {
            if (! @flock($lease, LOCK_EX | LOCK_NB)) {
                $this->fail();
            }
            $file = @fopen($path, 'x+b');
            if (! is_resource($file)) {
                $this->fail();
            }
            $created = true;
            if (! @flock($file, LOCK_EX | LOCK_NB)) {
                $this->fail();
            }
            $this->writeAll($file, $bytes);
            if (! @fflush($file) || @fseek($file, 0) !== 0) {
                $this->fail();
            }
            // Persisted metadata is derived exclusively from the final file handle.
            $stored = $this->boundedRead($file);
            $this->validator->validateNormalized($stored);
            [$width, $height] = $this->decodeDimensions($stored, $workspace);
            $identity = @fstat($file);
            if ($identity === false || $identity['size'] !== strlen($stored)) {
                $this->fail();
            }
            $receipt = new OwnedSignatureFile($key, hash('sha256', $stored), strlen($stored), $width, $height, $publicId, $root, $identity, $file, $lease);
            $journal = @fopen($path.'.json', 'x+b');
            if (! is_resource($journal)) {
                $this->fail();
            }
            $receiptCreated = true;
            try {
                $this->writeAll($journal, json_encode([
                    'version' => 1, 'public_id' => $publicId, 'key' => $key,
                    'sha256' => $receipt->sha256, 'size_bytes' => $receipt->sizeBytes,
                    'created_at' => time(),
                ], JSON_THROW_ON_ERROR));
                if (! @fflush($journal)) {
                    $this->fail();
                }
            } finally {
                fclose($journal);
            }

            return $receipt;
        } catch (Throwable $exception) {
            if (is_resource($file)) {
                fclose($file);
            }
            if ($created && $this->safeRootStillMatches($root)) {
                @unlink($path);
            }
            if ($receiptCreated && $this->safeRootStillMatches($root)) {
                $this->removeCandidateFile($path.'.json');
            }
            fclose($lease);
            // A successfully created lease is permanent: never reopen this key on a new inode.
            if ($exception instanceof ApiProblemException) {
                throw $exception;
            }
            $this->fail();
        }
    }

    /** Release handles after commit OR ambiguous commit. No final bytes are deleted. */
    public function preserve(OwnedSignatureFile $file): void
    {
        if ($file->released) {
            return;
        }
        foreach (['handle', 'lease'] as $property) {
            if (is_resource($file->{$property})) {
                @flock($file->{$property}, LOCK_UN);
                fclose($file->{$property});
            }
        }
        $file->released = true;
    }

    /** Only definite rollback may call this; ownership cannot be recovered from a hash. */
    public function rollback(OwnedSignatureFile $file): void
    {
        if ($file->released) {
            return;
        }
        $path = $file->root.DIRECTORY_SEPARATOR.$file->storageKey;
        try {
            if (! $this->safeRootStillMatches($file->root)
                || ! $this->sameOwnedFile($path, $file->identity, $file->handle)
                || ! $this->candidateHandleMatches($path.'.lock', $file->lease)) {
                $this->cleanupWarning();

                return;
            }
            // Windows may require closing the PNG handle, but the lease remains exclusive.
            fclose($file->handle);
            $file->handle = null;
            $this->reconciliationCheckpoint('rollback-before-delete', $path);
            if (! $this->removeCandidateFile($path)) {
                $this->cleanupWarning();

                return;
            }
            if (is_file($path.'.json') && ! $this->removeCandidateFile($path.'.json')) {
                $this->cleanupWarning();
            }
        } finally {
            $this->preserve($file);
        }
    }

    public function cleanup(OwnedSignatureWorkspace $workspace): void
    {
        if (! is_resource($workspace->lease)) {
            return;
        }
        try {
            $this->assertWorkspace($workspace);
            foreach (new \DirectoryIterator($workspace->directory()) as $entry) {
                if ($entry->isDot() || $entry->getFilename() === '.lease') {
                    continue;
                }
                $this->assertPath($entry->getPathname());
                if (! $entry->isFile() || ! @unlink($entry->getPathname())) {
                    $this->cleanupWarning();
                }
            }
            fclose($workspace->lease);
            $workspace->lease = null;
            if (! @unlink($workspace->directory().DIRECTORY_SEPARATOR.'.lease') || ! @rmdir($workspace->directory())) {
                $this->cleanupWarning();
            }
        } catch (Throwable) {
            if (is_resource($workspace->lease)) {
                fclose($workspace->lease);
                $workspace->lease = null;
            }
            $this->cleanupWarning();
        }
    }

    /** Verified in-memory snapshot: bounded to 8 MiB and independent of later source changes. */
    public function readVerified(SignatureAsset $asset): string
    {
        [$root] = $this->roots();
        $this->assertKey($asset->storage_key);
        $path = $root.DIRECTORY_SEPARATOR.$asset->storage_key;
        $this->assertPath($path);
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            $this->fail();
        }
        try {
            if (! @flock($handle, LOCK_SH | LOCK_NB)) {
                $this->fail();
            }
            $before = @fstat($handle);
            $bytes = $this->boundedRead($handle);
            if ($before === false || ! $this->sameOwnedFile($path, $before, $handle)
                || strlen($bytes) !== (int) $asset->size_bytes
                || ! is_string($asset->sha256) || ! hash_equals($asset->sha256, hash('sha256', $bytes))) {
                $this->fail('signature_asset_integrity_failed', 409);
            }
            try {
                $this->validator->validateNormalized($bytes);
            } catch (Throwable) {
                $this->fail('signature_asset_integrity_failed', 409);
            }
            $header = unpack('Nwidth/Nheight', substr($bytes, 16, 8));
            if ($header['width'] !== (int) $asset->width || $header['height'] !== (int) $asset->height || $asset->mime_type !== 'image/png') {
                $this->fail('signature_asset_integrity_failed', 409);
            }

            return $bytes;
        } catch (ApiProblemException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Per-run observations only. Neither these counts nor a scan result authorize deletion. */
    public function reconcile(bool $apply = false, int $minimumAgeSeconds = 3600): array
    {
        [$root] = $this->roots(false);
        $counts = array_fill_keys([
            'referenced', 'busy', 'unresolved', 'candidate', 'corrupt', 'disappeared',
            'recent', 'activity_unknown', 'removed', 'partial_error', 'identity_mismatch',
        ], 0);
        $names = [
            SignatureReconciliationPolicy::REFERENCED => 'referenced',
            SignatureReconciliationPolicy::ACTIVE_OWNED => 'busy',
            SignatureReconciliationPolicy::UNRESOLVED => 'unresolved',
            SignatureReconciliationPolicy::CANDIDATE => 'candidate',
            SignatureReconciliationPolicy::CORRUPT => 'corrupt',
            SignatureReconciliationPolicy::DISAPPEARED => 'disappeared',
            SignatureReconciliationPolicy::PROTECTED_RECENT => 'recent',
        ];
        foreach ($this->reconciliationCandidates($root) as $key) {
            // Recheck even injected enumerators: never inspect any unrelated pathname.
            if (! is_string($key) || preg_match('/\A[0-9a-f]{64}\.png\z/D', $key) !== 1) {
                continue;
            }
            $path = $root.DIRECTORY_SEPARATOR.$key;
            $this->reconciliationCheckpoint('after-scan', $path);
            $result = $apply
                ? $this->applyCandidate($root, $path, $minimumAgeSeconds)
                : $this->observeCandidate($path, $minimumAgeSeconds);
            if (isset($names[$result['state']])) {
                $counts[$names[$result['state']]]++;
            }
            foreach (['removed', 'partial_error', 'identity_mismatch'] as $flag) {
                $counts[$flag] += (int) ($result[$flag] ?? false);
            }
            if (! $apply && ! in_array($result['state'], [SignatureReconciliationPolicy::REFERENCED, SignatureReconciliationPolicy::DISAPPEARED], true)) {
                $counts['activity_unknown']++;
            }
        }

        return $counts;
    }

    /** Filter names before stat/open/path validation, including retired coordination artifacts. */
    protected function reconciliationCandidates(string $root): iterable
    {
        foreach (new \DirectoryIterator($root) as $entry) {
            $name = $entry->getFilename();
            if (preg_match('/\A[0-9a-f]{64}\.png\z/D', $name) === 1) {
                yield $name;
            }
        }
    }

    private function observeCandidate(string $path, int $minimumAgeSeconds): array
    {
        try {
            $snapshot = $this->inspectCandidate($path, $minimumAgeSeconds);
            if ($snapshot['state'] !== SignatureReconciliationPolicy::CANDIDATE) {
                return $this->reconciliation->observe($snapshot['state']);
            }
            $observation = $this->references->observe(basename($path), $snapshot['record']['public_id']);

            return $this->reconciliation->observe($observation);
        } catch (Throwable) {
            return $this->reconciliation->observe(SignatureReconciliationPolicy::UNRESOLVED);
        }
    }

    private function applyCandidate(string $root, string $path, int $minimumAgeSeconds): array
    {
        $lease = null;
        $snapshot = null;

        return $this->reconciliation->apply(
            function () use ($path, &$lease): ?string {
                if ($this->candidateMissing($path)) {
                    return SignatureReconciliationPolicy::DISAPPEARED;
                }
                $leasePath = $path.'.lock';
                $this->assertPath($leasePath);
                $stat = $this->candidateStat($leasePath);
                if (! $this->regularSingleLink($stat)) {
                    return SignatureReconciliationPolicy::CORRUPT;
                }
                $lease = $this->openCandidateFile($leasePath, 'r+b');
                if (! is_resource($lease)) {
                    return SignatureReconciliationPolicy::UNRESOLVED;
                }
                $wouldBlock = 0;
                if (! $this->lockCandidate($lease, $wouldBlock)) {
                    fclose($lease);
                    $lease = null;

                    return $wouldBlock ? SignatureReconciliationPolicy::ACTIVE_OWNED : SignatureReconciliationPolicy::UNRESOLVED;
                }
                if (! $this->candidateHandleMatches($leasePath, $lease)) {
                    fclose($lease);
                    $lease = null;

                    return SignatureReconciliationPolicy::UNRESOLVED;
                }
                $this->reconciliationCheckpoint('after-ownership', $path);

                return null;
            },
            function () use ($path, $minimumAgeSeconds, &$snapshot): string {
                $snapshot = $this->inspectCandidate($path, $minimumAgeSeconds);

                return $snapshot['state'];
            },
            function (\Closure $whenAbsent) use ($path, &$snapshot): array {
                return $this->references->protect(basename($path), $snapshot['record']['public_id'], $whenAbsent);
            },
            function () use ($root, $path, $minimumAgeSeconds, &$lease, &$snapshot): array {
                $removed = false;
                try {
                    $this->reconciliationCheckpoint('before-delete', $path);
                    if (! $this->safeRootStillMatches($root) || ! $this->candidateHandleMatches($path.'.lock', $lease)) {
                        return ['state' => SignatureReconciliationPolicy::UNRESOLVED];
                    }
                    $fresh = $this->inspectCandidate($path, $minimumAgeSeconds);
                    if ($fresh['state'] !== SignatureReconciliationPolicy::CANDIDATE) {
                        // An initially valid candidate changed or became unreadable under
                        // our lease. This is uncertainty, not a stable corruption finding.
                        return ['state' => $fresh['state'] === SignatureReconciliationPolicy::DISAPPEARED
                            ? SignatureReconciliationPolicy::DISAPPEARED
                            : SignatureReconciliationPolicy::UNRESOLVED];
                    }
                    if (! $this->sameCandidateIdentity($snapshot['png_identity'], $fresh['png_identity'])
                        || ! $this->sameCandidateIdentity($snapshot['journal_identity'], $fresh['journal_identity'])
                        // The receipt digest binds every byte, including both reference
                        // identities and the PNG digest checked by each fresh inspection.
                        || ! hash_equals($snapshot['journal_sha256'], $fresh['journal_sha256'])) {
                        return ['state' => SignatureReconciliationPolicy::UNRESOLVED];
                    }
                    if (! $this->removeCandidateFile($path)) {
                        return ['state' => SignatureReconciliationPolicy::UNRESOLVED];
                    }
                    $removed = true;
                    if (! $this->removeCandidateFile($path.'.json')) {
                        return ['state' => SignatureReconciliationPolicy::SAFE_ORPHAN, 'removed' => true, 'partial_error' => true];
                    }

                    return ['state' => SignatureReconciliationPolicy::SAFE_ORPHAN, 'removed' => true];
                } catch (Throwable) {
                    return ['state' => SignatureReconciliationPolicy::UNRESOLVED, 'removed' => $removed, 'partial_error' => $removed];
                }
            },
            function () use (&$lease): void {
                if (is_resource($lease)) {
                    fclose($lease);
                    $lease = null;
                }
            },
        );
    }

    /** A stable bounded observation; no flock or provisioning, also used under apply ownership. */
    private function inspectCandidate(string $path, int $minimumAgeSeconds): array
    {
        if ($this->candidateMissing($path)) {
            return ['state' => SignatureReconciliationPolicy::DISAPPEARED];
        }
        foreach ([$path, $path.'.json'] as $item) {
            $stat = $this->candidateStat($item);
            if ($stat === false) {
                return ['state' => SignatureReconciliationPolicy::UNRESOLVED];
            }
            if (! $this->regularSingleLink($stat)) {
                return ['state' => SignatureReconciliationPolicy::CORRUPT];
            }
            $this->assertPath($item);
        }
        $journal = $this->readCandidateFile($path.'.json', 4096);
        if ($journal === null) {
            return ['state' => SignatureReconciliationPolicy::UNRESOLVED];
        }
        $record = json_decode($journal['bytes'], true);
        if (! is_array($record) || ($record['version'] ?? null) !== 1
            || ($record['key'] ?? null) !== basename($path)
            || ! is_string($record['public_id'] ?? null)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $record['public_id']) !== 1
            || ! is_string($record['sha256'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $record['sha256']) !== 1
            || ! is_int($record['size_bytes'] ?? null) || $record['size_bytes'] < 1 || $record['size_bytes'] > self::OUTPUT_LIMIT
            || ! is_int($record['created_at'] ?? null)) {
            return ['state' => SignatureReconciliationPolicy::CORRUPT];
        }
        $age = $this->reconciliation->ageState($record['created_at'], $this->reconciliationTime(), $minimumAgeSeconds);
        if ($age !== SignatureReconciliationPolicy::CANDIDATE) {
            return ['state' => $age];
        }
        $png = $this->readCandidateFile($path, self::OUTPUT_LIMIT);
        if ($png === null) {
            return ['state' => SignatureReconciliationPolicy::UNRESOLVED];
        }
        if (strlen($png['bytes']) !== $record['size_bytes'] || ! hash_equals($record['sha256'], hash('sha256', $png['bytes']))) {
            return ['state' => SignatureReconciliationPolicy::CORRUPT];
        }
        try {
            $this->validator->validateNormalized($png['bytes']);
        } catch (Throwable) {
            return ['state' => SignatureReconciliationPolicy::CORRUPT];
        }

        return [
            'state' => SignatureReconciliationPolicy::CANDIDATE,
            'record' => $record, 'journal_identity' => $journal['identity'],
            'journal_sha256' => hash('sha256', $journal['bytes']), 'png_identity' => $png['identity'],
        ];
    }

    /** A successful parent enumeration distinguishes disappearance from an unreadable path. */
    private function candidateMissing(string $path): bool
    {
        if ($this->candidateStat($path) !== false) {
            return false;
        }
        $this->assertPath(dirname($path));
        $entries = @scandir(dirname($path));
        if (! is_array($entries) || in_array(basename($path), $entries, true)) {
            $this->fail();
        }

        return true;
    }

    private function regularSingleLink(array|false $stat): bool
    {
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && ($stat['nlink'] ?? 1) === 1;
    }

    private function candidateHandleMatches(string $path, mixed $handle): bool
    {
        $this->assertPath($path);
        $held = is_resource($handle) ? @fstat($handle) : false;
        $current = $this->candidateStat($path);

        return $this->regularSingleLink($held) && $this->regularSingleLink($current)
            && $this->sameCandidateIdentity($held, $current);
    }

    private function sameCandidateIdentity(array $one, array $two): bool
    {
        $first = $this->stableFileSnapshot($one);
        $second = $this->stableFileSnapshot($two);
        if ($first === null || $second === null) {
            return false;
        }
        foreach ($first as $field => $value) {
            if ($value !== $second[$field]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stable observations, never stand-alone identity or deletion authorization.
     * Named fields avoid numeric aliases and input-array ordering; atime changes
     * on reads. Allocation hints (blocks/blksize) and regular-file rdev are omitted.
     *
     * Windows mode is not an ACL and ctime is not Unix inode-change evidence.
     * Keep those values as change detectors; existing path/DACL checks still apply.
     * dev/ino can be signed opaque Windows identifiers. Zero/unsupported values
     * are not object-identity proof: callers also require the canonical private
     * namespace, permanent held lease and fresh receipt/PNG integrity checks.
     * Comparing their observations still rejects a change in identity availability.
     */
    private function stableFileSnapshot(array $stat): ?array
    {
        $stable = [];
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if (! isset($stat[$field]) || ! is_int($stat[$field])) {
                return null;
            }
            $stable[$field] = $stat[$field];
        }

        return $stable;
    }

    protected function candidateStat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    /** Null means unavailable/changing, never proof of corruption or absence. */
    protected function readCandidateFile(string $path, int $limit): ?array
    {
        $handle = $this->openCandidateFile($path, 'rb');
        if (! is_resource($handle)) {
            return null;
        }
        try {
            $before = @fstat($handle);
            if (! $this->regularSingleLink($before)) {
                return null;
            }
            $bytes = @stream_get_contents($handle, $limit + 1);
            $after = @fstat($handle);
            $pathStat = $this->candidateStat($path);
            if (! is_string($bytes) || strlen($bytes) > $limit || strlen($bytes) !== $before['size']
                || ! is_array($after) || ! is_array($pathStat)
                || ! $this->sameCandidateIdentity($before, $after) || ! $this->sameCandidateIdentity($before, $pathStat)) {
                return null;
            }

            return ['bytes' => $bytes, 'identity' => $before];
        } finally {
            fclose($handle);
        }
    }

    protected function openCandidateFile(string $path, string $mode): mixed
    {
        return @fopen($path, $mode);
    }

    protected function lockCandidate(mixed $lease, int &$wouldBlock): bool
    {
        return @flock($lease, LOCK_EX | LOCK_NB, $wouldBlock);
    }

    protected function removeCandidateFile(string $path): bool
    {
        return @unlink($path);
    }

    protected function reconciliationTime(): int
    {
        return time();
    }

    /** Internal deterministic scheduling seam; no persisted coordination or public capability. */
    protected function reconciliationCheckpoint(string $phase, string $path): void {}

    protected function newStorageKey(): string
    {
        return bin2hex(random_bytes(32)).'.png';
    }

    /** @return array{string,string} */
    private function roots(bool $createMissing = true): array
    {
        $root = config('signature_assets.storage_root');
        $temporary = config('signature_assets.temporary_directory');
        if (! is_string($root) || ! is_string($temporary)) {
            $this->fail();
        }
        $forbidden = $this->forbiddenRoots();
        foreach ([$root, $temporary] as $directory) {
            $this->assertAbsolute($directory);
            foreach ($forbidden as $other) {
                if ($this->overlap($directory, $other) || (($real = realpath($other)) !== false && $this->overlap($directory, $real))) {
                    $this->fail();
                }
            }
        }
        if ($this->overlap($root, $temporary)) {
            $this->fail();
        }
        foreach ([$root, $temporary] as $directory) {
            $this->assertPath($directory, true);
            if (! is_dir($directory)) {
                if (! $createMissing || ! @mkdir($directory, 0700, true)) {
                    $this->fail();
                }
                $this->secureCreatedDirectory($directory);
            }
            $this->assertPath($directory);
            $this->assertPrivateDirectory($directory);
        }

        return [rtrim($root, '/\\'), rtrim($temporary, '/\\')];
    }

    /** Every storage adapter or public link is outside this private namespace. */
    private function forbiddenRoots(): array
    {
        $forbidden = [storage_path('app'), public_path()];
        foreach (config('filesystems.disks', []) as $disk) {
            if (($disk['driver'] ?? null) === 'local' && is_string($disk['root'] ?? null)) {
                $forbidden[] = $disk['root'];
            }
        }
        foreach (config('filesystems.links', []) as $link => $target) {
            if (is_string($link)) {
                $forbidden[] = $link;
            }
            if (is_string($target)) {
                $forbidden[] = $target;
            }
        }

        return $forbidden;
    }

    private function assertWorkspace(OwnedSignatureWorkspace $workspace): void
    {
        [, $temporary] = $this->roots();
        if (! is_resource($workspace->lease) || $this->normalize(dirname($workspace->directory())) !== $this->normalize($temporary)
            || preg_match('/\A[0-9a-f]{64}\z/D', basename($workspace->directory())) !== 1) {
            $this->fail();
        }
        $this->assertPath($workspace->directory());
    }

    private function assertAbsolute(#[\SensitiveParameter] string $path): void
    {
        $normal = str_replace('\\', '/', $path);
        if ($path === '' || preg_match('/[\x00-\x1f\x7f*?"<>|]/', $normal)
            || (PHP_OS_FAMILY === 'Windows' ? preg_match('/\A[A-Za-z]:\//', $normal) !== 1 : ! str_starts_with($normal, '/'))
            || str_starts_with($normal, '//')) {
            $this->fail();
        }
        foreach (explode('/', preg_replace('/\A[A-Za-z]:\//', '', ltrim($normal, '/'))) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')
                || preg_match('/[. ]$/', $segment) || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $segment)) {
                $this->fail();
            }
        }
    }

    private function assertPath(#[\SensitiveParameter] string $path, bool $allowMissing = false): void
    {
        $this->assertAbsolute($path);
        $cursor = $path;
        do {
            clearstatcache(true, $cursor);
            if (file_exists($cursor) || is_link($cursor)) {
                $resolved = realpath($cursor);
                if ($resolved === false || is_link($cursor) || $this->normalize($cursor) !== $this->normalize($resolved)) {
                    $this->fail();
                }
            } elseif (! $allowMissing) {
                $this->fail();
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        } while (true);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->powershell('$p=$env:SIGNATURE_PRIVATE_PATH; while($p){ if(Test-Path -LiteralPath $p){ $i=Get-Item -Force -LiteralPath $p -ErrorAction Stop; if(($i.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0){ exit 9 } }; $q=[IO.Path]::GetDirectoryName($p); if($q -eq $p){ break }; $p=$q }', $path);
        }
    }

    private function secureCreatedDirectory(#[\SensitiveParameter] string $directory): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Persist only the DACL. Owner and SACL are untouched; no SeSecurityPrivilege is needed.
            $this->powershell(<<<'POWERSHELL'
                $access=[Security.AccessControl.AccessControlSections]::Access
                $acl=New-Object Security.AccessControl.DirectorySecurity
                $ids=@([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,"S-1-5-18","S-1-5-32-544")
                $sddl="D:P"
                foreach($id in $ids){$sddl+="(A;OICI;FA;;;"+$id+")"}
                $acl.SetSecurityDescriptorSddlForm($sddl,$access)
                [IO.Directory]::SetAccessControl($env:SIGNATURE_PRIVATE_PATH,$acl)
                POWERSHELL, $directory);
        } elseif (! @chmod($directory, 0700)) {
            $this->fail();
        }
    }

    private function assertPrivateDirectory(#[\SensitiveParameter] string $directory, array $approvedServiceSids = []): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $extra = implode(',', array_map(static fn (string $sid): string => '"'.$sid.'"', $approvedServiceSids));
            $this->powershell('$ids=@([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,"S-1-5-18","S-1-5-32-544"); $ids+=@('.$extra.'); $acl=Get-Acl -LiteralPath $env:SIGNATURE_PRIVATE_PATH -ErrorAction Stop; if(-not $acl.AreAccessRulesProtected){exit 8}; foreach($r in $acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])){if($r.AccessControlType -eq "Allow" -and $ids -notcontains $r.IdentityReference.Value){exit 9}}', $directory);
        } elseif ((fileperms($directory) & 0077) !== 0) {
            $this->fail();
        }
    }

    private function powershell(string $script, #[\SensitiveParameter] string $path): void
    {
        try {
            $process = new Process(['powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', '$ErrorActionPreference="Stop"; '.$script], null, ['SIGNATURE_PRIVATE_PATH' => $path]);
            $process->setTimeout(10);
            $process->disableOutput();
            $process->run();
            if (! $process->isSuccessful()) {
                $this->fail();
            }
        } catch (Throwable) {
            $this->fail();
        }
    }

    private function safeRootStillMatches(#[\SensitiveParameter] string $root): bool
    {
        try {
            $this->assertPath($root);
            [$configured] = $this->roots(false);

            return $this->normalize($root) === $this->normalize($configured);
        } catch (Throwable) {
            return false;
        }
    }

    private function sameOwnedFile(#[\SensitiveParameter] string $path, array $expected, mixed $handle): bool
    {
        try {
            $this->assertPath($path);
            $current = @stat($path);
            $held = is_resource($handle) ? @fstat($handle) : false;
            foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
                if ($current === false || $held === false || $current[$field] !== $expected[$field] || $held[$field] !== $expected[$field]) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function assertKey(#[\SensitiveParameter] mixed $key): void
    {
        if (! is_string($key) || preg_match('/\A[0-9a-f]{64}\.png\z/D', $key) !== 1) {
            $this->fail('signature_asset_integrity_failed', 409);
        }
    }

    private function writeAll(mixed $handle, #[\SensitiveParameter] string $bytes): void
    {
        for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
            $written = @fwrite($handle, substr($bytes, $offset, 65536));
            if ($written === false || $written === 0) {
                $this->fail();
            }
        }
    }

    private function boundedRead(mixed $handle): string
    {
        $stat = @fstat($handle);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 1 || $stat['size'] > self::OUTPUT_LIMIT) {
            $this->fail('signature_asset_integrity_failed', 409);
        }
        $bytes = '';
        while (! feof($handle)) {
            $chunk = @fread($handle, 65536);
            if ($chunk === false || ($chunk === '' && ! feof($handle))) {
                $this->fail();
            }
            $bytes .= $chunk;
            if (strlen($bytes) > self::OUTPUT_LIMIT) {
                $this->fail('signature_asset_integrity_failed', 409);
            }
        }

        return $bytes;
    }

    private function decodeDimensions(#[\SensitiveParameter] string $bytes, OwnedSignatureWorkspace $workspace): array
    {
        $path = $workspace->directory().DIRECTORY_SEPARATOR.bin2hex(random_bytes(24)).'.readback';
        $handle = @fopen($path, 'x+b');
        if (! is_resource($handle)) {
            $this->fail();
        }
        try {
            $this->writeAll($handle, $bytes);
            if (! @fflush($handle)) {
                $this->fail();
            }
        } finally {
            fclose($handle);
        }
        $image = null;
        $warning = false;
        set_error_handler(static function () use (&$warning): bool { $warning = true; return true; });
        try {
            if (! function_exists('imagecreatefrompng')) {
                $this->fail('signature_decoder_unavailable', 503);
            }
            $image = imagecreatefrompng($path);
            if (! $image instanceof \GdImage || $warning) {
                $this->fail('signature_asset_integrity_failed', 409);
            }

            return [imagesx($image), imagesy($image)];
        } catch (ApiProblemException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('signature_asset_integrity_failed', 409);
        } finally {
            restore_error_handler();
            unset($image);
            if (! @unlink($path)) {
                $this->cleanupWarning();
            }
        }
    }

    private function overlap(string $one, string $two): bool
    {
        $one = $this->normalize($one).'/';
        $two = $this->normalize($two).'/';

        return str_starts_with($one, $two) || str_starts_with($two, $one);
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private function cleanupWarning(): void
    {
        Log::warning('signature_asset.cleanup_incomplete', ['error_code' => 'signature_asset_cleanup_incomplete']);
    }

    private function fail(string $code = 'signature_asset_unavailable', int $status = 503): never
    {
        throw new ApiProblemException('The signature asset could not be made available.', $code, $status);
    }
}
