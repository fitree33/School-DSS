<?php

declare(strict_types=1);

use App\Enums\DocumentImportStatus;
use App\Enums\DocumentVersionCreatedVia;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\DocumentImport;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectDocument;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Services\Documents\DocumentVersionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require __DIR__.'/bootstrap.php';
$runtime = $argv[2] ?? dirname(__DIR__, 2).'/.foundation-runtime/phase5-phase6a-concurrency';
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
    // Atomic rename prevents the coordinator from observing half a readiness record.
    file_put_contents($path.'.writing', json_encode($value, JSON_THROW_ON_ERROR));
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

    return ['created' => $created, 'command_output' => $output];
}

if ($mode === 'worker') {
    $fixture = readJson($argv[3]);
    $number = (int) $argv[4];
    $operation = $fixture['operations'][$number];
    $prefix = $fixture['prefix'].'-'.$number;
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    writeJson($prefix.'-ready.json', ['connection_id' => $connectionId]);
    $document = ProjectDocument::findOrFail($fixture['document']);
    $actor = User::findOrFail($fixture['actors'][$number]);
    $via = DocumentVersionCreatedVia::from($fixture['registration_via']);
    $service = $app->make(DocumentVersionService::class);

    if ($operation === 'backfill') {
        $result = runBackfill($document->id);
        $version = DocumentVersion::query()->where('project_document_id', $document->id)->sole();
    } elseif ($operation === 'registration') {
        // Imported confirmation registration requires its outer canonical transaction.
        $version = DB::transaction(fn () => $service->registerInitialVersion($document, $via, $actor));
        $result = ['created' => $version->wasRecentlyCreated];
    } elseif ($operation === 'rollback-registration') {
        try {
            DB::transaction(function () use ($service, $document, $via, $actor, $prefix, $fixture): void {
                $version = $service->registerInitialVersion($document, $via, $actor);
                requireCondition($version->wasRecentlyCreated, 'Rollback fixture must insert a fresh uncommitted version.');
                writeJson($prefix.'-inserted.json', ['public_id' => $version->public_id]);
                $deadline = microtime(true) + 20;
                while (! is_file($fixture['prefix'].'-release-rollback') && microtime(true) < $deadline) {
                    usleep(50000);
                }
                requireCondition(is_file($fixture['prefix'].'-release-rollback'), 'Coordinator did not release the rollback worker.');
                throw new RuntimeException('phase6a-deliberate-outer-rollback');
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'phase6a-deliberate-outer-rollback') {
                throw $exception;
            }
        }
        requireCondition(DB::transactionLevel() === 0, 'The failed registration left a transaction open.');
        writeJson($prefix.'-result.json', ['created' => false, 'rolled_back' => true, 'connection_id' => $connectionId]);
        exit(0);
    } else {
        throw new RuntimeException('Unknown worker operation.');
    }

    requireCondition(DB::transactionLevel() === 0, 'The successful worker left a transaction open.');
    writeJson($prefix.'-result.json', $result + ['public_id' => $version->public_id, 'version_id' => $version->id, 'connection_id' => $connectionId]);
    exit(0);
}

requireCondition($mode === 'run', 'Expected run or worker.');
// The opt-in, exact connection and actual server/datadir guards have all passed.
requireCondition(Artisan::call('migrate:fresh', ['--force' => true]) === 0, 'Guarded disposable schema migration failed.');
$actors = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
$project = Project::query()->create([
    'name' => 'Phase 6A MySQL concurrency',
    'objective' => 'One immutable verified baseline under overlapping writers.',
    'user_id' => $actors[0]->id,
    'department_id' => Department::query()->create(['name' => 'Concurrency'])->id,
    'project_category_id' => ProjectCategory::query()->create(['name' => 'Concurrency'])->id,
    'academic_year_id' => AcademicYear::query()->create(['year' => 2570])->id,
    'project_status_id' => ProjectStatus::query()->create(['name' => 'Concurrency draft'])->id,
]);

function tableCounts(): array
{
    $counts = [];
    foreach (DB::select('SHOW TABLES') as $row) {
        $table = array_values((array) $row)[0];
        $counts[$table] = DB::table($table)->count();
    }
    ksort($counts);

    return $counts;
}

function sourceFiles(): array
{
    $files = [];
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

function awaitEvidence(array $workers, callable $condition, string $failure): mixed
{
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        foreach ($workers as $worker) {
            $worker->checkTimeout();
            requireCondition($worker->isRunning(), 'Concurrent worker exited early: '.$worker->getErrorOutput().$worker->getOutput());
        }
        if ($result = $condition()) {
            return $result;
        }
        usleep(50000);
    }
    throw new RuntimeException($failure);
}

function waiters(string $table, array $connections, int $blocker): int
{
    $placeholders = implode(',', array_fill(0, count($connections), '?'));
    $row = DB::selectOne(
        'SELECT COUNT(DISTINCT w.REQUESTING_ENGINE_TRANSACTION_ID) AS waiting
         FROM performance_schema.data_lock_waits w
         JOIN performance_schema.data_locks l ON l.ENGINE = w.ENGINE AND l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
         JOIN performance_schema.threads requester ON requester.THREAD_ID = w.REQUESTING_THREAD_ID
         JOIN performance_schema.threads blocker ON blocker.THREAD_ID = w.BLOCKING_THREAD_ID
         WHERE l.OBJECT_SCHEMA = ? AND l.OBJECT_NAME = ? AND blocker.PROCESSLIST_ID = ?
           AND requester.PROCESSLIST_ID IN ('.$placeholders.')',
        ['school_dss_foundation_test', $table, $blocker, ...$connections],
    );

    return (int) $row->waiting;
}

$reports = [];
$runId = bin2hex(random_bytes(8));
$cases = [
    'registration-registration' => ['registration', 'registration'],
    'backfill-registration' => ['backfill', 'registration'],
    'backfill-backfill' => ['backfill', 'backfill'],
    'rollback-registration-backfill' => ['rollback-registration', 'backfill'],
];

foreach ([false, true] as $imported) {
    foreach ($cases as $name => $operations) {
        $case = ($imported ? 'imported-' : 'legacy-').$name;
        $prefix = $runtime.'/'.$runId.'-'.$case;
        // confirmed_project_id is unique: each imported original owns a project.
        $caseProject = $project;
        if ($imported) {
            $caseProject = $project->replicate();
            $caseProject->save();
        }
        $bytes = "%PDF-1.4\nPhase 6A {$case} original\n%%EOF\n";
        $disk = $imported ? 'project-imports' : 'local';
        $path = ($imported ? 'originals/' : 'documents/').Str::uuid().'.pdf';
        requireCondition(Storage::disk($disk)->put($path, $bytes), 'Could not create a disposable original fixture.');
        $source = $imported ? DocumentImport::query()->create([
            'uploaded_by' => $actors[0]->id,
            'status' => DocumentImportStatus::Confirmed,
            'original_name' => 'concurrency.pdf',
            'storage_disk' => $disk,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'confirmed_project_id' => $caseProject->id,
            'confirmed_by' => $actors[0]->id,
            'confirmed_at' => now(),
            'confirmed_preview_revision' => 1,
        ])->refresh() : null;
        $document = ProjectDocument::query()->create([
            'project_id' => $caseProject->id,
            'source_import_id' => $source?->id,
            'original_name' => 'concurrency.pdf',
            'path' => $path,
            'storage_disk' => $disk,
            'mime_type' => 'application/pdf',
            'size' => strlen($bytes),
            'uploaded_by' => $actors[0]->id,
            'checksum' => hash('sha256', $bytes),
            'version' => $imported ? 1 : 7,
        ])->refresh();
        $fixture = [
            'prefix' => $prefix,
            'document' => $document->id,
            'actors' => [$actors[0]->id, $actors[1]->id],
            'operations' => $operations,
            'registration_via' => ($imported ? DocumentVersionCreatedVia::PhaseFiveConfirm : DocumentVersionCreatedVia::LegacyUpload)->value,
        ];
        $fixturePath = $prefix.'-fixture.json';
        writeJson($fixturePath, $fixture);
        $beforeCounts = tableCounts();
        $beforeFiles = sourceFiles();
        $beforeDocument = $document->getRawOriginal();
        $beforeImport = $source?->getRawOriginal();
        $gateTable = $imported ? 'document_imports' : 'project_documents';
        $gateId = $source?->id ?? $document->id;
        $workers = [];

        try {
            if ($operations[0] === 'rollback-registration') {
                $workers[0] = startWorker($runtime, $fixturePath, 0);
                awaitEvidence($workers, fn () => is_file($prefix.'-0-inserted.json'), 'The rollback worker did not hold an uncommitted inserted version.');
                $workers[1] = startWorker($runtime, $fixturePath, 1);
                $overlap = awaitEvidence($workers, function () use ($prefix, $gateTable): int {
                    if (! is_file($prefix.'-1-ready.json')) {
                        return 0;
                    }

                    return waiters($gateTable, [readJson($prefix.'-1-ready.json')['connection_id']], readJson($prefix.'-0-ready.json')['connection_id']);
                }, 'Backfill did not block behind the registration transaction before its rollback.');
                file_put_contents($prefix.'-release-rollback', 'release');
            } else {
                DB::beginTransaction();
                DB::table($gateTable)->where('id', $gateId)->lockForUpdate()->firstOrFail();
                $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                $workers[0] = startWorker($runtime, $fixturePath, 0);
                $workers[1] = startWorker($runtime, $fixturePath, 1);
                $overlap = awaitEvidence($workers, function () use ($prefix, $gateTable, $blocker): int {
                    if (! is_file($prefix.'-0-ready.json') || ! is_file($prefix.'-1-ready.json')) {
                        return 0;
                    }
                    $count = waiters($gateTable, [readJson($prefix.'-0-ready.json')['connection_id'], readJson($prefix.'-1-ready.json')['connection_id']], $blocker);

                    return $count >= 2 ? $count : 0;
                }, 'Both writers did not overlap on the held source identity row lock.');
                DB::commit();
            }

            foreach ($workers as $worker) {
                requireCondition($worker->wait() === 0, 'Concurrent worker failed: '.$worker->getErrorOutput().$worker->getOutput());
            }
            $results = [readJson($prefix.'-0-result.json'), readJson($prefix.'-1-result.json')];
            requireCondition(count(array_filter($results, fn (array $result): bool => $result['created'])) === 1, 'Exactly one worker must commit the initial version.');
            $winner = $results[0]['created'] ? 0 : 1;
            $version = DocumentVersion::query()->where('project_document_id', $document->id)->sole();
            $identity = $version->getRawOriginal();
            foreach ($results as $result) {
                if (! ($result['rolled_back'] ?? false)) {
                    requireCondition($result['public_id'] === $version->public_id && $result['version_id'] === $version->id, 'Workers returned different baseline identities.');
                }
            }
            $backfillWon = $operations[$winner] === 'backfill';
            requireCondition($version->revision_no === 1, 'A legacy scalar or race fabricated another revision.');
            requireCondition($version->created_via->value === ($backfillWon ? 'backfill' : $fixture['registration_via']), 'The winner source provenance was overwritten.');
            requireCondition($version->created_by === ($backfillWon ? null : $fixture['actors'][$winner]), 'The winner actor was overwritten.');
            requireCondition($version->storage_disk === $disk && $version->storage_path === $path && $version->sha256 === hash('sha256', $bytes) && $version->size_bytes === strlen($bytes), 'The committed baseline does not identify the original.');
            if ($operations[0] === 'rollback-registration') {
                requireCondition(($results[0]['rolled_back'] ?? false) && $backfillWon, 'Rollback did not leave backfill as the committed winner.');
                $rolledBackId = readJson($prefix.'-0-inserted.json')['public_id'];
                requireCondition(! DocumentVersion::query()->where('public_id', $rolledBackId)->exists(), 'An aborted registration left its inserted version behind.');
            }

            // A third actor and a later CLI replay must preserve every winning field.
            $replay = DB::transaction(fn () => $app->make(DocumentVersionService::class)->registerInitialVersion($document, DocumentVersionCreatedVia::from($fixture['registration_via']), $actors[2]));
            requireCondition(! $replay->wasRecentlyCreated && $replay->public_id === $version->public_id, 'Registration replay created another identity.');
            requireCondition(! runBackfill($document->id)['created'], 'Backfill replay created another identity.');
            requireCondition($identity === $version->refresh()->getRawOriginal(), 'Replay changed winner provenance, timestamps or source identity.');
            $expectedCounts = $beforeCounts;
            $expectedCounts['document_versions']++;
            requireCondition($expectedCounts === tableCounts(), 'Concurrency changed unrelated row counts or left transaction residue.');
            requireCondition($beforeDocument === $document->refresh()->getRawOriginal(), 'Concurrency changed the source document.');
            requireCondition($beforeImport === $source?->refresh()->getRawOriginal(), 'Concurrency changed the source import.');
            requireCondition($beforeFiles === sourceFiles(), 'Concurrency copied, removed or changed an original file.');
            $reports[$case] = ['passed' => true, 'overlapping_mysql_lock_waiters' => $overlap, 'winner' => $operations[$winner], 'version_id' => $version->id, 'public_id' => $version->public_id, 'source_unchanged' => true, 'replays_unchanged' => true, 'no_transaction_residue' => true];
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
}

$report = ['passed' => true, 'mysql_version' => '8.4.11', 'target' => '127.0.0.1:33084 / school_dss_foundation_test', 'cases' => $reports, 'canonical_counts' => tableCounts()];
writeJson($runtime.'/'.$runId.'-report.json', $report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
