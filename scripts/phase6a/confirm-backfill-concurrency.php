<?php

declare(strict_types=1);

use App\Enums\DocumentImportStatus;
use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Models\AuditLog;
use App\Models\DocumentContent;
use App\Models\DocumentImport;
use App\Models\DocumentVersion;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Services\Imports\ImportConfirmationService;
use App\Services\Imports\ProjectImportPayloadValidator;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;

require __DIR__.'/bootstrap.php';
$runtime = $argv[2] ?? dirname(__DIR__, 2).'/.foundation-runtime/phase5-phase6a-confirm-backfill';
$app = phaseSixBootstrap($runtime);
$mode = $argv[1] ?? 'run';

function requireCondition(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function writeJson(string $path, array $value): void
{
    requireCondition(file_put_contents($path.'.writing', json_encode($value, JSON_THROW_ON_ERROR)) !== false, 'Could not write a worker record.');
    requireCondition(rename($path.'.writing', $path), 'Could not publish a worker record.');
}

function readJson(string $path): array
{
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function runBackfill(int $documentId): array
{
    $exit = Artisan::call('documents:backfill-versions', ['--apply' => true, '--document-id' => [(string) $documentId]]);
    $output = Artisan::output();
    requireCondition($exit === 0, 'Backfill command failed: '.$output);
    $created = str_contains($output, "Document {$documentId}: registered");
    requireCondition($created || str_contains($output, "Document {$documentId}: already_registered"), 'Backfill did not report its single-document outcome.');
    $version = DocumentVersion::query()->where('project_document_id', $documentId)->sole();

    return ['created' => $created, 'version_id' => $version->id, 'public_id' => $version->public_id];
}

function confirmReplay(array $fixture): array
{
    $result = app(ImportConfirmationService::class)->confirm(
        User::findOrFail($fixture['actor']),
        DocumentImport::findOrFail($fixture['import']),
        $fixture['revision'],
        $fixture['idempotency_key'],
    );
    requireCondition(! $result->created && $result->project->id === $fixture['project'], 'Exact Confirm replay created or selected a different project.');

    return ['created' => $result->created, 'project_id' => $result->project->id];
}

if ($mode === 'worker') {
    $fixture = readJson($argv[3]);
    $number = (int) $argv[4];
    requireCondition(in_array($number, [0, 1], true), 'Unknown worker number.');
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $prefix = $fixture['prefix'].'-'.$number;
    writeJson($prefix.'-ready.json', ['connection_id' => $connectionId]);
    $result = $number === 0 ? confirmReplay($fixture) : runBackfill($fixture['document']);
    requireCondition(DB::transactionLevel() === 0, 'The worker left a transaction open.');
    writeJson($prefix.'-result.json', $result + ['connection_id' => $connectionId]);
    exit(0);
}

requireCondition($mode === 'run', 'Expected run or worker.');
// Every process passes the exact server/datadir guard before any database writes.
requireCondition(Artisan::call('migrate:fresh', ['--force' => true]) === 0, 'Guarded disposable schema migration failed.');
$fixtureBuilder = new class
{
    use BuildsPhaseFiveImports;

    public function seed(string $class): void
    {
        app($class)->run();
    }

    public function initialize(): void
    {
        $this->setUpPhaseFiveImports();
    }

    public function create(bool $registered): array
    {
        $actor = $this->phaseFiveUser();
        [$import, $run, $revision] = $this->createReviewableImport($actor);
        $key = 'phase6a-confirm-backfill-'.$import->public_id;

        if ($registered) {
            $result = app(ImportConfirmationService::class)->confirm($actor, $import, $revision->id, $key);
            requireCondition($result->created, 'The registered fixture must be created by real Confirm.');
            $project = $result->project;
        } else {
            // Model a canonical Phase 5 import that predates the version registry.
            // Insert its historical rows normally; never delete a version or alter guards.
            $project = DB::transaction(function () use ($actor, $import, $run, $revision, $key) {
                $attributes = app(ProjectImportPayloadValidator::class)->validateForConfirmation($revision->payload, $actor);
                $indicators = Arr::pull($attributes, 'indicators', []);
                $project = app(ProjectService::class)->createInTransaction($actor, $attributes);
                $kpiIds = [];
                foreach ($indicators as $indicator) {
                    $kpiIds[] = $project->kpis()->create($indicator)->id;
                }
                $document = ProjectDocument::query()->create([
                    'project_id' => $project->id,
                    'source_import_id' => $import->id,
                    'original_name' => $import->original_name,
                    'path' => $import->storage_path,
                    'storage_disk' => $import->storage_disk,
                    'mime_type' => $import->mime_type,
                    'size' => $import->size_bytes,
                    'uploaded_by' => $import->uploaded_by,
                    'checksum' => $import->sha256,
                    'version' => 1,
                    'processing_status' => 'completed',
                    'processed_at' => $run->finished_at,
                ]);
                DocumentContent::query()->create([
                    'document_id' => $document->id,
                    'extracted_text' => $run->extracted_text,
                    'language' => $import->language,
                    'processed_at' => $run->finished_at,
                ]);
                $import->update([
                    'status' => DocumentImportStatus::Confirmed,
                    'processing_stage' => null,
                    'confirmed_preview_revision' => $revision->revision_no,
                    'confirmation_idempotency_key_hash' => hash('sha256', $key),
                    'confirmed_project_id' => $project->id,
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => now(),
                ]);
                AuditLog::record('project.imported_kpis_created', $project, [], [
                    'source_import_id' => $import->id, 'kpi_ids' => $kpiIds,
                ]);
                AuditLog::record('project_document.import_associated', $document, [], [
                    'project_id' => $project->id, 'source_import_id' => $import->id, 'sha256' => $import->sha256,
                ]);
                AuditLog::record('document_import.confirmed', $import, [], [
                    'project_id' => $project->id,
                    'project_document_id' => $document->id,
                    'preview_revision_id' => $revision->id,
                    'preview_revision_no' => $revision->revision_no,
                    'idempotency_key_sha256' => hash('sha256', $key),
                ]);

                return $project;
            });
        }

        $document = ProjectDocument::query()->where('source_import_id', $import->id)->sole();
        requireCondition(DocumentVersion::query()->where('project_document_id', $document->id)->count() === ($registered ? 1 : 0), 'Fixture has the wrong initial version count.');

        return ['actor' => $actor->id, 'import' => $import->id, 'revision' => $revision->id,
            'project' => $project->id, 'document' => $document->id, 'idempotency_key' => $key];
    }
};
$fixtureBuilder->initialize();

/** Snapshot all rows so equal counts cannot conceal a canonical mutation. */
function databaseState(): array
{
    $state = [];
    foreach (DB::select('SHOW TABLES') as $row) {
        $table = array_values((array) $row)[0];
        $rows = DB::table($table)->get()->map(static fn ($record): array => (array) $record)->all();
        usort($rows, static fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
        $state[$table] = $rows;
    }
    ksort($state);

    return $state;
}

function sourceFiles(): array
{
    $files = [];
    // Local includes the private imports subtree in this guarded runtime.
    foreach (Storage::disk('local')->allFiles() as $path) {
        $absolute = Storage::disk('local')->path($path);
        clearstatcache(true, $absolute);
        $files[$path] = ['size' => filesize($absolute), 'sha256' => hash_file('sha256', $absolute), 'mtime' => filemtime($absolute)];
    }
    ksort($files);

    return $files;
}

function startWorker(string $runtime, string $fixturePath, int $number): Process
{
    $command = [PHP_BINARY];
    if (($ini = php_ini_loaded_file()) !== false) {
        array_push($command, '-c', $ini);
    }
    array_push($command, __FILE__, 'worker', $runtime, $fixturePath, (string) $number);
    $worker = new Process($command, dirname(__DIR__, 2).'/laravel-app', null, null, 40);
    $worker->start();

    return $worker;
}

function awaitImportLockOverlap(array $workers, string $prefix, int $blocker): array
{
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        foreach ($workers as $worker) {
            $worker->checkTimeout();
            requireCondition($worker->isRunning(), 'Concurrent worker exited early: '.$worker->getErrorOutput().$worker->getOutput());
        }
        if (is_file($prefix.'-0-ready.json') && is_file($prefix.'-1-ready.json')) {
            $connections = [readJson($prefix.'-0-ready.json')['connection_id'], readJson($prefix.'-1-ready.json')['connection_id']];
            requireCondition(count(array_unique([$blocker, ...$connections])) === 3, 'The coordinator and workers must use separate connections.');
            $waiting = DB::select(
                'SELECT DISTINCT requester.PROCESSLIST_ID AS connection_id
                 FROM performance_schema.data_lock_waits w
                 JOIN performance_schema.data_locks l ON l.ENGINE = w.ENGINE AND l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
                 JOIN performance_schema.threads requester ON requester.THREAD_ID = w.REQUESTING_THREAD_ID
                 JOIN performance_schema.threads blocker ON blocker.THREAD_ID = w.BLOCKING_THREAD_ID
                 WHERE l.OBJECT_SCHEMA = ? AND l.OBJECT_NAME = ? AND l.INDEX_NAME = ?
                   AND blocker.PROCESSLIST_ID = ? AND requester.PROCESSLIST_ID IN (?, ?)',
                ['school_dss_foundation_test', 'document_imports', 'PRIMARY', $blocker, ...$connections],
            );
            $observed = array_map(static fn ($row): int => (int) $row->connection_id, $waiting);
            sort($connections);
            sort($observed);
            if ($observed === $connections) {
                return $observed;
            }
        }
        usleep(50000);
    }
    throw new RuntimeException('Actual Confirm replay and backfill did not overlap on the coordinator-held import row lock.');
}

$reports = [];
$runId = bin2hex(random_bytes(8));
foreach ([true, false] as $registered) {
    $case = $registered ? 'confirmed-version-replay-backfill' : 'pre-phase6-confirmed-replay-backfill';
    $prefix = $runtime.'/'.$runId.'-'.$case;
    $fixture = $fixtureBuilder->create($registered) + ['prefix' => $prefix];
    $fixturePath = $prefix.'-fixture.json';
    writeJson($fixturePath, $fixture);
    $before = databaseState();
    $beforeFiles = sourceFiles();
    $beforeVersion = DocumentVersion::query()->where('project_document_id', $fixture['document'])->first()?->getRawOriginal();
    $workers = [];

    try {
        DB::beginTransaction();
        DocumentImport::query()->whereKey($fixture['import'])->lockForUpdate()->firstOrFail();
        $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $workers[0] = startWorker($runtime, $fixturePath, 0);
        $workers[1] = startWorker($runtime, $fixturePath, 1);
        $connections = awaitImportLockOverlap($workers, $prefix, $blocker);
        DB::commit();

        foreach ($workers as $worker) {
            requireCondition($worker->wait() === 0, 'Concurrent worker failed: '.$worker->getErrorOutput().$worker->getOutput());
        }
        $results = [readJson($prefix.'-0-result.json'), readJson($prefix.'-1-result.json')];
        foreach ($results as $number => $result) {
            requireCondition($result['connection_id'] === readJson($prefix.'-'.$number.'-ready.json')['connection_id'], 'A worker result does not match its observed connection.');
        }
        requireCondition($results[0]['created'] === false && $results[0]['project_id'] === $fixture['project'], 'Confirm replay changed canonical project identity.');
        requireCondition($results[1]['created'] === ! $registered, 'Backfill created an unexpected number of baselines.');
        $version = DocumentVersion::query()->where('project_document_id', $fixture['document'])->sole();
        $identity = $version->getRawOriginal();
        $source = DocumentImport::findOrFail($fixture['import']);
        requireCondition($version->revision_no === 1 && $results[1]['version_id'] === $version->id && $results[1]['public_id'] === $version->public_id, 'Backfill returned an inconsistent revision 1 identity.');
        requireCondition($version->created_via === ($registered ? DocumentVersionCreatedVia::PhaseFiveConfirm : DocumentVersionCreatedVia::Backfill), 'Version provenance changed.');
        requireCondition($version->created_by === ($registered ? $fixture['actor'] : null), 'Version creator changed.');
        requireCondition($version->integrity_basis === DocumentVersionIntegrityBasis::RecordedSha256
            && $version->storage_disk === $source->storage_disk && $version->storage_path === $source->storage_path
            && $version->original_name === $source->original_name && $version->mime_type === $source->mime_type
            && $version->size_bytes === $source->size_bytes && $version->sha256 === $source->sha256, 'Version does not identify the confirmed original.');
        if ($registered) {
            requireCondition($beforeVersion === $identity, 'Concurrent backfill changed the existing version identity or timestamps.');
        }

        $after = databaseState();
        $expected = $before;
        if (! $registered) {
            $newRow = (array) DB::table('document_versions')->where('id', $version->id)->first();
            $expected['document_versions'][] = $newRow;
            usort($expected['document_versions'], static fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
        }
        requireCondition($expected === $after, 'Concurrent Confirm/backfill changed canonical rows, existing versions, or unrelated database state.');
        requireCondition($beforeFiles === sourceFiles(), 'Concurrent Confirm/backfill copied, removed, or changed an original.');

        // Further real Confirm and command replays preserve every winning field.
        confirmReplay($fixture);
        requireCondition(! runBackfill($fixture['document'])['created'], 'Subsequent backfill created another baseline.');
        requireCondition($identity === $version->refresh()->getRawOriginal(), 'Subsequent replay changed version identity or timestamps.');
        requireCondition($after === databaseState() && $beforeFiles === sourceFiles(), 'Subsequent replay changed canonical rows or source files.');
        requireCondition(DB::transactionLevel() === 0, 'The coordinator left a transaction open.');
        $reports[$case] = ['passed' => true, 'overlapping_mysql_lock_waiters' => count($connections),
            'worker_connection_ids' => $connections, 'blocker_connection_id' => $blocker,
            'confirm_created' => false, 'backfill_created' => ! $registered,
            'project_id' => $fixture['project'], 'version_id' => $version->id, 'public_id' => $version->public_id,
            'canonical_rows_unchanged' => true, 'source_files_unchanged' => true, 'replays_unchanged' => true,
            'canonical_counts' => array_map('count', $after)];
        echo json_encode(['case' => $case] + $reports[$case], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
}

$report = ['passed' => true, 'mysql_version' => '8.4.11',
    'target' => '127.0.0.1:33084 / school_dss_foundation_test', 'cases' => $reports];
writeJson($runtime.'/'.$runId.'-report.json', $report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
