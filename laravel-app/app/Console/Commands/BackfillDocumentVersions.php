<?php

namespace App\Console\Commands;

use App\Enums\DocumentVersionCreatedVia;
use App\Exceptions\ApiProblemException;
use App\Models\DocumentVersion;
use App\Models\ProjectDocument;
use App\Services\Documents\DocumentVersionService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

final class BackfillDocumentVersions extends Command
{
    protected $signature = 'documents:backfill-versions
        {--apply : Register verified initial versions; otherwise only inspect}
        {--document-id=* : Restrict inspection to these positive document IDs}
        {--chunk=100 : Number of documents read per batch (1-1000)}
        {--manifest= : JSON object keyed by document ID, with explicit storage mapping}';

    protected $description = 'Verify existing originals and register immutable revision 1 (dry-run by default).';

    public function handle(DocumentVersionService $versions): int
    {
        try {
            $ids = $this->documentIds();
            $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 1000],
            ]);

            if ($chunk === false) {
                throw new RuntimeException('The --chunk option must be an integer between 1 and 1000.');
            }

            $manifest = $this->manifest();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $counts = ['would_register' => 0, 'registered' => 0, 'already_registered' => 0, 'failed' => 0];
        $seen = [];
        $this->line($apply ? 'Mode: apply' : 'Mode: dry-run (no writes)');
        $query = ProjectDocument::query()->orderBy('id');

        if ($ids !== []) {
            $query->whereKey($ids);
        }

        $query->chunkById($chunk, function ($documents) use ($versions, $apply, $manifest, &$counts, &$seen): void {
            foreach ($documents as $document) {
                $id = (int) $document->getKey();
                $seen[$id] = true;

                try {
                    if ($apply) {
                        $version = $versions->registerInitialVersion(
                            $document,
                            DocumentVersionCreatedVia::Backfill,
                            mapping: $manifest[(string) $id] ?? null,
                        );
                        $result = $version->wasRecentlyCreated ? 'registered' : 'already_registered';
                    } else {
                        $versions->previewInitialVersion($document, $manifest[(string) $id] ?? null);
                        $result = DocumentVersion::query()->where('project_document_id', $id)->exists()
                            ? 'already_registered' : 'would_register';
                    }

                    $counts[$result]++;
                    $this->line("Document {$id}: {$result}");
                } catch (Throwable $exception) {
                    $counts['failed']++;
                    // Never print exception messages from storage/database
                    // drivers: those can contain private paths or credentials.
                    $code = $exception instanceof ApiProblemException
                        ? $exception->errorCode : 'document_version_registration_failed';
                    $this->error("Document {$id}: failed ({$code})");
                }
            }
        });

        foreach ($ids as $id) {
            if (! isset($seen[$id])) {
                $counts['failed']++;
                $this->error("Document {$id}: failed (document_not_found)");
            }
        }

        foreach (array_keys($manifest) as $id) {
            if (! isset($seen[(int) $id]) && ($ids === [] || in_array((int) $id, $ids, true))) {
                // Unknown entries must not silently make a typo look like a
                // successful manifest application.
                if (! in_array((int) $id, $ids, true)) {
                    $counts['failed']++;
                    $this->error("Document {$id}: failed (manifest_document_not_found)");
                }
            }
        }

        $this->line(implode(', ', array_map(
            static fn (string $key, int $value): string => "{$key}={$value}",
            array_keys($counts),
            array_values($counts),
        )));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<int> */
    private function documentIds(): array
    {
        $ids = [];

        foreach ($this->option('document-id') as $value) {
            if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)
                || filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException('Every --document-id must be a positive integer.');
            }

            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, array<string, mixed>> */
    private function manifest(): array
    {
        $path = $this->option('manifest');

        if ($path === null) {
            return [];
        }

        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The manifest must be a readable JSON file.');
        }

        // A manifest is metadata, not a document stream. Bound its size to
        // avoid unintentionally loading an original file into memory.
        $size = filesize($path);

        if ($size === false || $size > 4 * 1024 * 1024) {
            throw new RuntimeException('The manifest must not exceed 4 MiB; use smaller batches.');
        }

        try {
            $manifest = json_decode(file_get_contents($path), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The manifest is not valid JSON.');
        }

        if (! $manifest instanceof \stdClass) {
            throw new RuntimeException('The manifest must be a JSON object keyed by document ID.');
        }

        $result = [];

        foreach (get_object_vars($manifest) as $id => $entry) {
            if (! preg_match('/^[1-9][0-9]*$/D', (string) $id)
                || filter_var($id, FILTER_VALIDATE_INT) === false
                || ! $entry instanceof \stdClass) {
                throw new RuntimeException('Each manifest entry must map a positive document ID to an object.');
            }

            $mapping = get_object_vars($entry);

            if (array_diff(array_keys($mapping), ['storage_disk', 'storage_path', 'sha256', 'size_bytes']) !== []
                || array_diff(['storage_disk', 'storage_path', 'sha256', 'size_bytes'], array_keys($mapping)) !== []) {
                throw new RuntimeException('Each manifest entry requires exactly storage_disk, storage_path, sha256 and size_bytes.');
            }

            if (! is_string($mapping['storage_disk']) || trim($mapping['storage_disk']) === ''
                || ! is_string($mapping['storage_path']) || trim($mapping['storage_path']) === ''
                || ! is_string($mapping['sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $mapping['sha256'])
                || ! is_int($mapping['size_bytes']) || $mapping['size_bytes'] < 0) {
                throw new RuntimeException('Manifest storage values, lowercase SHA-256 and nonnegative integer size are required.');
            }

            $result[(string) $id] = $mapping;
        }

        return $result;
    }
}
