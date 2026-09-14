<?php

declare(strict_types=1);

use App\Enums\ProjectSignatureSlotCode;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectSignatureSlot;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\Projects\ProjectSignatureSlotService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/bootstrap.php';
$runtime = $argv[2] ?? dirname(__DIR__, 2).'/.foundation-runtime/phase5-phase6b1-concurrency';
$app = phaseSixBOneBootstrap($runtime);
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

/** Exercise the real route, request, authorization, controller, service and error renderer. */
function runAssignment(int $actorId, int $projectId, int $candidateId, int $revision): array
{
    Auth::guard('web')->setUser(User::findOrFail($actorId));
    Auth::shouldUse('web');
    $request = Request::create(
        '/api/v2/projects/'.$projectId.'/signature-slots/project_proposer/assignment',
        'PUT',
        server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        content: json_encode(['assigned_user_id' => $candidateId, 'assignment_revision' => $revision], JSON_THROW_ON_ERROR),
    );
    $kernel = app(Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $status = $response->getStatusCode();
    requireCondition($status === 200 || ($status === 409 && ($body['code'] ?? null) === 'assignment_revision_conflict'), 'Unexpected assignment response: '.$status.' / '.($body['code'] ?? 'missing_code'));

    return $status === 200
        ? ['status' => $status, 'slot_id' => $body['data']['id'], 'assigned_user_id' => $body['data']['assigned_user_id'], 'assignment_revision' => $body['data']['assignment_revision']]
        : ['status' => $status, 'code' => $body['code']];
}

/** A nonzero command exit, including unknown SQL integrity failures, always fails this harness. */
function runBackfill(int $projectId): array
{
    $exit = Artisan::call('projects:backfill-signature-slots', ['--apply' => true, '--project-id' => [(string) $projectId]]);
    $output = Artisan::output();
    requireCondition($exit === 0, 'Backfill command failed: '.$output);
    $created = str_contains($output, "Project {$projectId}: initialized");
    requireCondition($created || str_contains($output, "Project {$projectId}: already_complete"), 'Backfill did not report a single-project outcome.');
    requireCondition(preg_match('/inserted_rows=([0-9]+), failed=0\s*$/D', $output, $matches) === 1, 'Backfill did not report a successful row count.');

    return ['created' => $created, 'inserted_rows' => (int) $matches[1], 'command_output' => $output];
}

if ($mode === 'worker') {
    $fixture = readJson($argv[3]);
    $number = (int) $argv[4];
    requireCondition(in_array($number, [0, 1], true), 'Unknown worker number.');
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $prefix = $fixture['prefix'].'-'.$number;
    writeJson($prefix.'-ready.json', ['connection_id' => $connectionId]);
    if ($fixture['operation'] === 'assignment') {
        $result = runAssignment($fixture['actors'][$number], $fixture['project'], $fixture['candidates'][$number], $fixture['revision']);
    } elseif ($fixture['operation'] === 'backfill') {
        $result = runBackfill($fixture['project']);
    } else {
        throw new RuntimeException('Unknown worker operation.');
    }
    requireCondition(DB::transactionLevel() === 0, 'The worker left a transaction open.');
    writeJson($prefix.'-result.json', $result + ['connection_id' => $connectionId]);
    exit(0);
}

requireCondition($mode === 'run', 'Expected run or worker.');
// Every coordinator and worker has identified the actual disposable server before any writes.
requireCondition(Artisan::call('migrate:fresh', ['--force' => true]) === 0, 'Guarded disposable schema migration failed.');
$app->make(AuthorizationSeeder::class)->run();
$app->make(ProjectStatusSeeder::class)->run();
$department = Department::query()->create(['name' => 'Phase 6B1 concurrency']);
$category = ProjectCategory::query()->create(['name' => 'Phase 6B1 concurrency']);
$year = AcademicYear::query()->create(['year' => 2570]);
$status = ProjectStatus::query()->where('code', 'draft')->firstOrFail();
$makeUser = static fn (string $role): User => User::factory()->create([
    'role_id' => Role::query()->where('code', $role)->firstOrFail()->id,
    'department_id' => $department->id,
    'is_active' => true,
]);
$actors = [$makeUser('director'), $makeUser('deputy_director')];
$candidates = [$makeUser('teacher'), $makeUser('department_head')];

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

function slotState(int $projectId): array
{
    return ProjectSignatureSlot::query()->where('project_id', $projectId)->orderBy('slot_no')->get()->map->getRawOriginal()->all();
}

function assertUnrelatedRowsUnchanged(array $before, array $after, int $projectId, int $priorAuditId): void
{
    foreach (['project_signature_slots', 'audit_logs'] as $table) {
        unset($before[$table], $after[$table]);
    }
    requireCondition($before === $after, 'The race changed unrelated database rows.');
    requireCondition(DB::table('project_access')->count() === 0, 'Assignment or structural backfill granted ProjectAccess.');
    foreach (AuditLog::query()->where('id', '>', $priorAuditId)->get() as $audit) {
        requireCondition($audit->new_values['project_id'] === $projectId, 'The race audited an unrelated project.');
    }
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

function awaitEvidence(array $workers, callable $condition): int
{
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        foreach ($workers as $worker) {
            $worker->checkTimeout();
            requireCondition($worker->isRunning(), 'Concurrent worker exited before overlap: '.$worker->getErrorOutput().$worker->getOutput());
        }
        if ($result = $condition()) {
            return $result;
        }
        usleep(50000);
    }
    throw new RuntimeException('Both independent writers did not overlap on the coordinator-held MySQL project row lock.');
}

function waiters(array $connections, int $blocker): int
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
        ['school_dss_foundation_test', 'projects', $blocker, ...$connections],
    );

    return (int) $row->waiting;
}

$runId = bin2hex(random_bytes(8));
$reports = [];
$cases = [
    'assignment-same-revision' => ['operation' => 'assignment', 'partial' => false, 'soft_deleted' => false],
    'backfill-empty-active' => ['operation' => 'backfill', 'partial' => false, 'soft_deleted' => false],
    'backfill-partial-active' => ['operation' => 'backfill', 'partial' => true, 'soft_deleted' => false],
    'backfill-empty-soft-deleted' => ['operation' => 'backfill', 'partial' => false, 'soft_deleted' => true],
    'backfill-partial-soft-deleted' => ['operation' => 'backfill', 'partial' => true, 'soft_deleted' => true],
];

foreach ($cases as $name => $case) {
    $prefix = $runtime.'/'.$runId.'-'.$name;
    $project = Project::query()->create([
        'name' => 'Phase 6B1 '.$name,
        'objective' => 'Verify committed slot identities, revisions and audit atomicity under overlapping writers.',
        'user_id' => $actors[0]->id,
        'department_id' => $department->id,
        'project_category_id' => $category->id,
        'academic_year_id' => $year->id,
        'project_status_id' => $status->id,
    ]);
    if ($case['operation'] === 'assignment') {
        DB::transaction(fn () => $app->make(ProjectSignatureSlotService::class)->initializeInTransaction($project, $actors[0]));
    } elseif ($case['partial']) {
        // Historical assigned row: create directly without weakening checks or deleting canonical rows.
        ProjectSignatureSlot::query()->create([
            'project_id' => $project->id,
            'slot_code' => ProjectSignatureSlotCode::ProjectProposer,
            'slot_no' => 1,
            'assigned_user_id' => $candidates[0]->id,
            'assignment_revision' => 7,
            'assigned_by' => $actors[0]->id,
            'assigned_at' => now('UTC')->subDay(),
        ]);
    }
    if ($case['soft_deleted']) {
        $project->delete();
    }
    $fixture = [
        'prefix' => $prefix,
        'project' => $project->id,
        'operation' => $case['operation'],
        'actors' => [$actors[0]->id, $actors[1]->id],
        'candidates' => [$candidates[0]->id, $candidates[1]->id],
        'revision' => 0,
    ];
    $fixturePath = $prefix.'-fixture.json';
    writeJson($fixturePath, $fixture);
    $before = databaseState();
    $beforeSlots = slotState($project->id);
    $beforeAuditId = (int) (AuditLog::query()->max('id') ?? 0);
    $workers = [];

    try {
        DB::beginTransaction();
        Project::withTrashed()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        $blocker = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $workers[0] = startWorker($runtime, $fixturePath, 0);
        $workers[1] = startWorker($runtime, $fixturePath, 1);
        $overlap = awaitEvidence($workers, function () use ($prefix, $blocker): int {
            if (! is_file($prefix.'-0-ready.json') || ! is_file($prefix.'-1-ready.json')) {
                return 0;
            }
            $connections = [readJson($prefix.'-0-ready.json')['connection_id'], readJson($prefix.'-1-ready.json')['connection_id']];
            requireCondition(count(array_unique([$blocker, ...$connections])) === 3, 'Writers must use independent MySQL connections.');
            $count = waiters($connections, $blocker);

            return $count >= 2 ? $count : 0;
        });
        DB::commit();
        foreach ($workers as $worker) {
            requireCondition($worker->wait() === 0, 'Concurrent worker failed: '.$worker->getErrorOutput().$worker->getOutput());
        }
        $results = [readJson($prefix.'-0-result.json'), readJson($prefix.'-1-result.json')];
        $slots = slotState($project->id);
        requireCondition(count($slots) === 4, 'Concurrency must leave exactly four slots.');
        requireCondition(array_column($slots, 'slot_code') === ['project_proposer', 'related_approver', 'deputy_director', 'director'], 'Concurrency changed canonical slot codes.');
        requireCondition(array_column($slots, 'slot_no') === [1, 2, 3, 4], 'Concurrency changed canonical slot numbers.');
        $audits = AuditLog::query()->where('id', '>', $beforeAuditId)->get();
        requireCondition($audits->count() === 1, 'Exactly one committed audit must describe the winning mutation.');
        $audit = $audits->sole();

        if ($case['operation'] === 'assignment') {
            $statuses = array_column($results, 'status');
            sort($statuses);
            requireCondition($statuses === [200, 409], 'Same-revision writers must return one HTTP 200 and one HTTP 409.');
            $winner = $results[0]['status'] === 200 ? 0 : 1;
            $loser = 1 - $winner;
            $slot = $slots[0];
            requireCondition($results[$loser]['code'] === 'assignment_revision_conflict', 'The rejected writer was not a revision conflict.');
            requireCondition($slot['assignment_revision'] === 1 && $results[$winner]['assignment_revision'] === 1, 'Concurrent requests must increment the revision exactly once.');
            requireCondition($slot['assigned_user_id'] === $fixture['candidates'][$winner] && $slot['assigned_by'] === $fixture['actors'][$winner], 'The slot does not preserve the winning actor and candidate.');
            requireCondition($results[$winner]['slot_id'] === $slot['id'] && $results[$winner]['assigned_user_id'] === $slot['assigned_user_id'], 'The successful response returned a different assignment.');
            requireCondition($slot['assigned_at'] !== null && $slot['updated_at'] === $slot['assigned_at'], 'The winning assignment must use a single timestamp.');
            $expectedSlots = $beforeSlots;
            foreach (['assigned_user_id', 'assignment_revision', 'assigned_by', 'assigned_at', 'updated_at'] as $field) {
                $expectedSlots[0][$field] = $slot[$field];
            }
            requireCondition($expectedSlots === $slots, 'The assignment race changed immutable fields or another slot.');
            requireCondition($audit->action === 'project_signature_slot.assigned' && $audit->auditable_type === ProjectSignatureSlot::class && $audit->auditable_id === $slot['id'], 'The winning audit has incorrect action or subject.');
            requireCondition($audit->user_id === $slot['assigned_by'] && $audit->new_values['actor_id'] === $slot['assigned_by'], 'The audit has incorrect winner provenance.');
            requireCondition($audit->old_values['assignment_revision'] === 0 && $audit->old_values['assigned_user_id'] === null && $audit->new_values['assignment_revision'] === 1 && $audit->new_values['assigned_user_id'] === $slot['assigned_user_id'], 'The audit must record exactly one transition from revision zero.');
            requireCondition($audit->new_values['source'] === 'assignment_api', 'The assignment audit has incorrect source.');
            $committed = databaseState();
            $replay = runAssignment($fixture['actors'][$winner], $project->id, $slot['assigned_user_id'], 1);
            requireCondition($replay['status'] === 200 && $replay['assignment_revision'] === 1, 'A current-revision same-target replay must be a no-op.');
            $stale = runAssignment($fixture['actors'][$loser], $project->id, $slot['assigned_user_id'], 0);
            requireCondition($stale['status'] === 409 && $stale['code'] === 'assignment_revision_conflict', 'An old revision must conflict even with the now-current target.');
            requireCondition($committed === databaseState(), 'No-op or stale replay changed timestamps, revision, audit or other rows.');
        } else {
            requireCondition(count(array_filter($results, static fn (array $result): bool => $result['created'])) === 1, 'Exactly one backfill must initialize the missing slots.');
            $winner = $results[0]['created'] ? 0 : 1;
            $missing = 4 - count($beforeSlots);
            requireCondition($results[$winner]['inserted_rows'] === $missing && $results[1 - $winner]['inserted_rows'] === 0, 'Backfill inserted an incorrect number of rows.');
            requireCondition(array_slice($slots, 0, count($beforeSlots)) === $beforeSlots, 'Backfill changed an existing assignment, revision, provenance or timestamp.');
            $newSlots = array_slice($slots, count($beforeSlots));
            foreach ($newSlots as $slot) {
                requireCondition($slot['assigned_user_id'] === null && $slot['assigned_by'] === null && $slot['assigned_at'] === null && $slot['assignment_revision'] === 0, 'Backfill created a nonblank assignment.');
            }
            requireCondition($audit->action === 'project_signature_slots.backfilled' && $audit->auditable_type === Project::class && $audit->auditable_id === $project->id, 'Backfill audit has an incorrect action or project.');
            requireCondition($audit->user_id === null && $audit->old_values === null && $audit->new_values['actor_id'] === null && $audit->new_values['source'] === 'backfill', 'Backfill audit must preserve maintenance provenance.');
            requireCondition(is_string($audit->new_values['run_id']) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $audit->new_values['run_id']) === 1, 'Backfill audit requires a run UUID.');
            requireCondition(array_column($audit->new_values['slots'], 'id') === array_column($newSlots, 'id'), 'Backfill audit must contain exactly the inserted slot identities.');
            $committed = databaseState();
            $replay = runBackfill($project->id);
            requireCondition(! $replay['created'] && $replay['inserted_rows'] === 0, 'A later backfill must be idempotent.');
            requireCondition($committed === databaseState(), 'Backfill replay changed the committed rows or audit.');
        }

        $after = databaseState();
        assertUnrelatedRowsUnchanged($before, $after, $project->id, $beforeAuditId);
        $priorAudits = array_values(array_filter($after['audit_logs'], static fn (array $row): bool => $row['id'] <= $beforeAuditId));
        requireCondition($before['audit_logs'] === $priorAudits, 'Concurrency changed an existing audit.');
        $otherSlots = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $row): bool => $row['project_id'] !== $project->id));
        requireCondition($otherSlots($before['project_signature_slots']) === $otherSlots($after['project_signature_slots']), 'Concurrency changed another project slot.');
        requireCondition(DB::transactionLevel() === 0, 'The coordinator left a transaction open.');
        $reports[$name] = [
            'passed' => true,
            'overlapping_mysql_lock_waiters' => $overlap,
            'project_id' => $project->id,
            'winning_worker' => $winner,
            'statuses' => $case['operation'] === 'assignment' ? array_column($results, 'status') : null,
            'new_slot_rows' => count($slots) - count($beforeSlots),
            'new_audit_rows' => 1,
            'replays_unchanged' => true,
            'unrelated_rows_unchanged' => true,
        ];
        writeJson($prefix.'-case-report.json', $reports[$name]);
        echo json_encode(['case' => $name] + $reports[$name], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as $number => $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
            file_put_contents($prefix.'-'.$number.'-stdout.log', $worker->getOutput());
            file_put_contents($prefix.'-'.$number.'-stderr.log', $worker->getErrorOutput());
        }
    }
}

$report = [
    'passed' => true,
    'mysql_version' => '8.4.11',
    'target' => '127.0.0.1:33084 / school_dss_foundation_test',
    'case_count' => count($reports),
    'cases' => $reports,
];
writeJson($runtime.'/'.$runId.'-report.json', $report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
