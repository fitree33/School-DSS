<?php

namespace Tests\Unit\Signatures;

use App\Services\Signatures\PngStructureValidator;
use App\Services\Signatures\SignatureAssetStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class SignatureAssetEntryFilterTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_global_lock_never_reaches_path_resolution_or_candidate_processing(): void
    {
        // These fail-fast namespace spies exist only in this isolated worker.
        // In particular, realpath on a stream can otherwise fail silently without
        // invoking url_stat, so a stream wrapper alone cannot prove this boundary.
        require __DIR__.'/../../Support/ReconciliationEntryFunctionTrap.php';
        $this->assertTrue(stream_wrapper_register('signatureentries', SignatureEntryDirectoryStream::class));
        $key = str_repeat('b2', 32).'.png';
        SignatureEntryDirectoryStream::$names = ['.reconcile.lock', $key, $key.'.json', $key.'.lock'];
        SignatureEntryDirectoryStream::$targetOperations = [];
        SignatureEntryDirectoryStream::$observedNames = [];
        try {
            $scanner = new ReflectionMethod(SignatureAssetStorage::class, 'reconciliationCandidates');
            $candidates = iterator_to_array($scanner->invoke(
                new SignatureAssetStorage(new PngStructureValidator), 'signatureentries://opaque-directory',
            ), false);
            $this->assertSame(SignatureEntryDirectoryStream::$names, SignatureEntryDirectoryStream::$observedNames);
            $this->assertSame([$key], $candidates, 'Only this returned list can reach candidate path processing.');
            $this->assertSame([], SignatureEntryDirectoryStream::$targetOperations);
        } finally {
            stream_wrapper_unregister('signatureentries');
        }
    }

    #[DataProvider('entryNames')]
    public function test_real_scanner_filters_directory_entry_names_before_any_target_access(string $name, array $expected): void
    {
        $this->assertTrue(stream_wrapper_register('signatureentries', SignatureEntryDirectoryStream::class));
        SignatureEntryDirectoryStream::$names = [$name];
        SignatureEntryDirectoryStream::$targetOperations = [];
        SignatureEntryDirectoryStream::$observedNames = [];
        try {
            // Invoke the production scanner, not a copied predicate. This directory
            // supplies names only: every target operation fails, irrespective of
            // whether a real target would be a file, directory, link or reparse point.
            $scanner = new ReflectionMethod(SignatureAssetStorage::class, 'reconciliationCandidates');
            $candidates = iterator_to_array($scanner->invoke(
                new SignatureAssetStorage(new PngStructureValidator), 'signatureentries://opaque-directory',
            ), false);

            $this->assertSame([$name], SignatureEntryDirectoryStream::$observedNames);
            $this->assertSame($expected, $candidates);
            $this->assertSame([], SignatureEntryDirectoryStream::$targetOperations);
        } finally {
            stream_wrapper_unregister('signatureentries');
        }
    }

    public static function entryNames(): iterable
    {
        $key = str_repeat('a1', 32).'.png';
        yield 'obsolete global lock' => ['.reconcile.lock', []];
        yield 'normal candidate' => [$key, [$key]];
        yield 'receipt accompanies candidate but is not scanned' => [$key.'.json', []];
        yield 'permanent lease accompanies candidate but is not scanned' => [$key.'.lock', []];
        yield 'workspace lease' => ['.lease', []];
        yield 'dot' => ['.', []];
        yield 'parent' => ['..', []];
        yield 'uppercase key' => [strtoupper($key), []];
        yield 'short key' => [str_repeat('a', 63).'.png', []];
        yield 'long key' => [str_repeat('a', 65).'.png', []];
        yield 'path instead of entry name' => ['../'.$key, []];
        yield 'trailing newline' => [$key."\n", []];
    }
}

/** Name-only directory; no target metadata or content capability is provided. */
class SignatureEntryDirectoryStream
{
    public mixed $context;
    public static array $names = [];
    public static array $observedNames = [];
    public static array $targetOperations = [];
    private int $position = 0;

    public function dir_opendir(string $path, int $options): bool
    {
        return $path === 'signatureentries://opaque-directory';
    }

    public function dir_readdir(): string|false
    {
        $name = self::$names[$this->position++] ?? false;
        if ($name !== false && ! in_array($name, self::$observedNames, true)) {
            self::$observedNames[] = $name;
        }

        return $name;
    }

    public function dir_rewinddir(): bool
    {
        $this->position = 0;

        return true;
    }

    public function dir_closedir(): bool
    {
        return true;
    }

    public function url_stat(string $path, int $flags): never
    {
        $this->forbidden('stat/lstat/reparse', $path);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): never
    {
        $this->forbidden('open', $path);
    }

    public function stream_stat(): never
    {
        $this->forbidden('fstat');
    }

    public function stream_read(int $count): never
    {
        $this->forbidden('read');
    }

    public function stream_lock(int $operation): never
    {
        $this->forbidden('flock');
    }

    public function unlink(string $path): never
    {
        $this->forbidden('unlink', $path);
    }

    private function forbidden(string $operation, ?string $path = null): never
    {
        self::$targetOperations[] = [$operation, $path];
        throw new RuntimeException('The entry-name filter must not inspect targets.');
    }
}
