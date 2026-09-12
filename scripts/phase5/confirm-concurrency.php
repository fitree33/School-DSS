<?php

declare(strict_types=1);

use App\Models\DocumentImport;
use App\Models\Project;
use App\Models\User;
use App\Services\Imports\ImportConfirmationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;

require __DIR__.'/bootstrap.php';
$runtime = $argv[2] ?? dirname(__DIR__, 2).'/.foundation-runtime/phase5-concurrency';
$app = phaseFiveBootstrap($runtime, true);
$mode = $argv[1] ?? 'run';

if ($mode === 'worker') {
    $fixture = json_decode(file_get_contents($runtime.'/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
    $number = $argv[3];
    $actor = User::findOrFail($fixture['actor']);
    $import = DocumentImport::findOrFail($fixture['import']);
    file_put_contents($runtime.'/ready-'.$number, 'ready');
    $result = $app->make(ImportConfirmationService::class)->confirm($actor, $import, $fixture['revision'], 'phase5-concurrent-confirm');
    file_put_contents($runtime.'/result-'.$number.'.json', json_encode(['project_id' => $result->project->id, 'created' => $result->created], JSON_THROW_ON_ERROR));
    exit(0);
}

if ($mode !== 'run') {
    throw new RuntimeException('Expected run or worker.');
}
// This script intentionally resets ONLY the exact guarded disposable test schema.
Artisan::call('migrate:fresh', ['--force' => true]);
$fixtureBuilder = new class
{
    use BuildsPhaseFiveImports;

    public function seed(string $class): void
    {
        app($class)->run();
    }

    public function create(): array
    {
        $this->setUpPhaseFiveImports();
        $actor = $this->phaseFiveUser();
        [$import, $run, $revision] = $this->createReviewableImport($actor);

        return ['actor' => $actor->id, 'import' => $import->id, 'revision' => $revision->id];
    }
};
$fixture = $fixtureBuilder->create();
file_put_contents($runtime.'/fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR));
foreach ([1, 2] as $number) {
    foreach (['ready-'.$number, 'result-'.$number.'.json'] as $file) {
        if (is_file($runtime.'/'.$file)) {
            unlink($runtime.'/'.$file);
        }
    }
}
$workers = [];
DB::beginTransaction();
try {
    DocumentImport::query()->lockForUpdate()->findOrFail($fixture['import']);
    foreach ([1, 2] as $number) {
        $worker = new Process([PHP_BINARY, __FILE__, 'worker', $runtime, (string) $number], dirname(__DIR__, 2).'/laravel-app', null, null, 30);
        $worker->start();
        $workers[] = $worker;
    }
    $deadline = microtime(true) + 15;
    $waiters = 0;
    while (microtime(true) < $deadline) {
        foreach ($workers as $worker) {
            if (! $worker->isRunning()) {
                throw new RuntimeException('Concurrent worker exited early: '.$worker->getErrorOutput().$worker->getOutput());
            }
        }
        if (is_file($runtime.'/ready-1') && is_file($runtime.'/ready-2')) {
            $waiters = (int) DB::selectOne('SELECT COUNT(DISTINCT REQUESTING_ENGINE_TRANSACTION_ID) AS waiting FROM performance_schema.data_lock_waits')->waiting;
            if ($waiters >= 2) {
                break;
            }
        }
        usleep(50000);
    }
    if ($waiters < 2) {
        throw new RuntimeException('Both confirm processes did not overlap on the held MySQL row lock.');
    }
    DB::commit();
    foreach ($workers as $worker) {
        if ($worker->wait() !== 0) {
            throw new RuntimeException('Concurrent confirm failed: '.$worker->getErrorOutput().$worker->getOutput());
        }
    }
    $results = array_map(fn (int $number): array => json_decode(file_get_contents($runtime.'/result-'.$number.'.json'), true, 512, JSON_THROW_ON_ERROR), [1, 2]);
    $counts = [];
    $expectedCounts = ['projects' => 1, 'project_kpis' => 2, 'project_documents' => 1,
        'document_contents' => 1, 'document_versions' => 1, 'project_access' => 1, 'project_status_histories' => 1,
        'project_execution_status_histories' => 1, 'audit_logs' => 4, 'import_preview_revisions' => 1];
    foreach (array_keys($expectedCounts) as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    if ($results[0]['project_id'] !== $results[1]['project_id'] || count(array_filter($results, fn (array $result): bool => $result['created'])) !== 1 || $counts !== $expectedCounts) {
        throw new RuntimeException('Concurrent confirmation created inconsistent canonical state.');
    }
    foreach (['project.created', 'project.imported_kpis_created', 'project_document.import_associated', 'document_import.confirmed'] as $action) {
        if (DB::table('audit_logs')->where('action', $action)->count() !== 1) {
            throw new RuntimeException('Concurrent confirmation duplicated an audit action.');
        }
    }
    $import = DocumentImport::findOrFail($fixture['import']);
    if ($import->confirmed_project_id !== Project::firstOrFail()->id || DB::table('audit_logs')->where('action', 'document_import.confirmed')->count() !== 1) {
        throw new RuntimeException('Concurrent confirmation provenance or audit count is incorrect.');
    }
    echo json_encode(['passed' => true, 'overlapping_mysql_lock_waiters' => $waiters, 'results' => $results, 'canonical_counts' => $counts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($workers as $worker) {
        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
}
