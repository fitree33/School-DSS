<?php

declare(strict_types=1);

use App\Exceptions\ApiProblemException;
use App\Models\DocumentImport;
use App\Models\ImportPreviewRevision;
use App\Models\User;
use App\Services\Imports\ImportConfirmationService;
use App\Services\Imports\ImportPreviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;

require __DIR__.'/bootstrap.php';
$runtime = dirname(__DIR__, 2).'/.foundation-runtime/phase5-preview-concurrency';
if (! is_dir($runtime)) {
    mkdir($runtime, 0700, true);
}
$app = phaseFiveBootstrap($runtime, true);
$mode = $argv[1] ?? 'run';

if ($mode === 'worker') {
    $fixture = json_decode(file_get_contents($runtime.'/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
    $number = (int) $argv[2];
    $request = $fixture['requests'][$number - 1];
    // Both workers intentionally load the same pre-lock model instance.
    $actor = User::findOrFail($fixture['actor']);
    $import = DocumentImport::findOrFail($fixture['import']);
    file_put_contents($runtime.'/ready-'.$number, 'ready');
    try {
        if ($request['action'] === 'confirm') {
            $result = $app->make(ImportConfirmationService::class)->confirm(
                $actor, $import, $fixture['revision'], $request['key'],
            );
            $output = ['status' => $result->created ? 201 : 200, 'project_id' => $result->project->id];
        } else {
            $result = $app->make(ImportPreviewService::class)->appendUserRevision(
                $actor, $import, $fixture['revision'], $fixture['payload'], $request['key'],
            );
            $output = ['status' => $result->created ? 201 : 200, 'revision_id' => $result->revision->id];
        }
    } catch (ApiProblemException $exception) {
        $output = ['status' => $exception->status, 'code' => $exception->errorCode];
    } catch (AuthorizationException) {
        $output = ['status' => 403, 'code' => 'forbidden'];
    }
    file_put_contents($runtime.'/result-'.$number.'.json', json_encode($output, JSON_THROW_ON_ERROR));
    exit(0);
}
if ($mode !== 'run') {
    throw new RuntimeException('Expected run or worker.');
}

$reports = [];
foreach (['distinct_edits', 'identical_replay', 'preview_vs_confirm'] as $scenario) {
    // Bootstrap has already guarded the opt-in, host, port and exact disposable schema.
    Artisan::call('migrate:fresh', ['--force' => true]);
    $builder = new class
    {
        use BuildsPhaseFiveImports;

        public function seed(string $class): void
        {
            app($class)->run();
        }

        public function fixture(): array
        {
            $this->setUpPhaseFiveImports();
            $actor = $this->phaseFiveUser();
            // Ensure row IDs cannot accidentally be treated as revision numbers.
            [$guardImport, , $guardRevision] = $this->createReviewableImport($actor);
            [$import, , $revision] = $this->createReviewableImport($actor);

            return ['actor' => $actor->id, 'import' => $import->id, 'revision' => $revision->id,
                'guard_import' => $guardImport->id, 'guard_revision' => $guardRevision->id,
                'payload' => $this->validImportPreviewPayload(['name' => 'Concurrent preview edit'])];
        }
    };
    $fixture = $builder->fixture();
    $fixture['requests'] = [
        ['action' => 'preview', 'key' => 'concurrent-edit-1'],
        ['action' => $scenario === 'preview_vs_confirm' ? 'confirm' : 'preview',
            'key' => $scenario === 'identical_replay' ? 'concurrent-edit-1' : 'concurrent-edit-2'],
    ];
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
            $worker = new Process([PHP_BINARY, __FILE__, 'worker', (string) $number], base_path(), null, null, 30);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 15;
        $waiters = 0;
        while (microtime(true) < $deadline) {
            foreach ($workers as $worker) {
                if (! $worker->isRunning()) {
                    throw new RuntimeException('Worker exited before overlap: '.$worker->getErrorOutput().$worker->getOutput());
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
            throw new RuntimeException('Both PHP processes must overlap on the held MySQL import row lock.');
        }
        DB::commit();
        foreach ($workers as $worker) {
            if ($worker->wait() !== 0) {
                throw new RuntimeException('Concurrent worker failed: '.$worker->getErrorOutput().$worker->getOutput());
            }
        }
        $results = array_map(fn (int $number): array => json_decode(file_get_contents($runtime.'/result-'.$number.'.json'), true, 512, JSON_THROW_ON_ERROR), [1, 2]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $confirmed = $scenario === 'preview_vs_confirm' && $results[1]['status'] === 201;
        $expectedStatuses = $scenario === 'identical_replay' ? [200, 201] : ($confirmed ? [201, 403] : [201, 409]);
        if ($statuses !== $expectedStatuses) {
            throw new RuntimeException('Unexpected race results: '.json_encode($results, JSON_THROW_ON_ERROR));
        }
        foreach ($results as $result) {
            if ($result['status'] === 409 && $result['code'] !== 'stale_preview_revision') {
                throw new RuntimeException('Losing edit/confirm must report stale_preview_revision.');
            }
        }
        if ($scenario === 'identical_replay' && $results[0]['revision_id'] !== $results[1]['revision_id']) {
            throw new RuntimeException('Identical replay must return the same revision row ID.');
        }
        $import = DocumentImport::findOrFail($fixture['import']);
        $revisions = ImportPreviewRevision::where('document_import_id', $import->id)->orderBy('revision_no')->get();
        $expectedRevisions = $confirmed ? 1 : 2;
        if ($revisions->count() !== $expectedRevisions || $import->current_preview_revision !== $expectedRevisions) {
            throw new RuntimeException('Race appended an unexpected revision or confused row ID with revision number.');
        }
        if ($revisions->pluck('revision_no')->all() !== range(1, $expectedRevisions)
            || $revisions->pluck('parent_revision_no')->all() !== ($confirmed ? [null] : [null, 1])) {
            throw new RuntimeException('Concurrent saves must preserve an unbroken revision and parent sequence.');
        }
        $counts = [];
        foreach (['projects' => 1, 'project_kpis' => 2, 'project_documents' => 1,
            'document_contents' => 1, 'project_access' => 1, 'project_status_histories' => 1,
            'project_execution_status_histories' => 1] as $table => $canonicalCount) {
            $counts[$table] = DB::table($table)->count();
            if ($counts[$table] !== ($confirmed ? $canonicalCount : 0)) {
                throw new RuntimeException('Unexpected canonical writes in '.$table);
            }
        }
        $expectedAuditCount = $confirmed ? 4 : 1;
        if (DB::table('audit_logs')->count() !== $expectedAuditCount) {
            throw new RuntimeException('Race duplicated an audit event.');
        }
        $reports[$scenario] = ['overlapping_mysql_lock_waiters' => $waiters, 'results' => $results,
            'revision_count' => $revisions->count(), 'revision_sequence' => $revisions->pluck('revision_no')->all(),
            'canonical_counts' => $counts];
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
}

// The reserved import gives deterministic stale/current and post-confirm checks,
// independent of which worker won the preview-versus-confirm race.
$actor = User::findOrFail($fixture['actor']);
$import = DocumentImport::findOrFail($fixture['guard_import']);
$preview = $app->make(ImportPreviewService::class);
$second = $preview->appendUserRevision($actor, $import, $fixture['guard_revision'], $fixture['payload'], 'sequence-2')->revision;
$third = $preview->appendUserRevision($actor, $import, $second->id, $fixture['payload'], 'sequence-3')->revision;
$rejectStale = function (int $base, string $key) use ($preview, $actor, $import, $fixture): array {
    $before = ImportPreviewRevision::where('document_import_id', $import->id)->count();
    try {
        $preview->appendUserRevision($actor, $import, $base, $fixture['payload'], $key);
        throw new RuntimeException('A stale or missing revision was accepted.');
    } catch (ApiProblemException $exception) {
        if ($exception->status !== 409 || $exception->errorCode !== 'stale_preview_revision'
            || ImportPreviewRevision::where('document_import_id', $import->id)->count() !== $before) {
            throw new RuntimeException('Stale revision must return 409 without appending a revision.');
        }

        return ['status' => $exception->status, 'code' => $exception->errorCode];
    }
};
$guards = ['stale_base' => $rejectStale($fixture['guard_revision'], 'stale-base'),
    'missing_base' => $rejectStale((int) ImportPreviewRevision::max('id') + 100, 'missing-base')];
DB::beginTransaction();
try {
    DocumentImport::whereKey($import->id)->update(['current_preview_revision' => 999]);
    $guards['missing_current'] = $rejectStale($third->id, 'missing-current');
} finally {
    DB::rollBack();
}
$app->make(ImportConfirmationService::class)->confirm($actor, $import, $third->id, 'sequence-confirm');
foreach (['sequence-3', 'after-confirm'] as $key) {
    try {
        $preview->appendUserRevision($actor, $import, $third->id, $fixture['payload'], $key);
        throw new RuntimeException('A confirmed import accepted a preview revision.');
    } catch (AuthorizationException) {
        $guards['confirmed_'.$key] = ['status' => 403];
    }
}
$sequence = ImportPreviewRevision::where('document_import_id', $import->id)->orderBy('revision_no')->get();
$duplicates = DB::table('import_preview_revisions')->select('document_import_id', 'revision_no')
    ->groupBy('document_import_id', 'revision_no')->havingRaw('COUNT(*) > 1')->get()->count();
if ($sequence->pluck('revision_no')->all() !== [1, 2, 3]
    || $sequence->pluck('parent_revision_no')->all() !== [null, 1, 2]
    || $import->fresh()->current_preview_revision !== 3 || $duplicates !== 0) {
    throw new RuntimeException('Revision sequence, parent sequence or confirmed immutability failed.');
}
$reports['revision_lifecycle'] = ['revision_sequence' => [1, 2, 3], 'parent_sequence' => [null, 1, 2],
    'duplicate_revision_numbers' => $duplicates, 'guards' => $guards, 'confirmed_revision_count' => $sequence->count()];
echo json_encode(['passed' => true, 'scenarios' => $reports], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
