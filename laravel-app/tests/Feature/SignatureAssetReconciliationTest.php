<?php

namespace Tests\Feature;

use App\Models\SignatureAsset;
use App\Models\User;
use App\DTOs\Signatures\OwnedSignatureFile;
use App\Services\Signatures\PngStructureValidator;
use App\Services\Signatures\SignatureAssetStorage;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\GuardsSignatureAssetMysql;
use Tests\Feature\Concerns\UsesPrivateSignatureStorage;
use Tests\TestCase;

class SignatureAssetReconciliationTest extends TestCase
{
    use GuardsSignatureAssetMysql, DatabaseMigrations {
        GuardsSignatureAssetMysql::beforeRefreshingDatabase insteadof DatabaseMigrations;
    }
    use UsesPrivateSignatureStorage;

    /** Reconciliation requires top-level transactions on a disposable database. */
    public function runDatabaseMigrations(): void
    {
        $this->guardSignatureAssetDatabase();
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->artisan('migrate:fresh', $this->migrateFreshUsing())->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->beforeApplicationDestroyed(function (): void {
            // The populated immutable registry forbids migrate:rollback.
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
            DB::disconnect();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateSignatureStorage();
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->signatureStorage = new ReconciliationStorageProbe(new PngStructureValidator);
    }

    protected function tearDown(): void
    {
        $this->tearDownPrivateSignatureStorage();
        parent::tearDown();
    }

    public function test_default_dry_run_does_not_mutate_database_or_any_asset_root_file(): void
    {
        $snapshotFiles = static function (string $root): array {
            $files = [];
            foreach (new \DirectoryIterator($root) as $entry) {
                if (! $entry->isDot()) {
                    $files[$entry->getFilename()] = file_get_contents($entry->getPathname());
                }
            }
            ksort($files);

            return $files;
        };
        $root = config('signature_assets.storage_root');
        $beforeAssets = DB::table('signature_assets')->orderBy('id')->get()->toArray();
        $beforeAudits = DB::table('audit_logs')->orderBy('id')->get()->toArray();
        $beforeFiles = $snapshotFiles($root);
        $this->assertSame([], $beforeFiles);

        $counts = $this->signatureStorage->reconcile();

        $this->assertSame(0, $counts['referenced']);
        $this->assertSame(0, $counts['removed']);
        $this->assertEquals($beforeAssets, DB::table('signature_assets')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeAudits, DB::table('audit_logs')->orderBy('id')->get()->toArray());
        $this->assertSame($beforeFiles, $snapshotFiles($root), 'Default dry-run must not create, change, or delete any asset-root file.');
    }

    public function test_dry_run_retains_orphans_and_apply_removes_only_proven_inactive_unreferenced_receipts(): void
    {
        $orphan = $this->privateSignatureReceipt();
        $active = $this->privateSignatureReceipt();
        $this->signatureStorage->preserve($orphan);
        $orphanPath = $orphan->root.DIRECTORY_SEPARATOR.$orphan->storageKey;
        $record = json_decode(file_get_contents($orphanPath.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $record['created_at'] = time() - 7200;
        file_put_contents($orphanPath.'.json', json_encode($record, JSON_THROW_ON_ERROR));
        file_put_contents($orphanPath.'.lock', 'stable lease identity');
        $leaseIdentity = lstat($orphanPath.'.lock');
        $this->signatureStorage->diagnostics = [];
        $dry = $this->signatureStorage->reconcile();
        $this->assertSame(1, $dry['candidate']);
        $this->assertSame(1, $dry['recent']);
        $this->assertSame(2, $dry['activity_unknown']);
        $this->assertSame(0, $dry['removed']);
        $this->assertFileExists($orphanPath);
        $beforeApply = [
            'storage_key' => $orphan->storageKey, 'public_id' => $orphan->publicId,
            'paths' => ['png' => $orphanPath, 'receipt' => $orphanPath.'.json', 'lease' => $orphanPath.'.lock'],
            'created_at' => $record['created_at'], 'now' => time(), 'minimum_age_seconds' => 3600,
            'clock' => 'real Unix seconds, not frozen',
            'receipt_key_matches' => $record['key'] === $orphan->storageKey,
            'receipt_public_id_matches' => $record['public_id'] === $orphan->publicId,
            'released' => $orphan->released,
            'png_handle_open' => is_resource($orphan->handle), 'lease_handle_open' => is_resource($orphan->lease),
            'lease_identity' => $leaseIdentity,
            'rows' => DB::table('signature_assets')->get(['id', 'storage_key', 'public_id'])->all(),
            'storage_key_rows' => DB::table('signature_assets')->where('storage_key', $orphan->storageKey)->count(),
            'public_id_rows' => DB::table('signature_assets')->where('public_id', $orphan->publicId)->count(),
            'laravel_transaction_level' => DB::connection()->transactionLevel(),
            'pdo_in_transaction' => DB::connection()->getPdo()->inTransaction(),
        ];
        $dryTrace = $this->signatureStorage->diagnostics;
        $this->signatureStorage->diagnostics = [];
        [$apply, $pdo] = $this->withRecordingPrimary(fn () => $this->signatureStorage->reconcile(true));
        $this->assertSame(1, $apply['removed'], json_encode([
            'before_apply' => $beforeApply, 'dry' => $dry, 'dry_trace' => $dryTrace,
            'apply' => $apply, 'apply_trace' => $this->signatureStorage->diagnostics,
            'primary_queries' => $pdo->queries, 'primary_commands' => $pdo->commands,
            'primary_events' => $pdo->events,
            'after_pdo_in_transaction' => DB::connection()->getPdo()->inTransaction(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertFileDoesNotExist($orphanPath);
        $this->assertFileDoesNotExist($orphanPath.'.json');
        $this->assertFileExists($active->root.DIRECTORY_SEPARATOR.$active->storageKey);
        clearstatcache();
        $this->assertSame($leaseIdentity, lstat($orphanPath.'.lock'));
        $this->assertSame('stable lease identity', file_get_contents($orphanPath.'.lock'));
        $this->assertCandidateLeaseReleased($orphanPath.'.lock');
        $this->assertNoGlobalReconciliationArtifact();
    }

    public function test_committed_or_ambiguously_committed_rows_keep_bytes_including_after_retirement(): void
    {
        $receipt = $this->privateSignatureReceipt();
        $owner = User::factory()->create();
        $asset = $this->signatureAssetForReceipt($receipt);
        $asset->owner_id = $owner->id;
        $asset->save();
        $this->signatureStorage->preserve($receipt);
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $record = json_decode(file_get_contents($path.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $record['created_at'] = time() - 7200;
        file_put_contents($path.'.json', json_encode($record, JSON_THROW_ON_ERROR));
        foreach (['active', 'retired'] as $status) {
            if ($status === 'retired') {
                $asset->forceFill(['status' => 'retired', 'retired_at' => now(), 'retired_by' => $owner->id])->save();
            }
            $result = $this->signatureStorage->reconcile(true);
            $this->assertSame(1, $result['referenced']);
            $this->assertSame(0, $result['removed']);
            $this->assertFileExists($path);
        }
        $this->assertSame(1, SignatureAsset::query()->count());
    }

    public function test_untracked_tampered_and_recent_objects_are_never_removed(): void
    {
        $recent = $this->privateSignatureReceipt();
        $tampered = $this->privateSignatureReceipt();
        $this->signatureStorage->preserve($recent);
        $this->signatureStorage->preserve($tampered);
        $path = $tampered->root.DIRECTORY_SEPARATOR.$tampered->storageKey;
        $record = json_decode(file_get_contents($path.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $record['created_at'] = time() - 7200;
        file_put_contents($path.'.json', json_encode($record, JSON_THROW_ON_ERROR));
        file_put_contents($path, 'tampered');
        $untracked = $tampered->root.DIRECTORY_SEPARATOR.str_repeat('d', 64).'.png';
        file_put_contents($untracked, $this->signaturePng());
        $counts = $this->signatureStorage->reconcile(true, 0);
        $this->assertSame(1, $counts['recent']);
        // A missing lease is uncertainty; only the altered receipt is proven corrupt.
        $this->assertSame(1, $counts['corrupt']);
        $this->assertSame(1, $counts['unresolved']);
        $this->assertSame(0, $counts['removed']);
        $this->assertFileExists($path);
        $this->assertFileExists($untracked);
    }

    #[DataProvider('dryRunScenarios')]
    public function test_repeated_dry_run_preserves_rows_files_and_metadata_for_receipt_scenarios(string $scenario): void
    {
        $receipt = $this->privateSignatureReceipt();
        $this->signatureStorage->preserve($receipt);
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $this->updateReceiptJournal($path, ['created_at' => time() - 7200]);
        $outside = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'outside-sentinel.png';
        file_put_contents($outside, 'unrelated outside bytes');

        if (in_array($scenario, ['active asset', 'retired asset', 'missing file'], true)) {
            $owner = User::factory()->create();
            $asset = $this->signatureAssetForReceipt($receipt);
            $asset->owner_id = $owner->id;
            $asset->save();
            if ($scenario === 'retired asset') {
                $asset->forceFill(['status' => 'retired', 'retired_at' => now(), 'retired_by' => $owner->id])->save();
            }
        }
        match ($scenario) {
            'missing file' => $this->assertTrue(unlink($path)),
            'checksum mismatch' => $this->updateReceiptJournal($path, ['sha256' => str_repeat('0', 64)]),
            'size mismatch' => $this->updateReceiptJournal($path, ['size_bytes' => $receipt->sizeBytes + 1]),
            // The immutable receipt remains original: corruption is rejected by its size/hash.
            // This safety test does not claim an independent PNG diagnostic or DB inventory scan.
            'malformed stored PNG' => file_put_contents($path, 'not a PNG'),
            'receipt path escape' => $this->updateReceiptJournal($path, ['key' => '../outside-sentinel.png']),
            default => null,
        };
        $beforeRows = $this->snapshotReconciliationRows();
        $beforeFiles = $this->snapshotReconciliationFiles();
        $first = $this->signatureStorage->reconcile();
        $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
        $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
        $this->assertSame(0, $first['removed']);
        if (in_array($scenario, ['active asset', 'retired asset'], true)) {
            $this->assertSame(1, $first['referenced']);
        } elseif ($scenario === 'orphan file') {
            $this->assertSame(1, $first['candidate']);
            $this->assertSame(1, $first['activity_unknown']);
        } elseif ($scenario !== 'missing file') {
            $this->assertSame(1, $first['corrupt']);
        }
        $this->assertSame($first, $this->signatureStorage->reconcile());
        $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
        $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
        $this->assertFileDoesNotExist($receipt->root.DIRECTORY_SEPARATOR.'.reconcile.lock');
    }

    public static function dryRunScenarios(): array
    {
        return array_combine($names = [
            'active asset', 'retired asset', 'missing file', 'orphan file',
            'checksum mismatch', 'size mismatch', 'malformed stored PNG', 'receipt path escape',
        ], array_map(static fn (string $name): array => [$name], $names));
    }

    #[DataProvider('invalidDryRunRoots')]
    public function test_dry_run_root_failure_never_provisions_or_mutates_any_directory(string $configuration, string $suffix): void
    {
        $original = config($configuration);
        config()->set($configuration, $this->signatureTestDirectory.DIRECTORY_SEPARATOR.$suffix);
        $beforeRows = $this->snapshotReconciliationRows();
        $beforeFiles = $this->snapshotReconciliationFiles();
        try {
            $this->assertSignatureProblem(fn () => $this->signatureStorage->reconcile());
            $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
            $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
        } finally {
            config()->set($configuration, $original);
        }
    }

    public static function invalidDryRunRoots(): array
    {
        return [
            'missing asset root' => ['signature_assets.storage_root', 'missing/assets'],
            'missing temporary root' => ['signature_assets.temporary_directory', 'missing/temporary'],
            'asset path escape' => ['signature_assets.storage_root', 'assets/../outside'],
        ];
    }

    public function test_dry_run_database_failure_leaves_no_artifact_or_row_change(): void
    {
        $receipt = $this->agedReceipt();
        $beforeRows = $this->snapshotReconciliationRows();
        $beforeFiles = $this->snapshotReconciliationFiles();
        [$counts, $pdo] = $this->withRecordingPrimary(fn () => $this->signatureStorage->reconcile(), failReferenceQuery: true);
        $this->assertSame(1, $counts['unresolved']);
        $this->assertSame(1, $counts['activity_unknown']);
        $this->assertSame(0, $counts['removed']);
        $this->assertSame([], $pdo->commands);
        $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
        $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
        $this->assertSame(1, $this->signatureStorage->reconcile()['candidate']);
        $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
    }

    public function test_dry_run_uses_no_flock_locking_query_or_filesystem_mutation(): void
    {
        $this->agedReceipt();
        $beforeFiles = $this->snapshotReconciliationFiles();
        $beforeRows = $this->snapshotReconciliationRows();
        $storage = $this->reconciliationProbe();
        $storage->resetObservations();

        [$counts, $pdo] = $this->withRecordingPrimary(fn () => $storage->reconcile());

        $this->assertSame(1, $counts['candidate']);
        $this->assertSame(1, $counts['activity_unknown']);
        $this->assertSame(0, $counts['busy']);
        $this->assertSame(0, $counts['removed']);
        $this->assertSame(0, $storage->locks);
        $this->assertSame([], $storage->deletions);
        $this->assertNotEmpty($storage->opens);
        $this->assertSame(['rb'], array_values(array_unique(array_column($storage->opens, 'mode'))));
        $this->assertSame([], $pdo->commands, 'Observation must not begin a locking transaction.');
        $this->assertCount(2, $pdo->queries);
        $this->assertStringContainsString('WHERE storage_key', $pdo->queries[0]);
        $this->assertStringContainsString('WHERE public_id', $pdo->queries[1]);
        foreach ($pdo->queries as $query) {
            $this->assertStringNotContainsString('FOR SHARE', $query);
            $this->assertStringNotContainsString('FOR UPDATE', $query);
        }
        $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
        $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
        $this->assertNoGlobalReconciliationArtifact();
    }

    #[DataProvider('staleArtifacts')]
    public function test_stale_global_artifact_is_ignored_by_name_without_access(string $kind): void
    {
        $receipt = $this->agedReceipt();
        $path = config('signature_assets.storage_root').DIRECTORY_SEPARATOR.'.reconcile.lock';
        $outside = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'outside-stale-target';
        $restore = $this->createStaleArtifact($path, $outside, $kind);
        try {
            if ($kind === 'unreadable') {
                $this->assertFalse(@file_get_contents($path), 'The fixture must actually deny byte reads.');
            }
            $storage = $this->reconciliationProbe();
            $storage->resetObservations();
            $this->assertSame(1, $storage->reconcile()['candidate']);
            $this->assertSame(1, $storage->reconcile(true)['removed']);
            $this->assertNotContains($path, $storage->stats);
            $this->assertNotContains($path, array_column($storage->opens, 'path'));
            $this->assertNotContains($path, $storage->reads);
            $this->assertNotContains($path, $storage->deletions);
            $this->assertContains('.reconcile.lock', scandir(config('signature_assets.storage_root')));
            $this->assertFileDoesNotExist($receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey);
            if (in_array($kind, ['symlink', 'directory reparse'], true)) {
                $sentinel = $kind === 'symlink' ? $outside : $outside.DIRECTORY_SEPARATOR.'sentinel';
                $this->assertSame('outside sentinel', file_get_contents($sentinel));
            } elseif ($kind === 'directory') {
                $this->assertSame('stale directory bytes', file_get_contents($path.DIRECTORY_SEPARATOR.'sentinel'));
            }
        } finally {
            $restore();
        }
        if (in_array($kind, ['regular', 'unreadable'], true)) {
            $this->assertSame('stale coordination bytes', file_get_contents($path));
        }
    }

    public static function staleArtifacts(): array
    {
        return array_combine($names = ['regular', 'unreadable', 'directory', 'symlink', 'directory reparse'],
            array_map(static fn (string $name): array => [$name], $names));
    }

    public function test_apply_removes_only_proven_orphan_and_preserves_another_requests_owned_file_and_unrelated_bytes(): void
    {
        $orphan = $this->privateSignatureReceipt();
        $this->signatureStorage->preserve($orphan);
        $orphanPath = $orphan->root.DIRECTORY_SEPARATOR.$orphan->storageKey;
        $this->updateReceiptJournal($orphanPath, ['created_at' => time() - 7200]);
        $otherRequest = $this->privateSignatureReceipt();
        $otherPath = $otherRequest->root.DIRECTORY_SEPARATOR.$otherRequest->storageKey;
        $otherIdentity = fstat($otherRequest->handle);
        $unrelated = $orphan->root.DIRECTORY_SEPARATOR.'project-document.pdf';
        $outside = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'outside-sentinel.png';
        file_put_contents($unrelated, 'unrelated document bytes');
        file_put_contents($outside, 'outside bytes');
        $beforeRows = $this->snapshotReconciliationRows();

        $counts = $this->signatureStorage->reconcile(true);

        $this->assertSame(1, $counts['removed']);
        $this->assertSame(1, $counts['busy']);
        $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
        foreach (['', '.json'] as $suffix) {
            $this->assertFileDoesNotExist($orphanPath.$suffix);
        }
        $this->assertCandidateLeaseReleased($orphanPath.'.lock');
        $this->assertSame($otherIdentity, fstat($otherRequest->handle));
        $this->assertFalse($otherRequest->released);
        $this->signatureStorage->preserve($otherRequest);
        $this->assertSame($this->signaturePng(), file_get_contents($otherPath));
        $this->assertFileExists($otherPath.'.json');
        $this->assertFileExists($otherPath.'.lock');
        $this->assertSame('unrelated document bytes', file_get_contents($unrelated));
        $this->assertSame('outside bytes', file_get_contents($outside));
        $afterFiles = $this->snapshotReconciliationFiles();
        $this->assertSame(0, $this->signatureStorage->reconcile(true)['removed']);
        $this->assertSame($afterFiles, $this->snapshotReconciliationFiles());
        $this->assertNoGlobalReconciliationArtifact();
    }

    public function test_apply_database_exception_releases_owned_candidate_lease_and_retains_bytes_for_retry(): void
    {
        $receipt = $this->privateSignatureReceipt();
        $this->signatureStorage->preserve($receipt);
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $this->updateReceiptJournal($path, ['created_at' => time() - 7200]);
        $png = file_get_contents($path);
        $journal = file_get_contents($path.'.json');
        $beforeRows = $this->snapshotReconciliationRows();
        $primary = DB::connection()->getPdo();
        $driver = $primary->getAttribute(PDO::ATTR_DRIVER_NAME);
        $settingsQuery = 'SELECT @@SESSION.innodb_lock_wait_timeout AS innodb_wait, @@SESSION.lock_wait_timeout AS metadata_wait';
        $settings = $driver === 'mysql' ? $primary->query($settingsQuery)->fetch(PDO::FETCH_ASSOC) : null;
        [$counts, $pdo] = $this->withRecordingPrimary(fn () => $this->signatureStorage->reconcile(true), failReferenceQuery: true);
        $this->assertSame(1, $counts['unresolved']);
        $this->assertSame(0, $counts['removed']);
        $this->assertSame(0, $counts['partial_error']);
        $referenceQuery = 'SELECT id, storage_key, public_id FROM signature_assets'
            .($driver === 'mysql' ? ' FORCE INDEX (signature_assets_storage_key_unique)' : '')
            .' WHERE storage_key = :identity'.($driver === 'mysql' ? ' FOR SHARE' : '');
        $failure = ['operation' => 'prepare', 'query' => $referenceQuery, 'error' => \PDOException::class, 'code' => 0];
        $this->assertSame([$failure], array_values(array_filter($pdo->events, static fn (array $event): bool => isset($event['error']))));
        if ($driver === 'mysql') {
            $this->assertSame([$settingsQuery, $referenceQuery], $pdo->queries);
            $this->assertSame([
                ['operation' => 'beginTransaction', 'result' => true],
                $failure,
                ['operation' => 'rollBack', 'result' => true],
            ], array_values(array_filter($pdo->events, static fn (array $event): bool =>
                in_array($event['operation'], ['beginTransaction', 'rollBack'], true) || isset($event['error']))));
            $wait = (int) config('signature_assets.reconciliation_lock_wait_seconds', 2);
            $this->assertSame([
                'SET SESSION innodb_lock_wait_timeout = '.$wait,
                'SET SESSION lock_wait_timeout = '.$wait,
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                'SET SESSION innodb_lock_wait_timeout = '.$settings['innodb_wait'],
                'SET SESSION lock_wait_timeout = '.$settings['metadata_wait'],
            ], $pdo->commands);
            $this->assertSame($settings, $primary->query($settingsQuery)->fetch(PDO::FETCH_ASSOC));
        } else {
            $this->assertSame([$referenceQuery], $pdo->queries);
            $this->assertSame(['BEGIN IMMEDIATE', 'ROLLBACK'], $pdo->commands);
        }
        $this->assertSame($primary, DB::connection()->getRawPdo());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse($primary->inTransaction());
        $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
        $this->assertSame($png, file_get_contents($path));
        $this->assertSame($journal, file_get_contents($path.'.json'));
        $this->assertCandidateLeaseReleased($path.'.lock');
        $this->assertNoGlobalReconciliationArtifact();
        $this->assertSame(1, $this->signatureStorage->reconcile(true)['removed']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_real_mysql_lock_timeout_retains_candidate_and_releases_lease(): void
    {
        $competitor = $this->independentReconciliationMysql();
        $primary = DB::connection()->getPdo();
        $primaryId = $primary->query('SELECT CONNECTION_ID()')->fetchColumn();
        $receipt = $this->agedReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $asset = $this->signatureAssetForReceipt($receipt);
        $asset->owner_id = User::factory()->create()->id;
        $beforeFiles = $this->snapshotReconciliationFiles();
        $beforeRows = $this->snapshotReconciliationRows();
        $settingsQuery = 'SELECT @@SESSION.innodb_lock_wait_timeout AS innodb_wait, @@SESSION.lock_wait_timeout AS metadata_wait';
        $settings = $primary->query($settingsQuery)->fetch(PDO::FETCH_ASSOC);
        // Observe the real server error even though the guard deliberately catches it.
        $timeoutQuery = 'SELECT errors.SUM_ERROR_RAISED FROM performance_schema.events_errors_summary_by_thread_by_error AS errors'
            .' JOIN performance_schema.threads AS threads ON threads.THREAD_ID = errors.THREAD_ID'
            .' WHERE threads.PROCESSLIST_ID = CONNECTION_ID() AND errors.ERROR_NUMBER = 1205';
        $timeouts = $primary->query($timeoutQuery)->fetchColumn();
        $this->assertNotFalse($timeouts, 'This MySQL gate requires its per-session lock-timeout counter.');
        config()->set('signature_assets.reconciliation_lock_wait_seconds', 1);
        $storage = $this->reconciliationProbe();
        $beforeDelete = 0;
        $storage->checkpoint = static function (string $phase) use (&$beforeDelete): void {
            $beforeDelete += (int) ($phase === 'before-delete');
        };

        $competitor->beginTransaction();
        try {
            $this->insertMysqlReference($competitor, $asset);
            $this->assertSame(1, (int) $competitor->query('SELECT COUNT(*) FROM signature_assets')->fetchColumn());
            $counts = $storage->reconcile(true);

            $this->assertSame((int) $timeouts + 1, (int) $primary->query($timeoutQuery)->fetchColumn());
            $this->assertSame(1, $counts['unresolved']);
            $this->assertSame(0, $counts['removed']);
            $this->assertSame(0, $counts['partial_error']);
            $this->assertSame(0, $beforeDelete);
            $this->assertSame([], $storage->deletions);
            $this->assertSame($primary, DB::connection()->getRawPdo());
            $this->assertSame($primaryId, $primary->query('SELECT CONNECTION_ID()')->fetchColumn());
            $this->assertFalse($primary->inTransaction());
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertTrue($competitor->inTransaction(), 'Reconciliation must not end the blocking caller transaction.');
            $this->assertSame($settings, $primary->query($settingsQuery)->fetch(PDO::FETCH_ASSOC));
            $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
            $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
            $this->assertCandidateLeaseReleased($path.'.lock');
        } finally {
            $storage->checkpoint = null;
            if ($competitor->inTransaction()) {
                $competitor->rollBack();
            }
        }

        $this->assertSame(1, $storage->reconcile(true)['removed']);
        $this->assertFalse($primary->inTransaction());
        $this->assertSame($settings, $primary->query($settingsQuery)->fetch(PDO::FETCH_ASSOC));
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path.'.json');
        $this->assertNoGlobalReconciliationArtifact();
    }

    public function test_real_mysql_reference_insert_waits_while_reconciliation_holds_proof_and_lease(): void
    {
        $competitor = $this->independentReconciliationMysql();
        $competitor->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $primary = DB::connection()->getPdo();
        $primaryId = $primary->query('SELECT CONNECTION_ID()')->fetchColumn();
        $receipt = $this->agedReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $asset = $this->signatureAssetForReceipt($receipt);
        $asset->owner_id = User::factory()->create()->id;
        $probes = ['key only' => clone $asset, 'public ID only' => clone $asset];
        $probes['key only']->public_id = (string) Str::uuid();
        $probes['public ID only']->storage_key = bin2hex(random_bytes(32)).'.png';
        $beforeRows = $this->snapshotReconciliationRows();
        $storage = $this->reconciliationProbe();
        $observations = [];
        $indexes = [];
        $storage->checkpoint = function (string $phase, string $candidate) use ($path, $primary, $primaryId, $competitor, $probes, &$observations, &$indexes): void {
            if ($phase !== 'before-delete' || $candidate !== $path) {
                return;
            }
            // Record inside the caught callback; assert outside so failures cannot be swallowed.
            $locks = $competitor->prepare('SELECT DISTINCT locks.INDEX_NAME FROM performance_schema.data_locks AS locks'
                .' JOIN performance_schema.threads AS threads ON threads.THREAD_ID = locks.THREAD_ID'
                ." WHERE threads.PROCESSLIST_ID = ? AND locks.OBJECT_NAME = 'signature_assets'"
                ." AND locks.LOCK_TYPE = 'RECORD' AND locks.LOCK_STATUS = 'GRANTED'");
            $locks->execute([$primaryId]);
            $indexes = $locks->fetchAll(PDO::FETCH_COLUMN);
            foreach ($probes as $identity => $probe) {
                $observation = [
                    'transaction_before' => $primary->inTransaction(),
                    'lease_before' => $this->candidateLeaseIsHeld($path.'.lock'),
                    'mysql_error' => null,
                ];
                $competitor->beginTransaction();
                try {
                    try {
                        $this->insertMysqlReference($competitor, $probe);
                    } catch (\PDOException $exception) {
                        $observation['mysql_error'] = $exception->errorInfo[1] ?? null;
                    }
                    $observation['transaction_after'] = $primary->inTransaction();
                    $observation['lease_after'] = $this->candidateLeaseIsHeld($path.'.lock');
                    $observation['competitor_rows'] = (int) $competitor->query('SELECT COUNT(*) FROM signature_assets')->fetchColumn();
                    $observations[$identity] = $observation;
                } finally {
                    if ($competitor->inTransaction()) {
                        $competitor->rollBack();
                    }
                }
            }
        };
        try {
            $counts = $storage->reconcile(true);
        } finally {
            $storage->checkpoint = null;
            if ($competitor->inTransaction()) {
                $competitor->rollBack();
            }
        }

        $this->assertCount(2, $observations);
        foreach ($observations as $identity => $observation) {
            $this->assertSame([
                'transaction_before' => true, 'lease_before' => true, 'mysql_error' => 1205,
                'transaction_after' => true, 'lease_after' => true, 'competitor_rows' => 0,
            ], $observation, $identity);
        }
        $this->assertContains('signature_assets_storage_key_unique', $indexes);
        $this->assertContains('signature_assets_public_id_unique', $indexes);
        $this->assertSame(1, $counts['removed']);
        $this->assertSame(0, $counts['unresolved']);
        $this->assertSame(0, $counts['partial_error']);
        $this->assertSame($primary, DB::connection()->getRawPdo());
        $this->assertSame($primaryId, $primary->query('SELECT CONNECTION_ID()')->fetchColumn());
        $this->assertFalse($primary->inTransaction());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path.'.json');
        $this->assertCandidateLeaseReleased($path.'.lock');
        // Positive controls: both inserts become possible after proof release.
        // Roll back each probe; do not persist a reference to deliberately removed bytes.
        foreach ($probes as $probe) {
            $competitor->beginTransaction();
            try {
                $this->insertMysqlReference($competitor, $probe);
                $this->assertSame(1, (int) $competitor->query('SELECT COUNT(*) FROM signature_assets')->fetchColumn());
            } finally {
                $competitor->rollBack();
            }
        }
        $this->assertFalse($competitor->inTransaction());
        $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
        $this->assertNoGlobalReconciliationArtifact();
    }

    public function test_apply_rejects_escape_before_mutation_and_retains_outside_sentinel(): void
    {
        $root = config('signature_assets.storage_root');
        $outside = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'outside-sentinel.png';
        file_put_contents($outside, 'outside bytes');
        config()->set('signature_assets.storage_root', $root.DIRECTORY_SEPARATOR.'..');
        $beforeFiles = $this->snapshotReconciliationFiles();
        $beforeRows = $this->snapshotReconciliationRows();
        try {
            $this->assertSignatureProblem(fn () => $this->signatureStorage->reconcile(true));
            $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
            $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
        } finally {
            config()->set('signature_assets.storage_root', $root);
        }
    }

    #[DataProvider('referenceIdentities')]
    public function test_either_reference_identity_retains_bytes_and_reports_mismatch(string $identity): void
    {
        $receipt = $this->agedReceipt();
        $asset = $this->signatureAssetForReceipt($receipt);
        $owner = User::factory()->create();
        $asset->owner_id = $owner->id;
        if ($identity === 'key only') {
            $asset->public_id = (string) Str::uuid();
        } elseif ($identity === 'public ID only') {
            $asset->storage_key = str_repeat('a', 64).'.png';
        }
        $asset->save();
        if ($identity === 'retired') {
            $asset->forceFill(['status' => 'retired', 'retired_at' => now(), 'retired_by' => $owner->id])->save();
        }
        $beforeRows = $this->snapshotReconciliationRows();
        $beforeFiles = $this->snapshotReconciliationFiles();

        foreach ([false, true] as $apply) {
            $counts = $this->signatureStorage->reconcile($apply);
            $this->assertSame(1, $counts['referenced']);
            $this->assertSame((int) in_array($identity, ['key only', 'public ID only'], true), $counts['identity_mismatch']);
            $this->assertSame(0, $counts['removed']);
            $this->assertSame($beforeRows, $this->snapshotReconciliationRows());
            $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
        }
        $this->assertNoGlobalReconciliationArtifact();
    }

    public static function referenceIdentities(): array
    {
        return ['key only' => ['key only'], 'public ID only' => ['public ID only'], 'both' => ['both'], 'retired' => ['retired']];
    }

    #[DataProvider('lateReferences')]
    public function test_reference_committed_during_reconciliation_is_retained(string $phase, bool $uploaderOwns): void
    {
        $receipt = $this->agedReceipt(release: ! $uploaderOwns);
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $asset = $this->signatureAssetForReceipt($receipt);
        $asset->owner_id = User::factory()->create()->id;
        $commits = 0;
        $storage = $this->reconciliationProbe();
        $storage->checkpoint = function (string $observed, string $candidate) use ($phase, $path, $asset, $receipt, &$commits): void {
            if ($observed !== $phase || $candidate !== $path) {
                return;
            }
            $this->assertSame(0, $commits++);
            DB::transaction(fn () => $asset->save());
            $this->signatureStorage->preserve($receipt);
        };

        $counts = $storage->reconcile(true);

        $this->assertSame(1, $commits);
        $this->assertSame(1, $counts['referenced']);
        $this->assertSame(0, $counts['removed']);
        $this->assertSame($receipt->sha256, hash_file('sha256', $path));
        $this->assertTrue(SignatureAsset::query()->where('public_id', $receipt->publicId)->exists());
        $this->assertCandidateLeaseReleased($path.'.lock');
    }

    public static function lateReferences(): array
    {
        return [
            'commit after scan' => ['after-scan', false],
            'commit after lease acquisition' => ['after-ownership', false],
            'uploader commits during scan' => ['after-scan', true],
        ];
    }

    public function test_uploader_owning_before_scan_is_busy_and_never_queries_references(): void
    {
        $receipt = $this->agedReceipt(release: false);
        $identity = fstat($receipt->handle);
        [$counts, $pdo] = $this->withRecordingPrimary(fn () => $this->signatureStorage->reconcile(true));

        $this->assertSame(1, $counts['busy']);
        $this->assertSame(0, $counts['removed']);
        $this->assertSame([], $pdo->queries);
        $this->assertSame([], $pdo->commands);
        $this->assertSame($identity, fstat($receipt->handle));
        $this->assertFalse($receipt->released);
        $this->assertNoGlobalReconciliationArtifact();
    }

    public function test_missing_candidate_lease_is_retained_without_provisioning_a_replacement(): void
    {
        $receipt = $this->agedReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $this->assertTrue(unlink($path.'.lock'));
        $beforeFiles = $this->snapshotReconciliationFiles();
        $storage = $this->reconciliationProbe();
        $storage->resetObservations();

        $counts = $storage->reconcile(true);

        $this->assertSame(0, $counts['removed']);
        $this->assertSame(1, $counts['unresolved'] + $counts['corrupt']);
        $this->assertSame([], $storage->opens);
        $this->assertSame(0, $storage->locks);
        $this->assertSame([], $storage->deletions);
        $this->assertFileDoesNotExist($path.'.lock');
        $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
        $this->assertNoGlobalReconciliationArtifact();
    }

    #[DataProvider('disappearances')]
    public function test_disappearance_during_scan_does_not_abort_another_candidate(bool $uploaderRollsBack): void
    {
        $first = $this->agedReceipt(release: ! $uploaderRollsBack);
        $second = $this->agedReceipt();
        $firstPath = $first->root.DIRECTORY_SEPARATOR.$first->storageKey;
        $storage = $this->reconciliationProbe();
        $storage->candidateKeys = [$first->storageKey, $second->storageKey];
        $storage->checkpoint = function (string $phase, string $path) use ($first, $firstPath, $uploaderRollsBack): void {
            if ($phase !== 'after-scan' || $path !== $firstPath) {
                return;
            }
            if ($uploaderRollsBack) {
                $this->signatureStorage->rollback($first);
            } else {
                $this->assertTrue(unlink($path));
                $this->assertTrue(unlink($path.'.json'));
            }
        };

        $counts = $storage->reconcile(true);

        $this->assertSame(1, $counts['disappeared']);
        $this->assertSame(1, $counts['removed']);
        $this->assertSame(0, $counts['partial_error']);
        foreach ([$first, $second] as $receipt) {
            $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
            $this->assertFileDoesNotExist($path);
            $this->assertFileDoesNotExist($path.'.json');
            $this->assertCandidateLeaseReleased($path.'.lock');
        }
    }

    public static function disappearances(): array
    {
        return ['uploader rollback' => [true], 'already removed elsewhere' => [false]];
    }

    public function test_unreadable_active_bytes_are_unknown_in_dry_run_and_busy_in_apply(): void
    {
        $receipt = $this->agedReceipt(release: false);
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $storage = $this->reconciliationProbe();
        // Windows can deny reads while the upload handle is exclusively held.
        $storage->unreadable = $path;
        $storage->resetObservations();
        $dry = $storage->reconcile();
        $this->assertSame(1, $dry['unresolved']);
        $this->assertSame(1, $dry['activity_unknown']);
        $this->assertSame(0, $dry['corrupt']);
        $this->assertSame(0, $dry['removed']);
        $this->assertSame(0, $storage->locks);
        $this->assertSame([], $storage->deletions);
        $apply = $storage->reconcile(true);
        $this->assertSame(1, $apply['busy']);
        $this->assertSame(0, $apply['corrupt']);
        $this->assertSame(0, $apply['removed']);
        $this->signatureStorage->preserve($receipt);
        $this->assertSame($receipt->sha256, hash_file('sha256', $path));
    }

    #[DataProvider('ambientTransactions')]
    public function test_ambient_transaction_is_rejected_and_remains_owned_by_its_caller(string $kind): void
    {
        $receipt = $this->agedReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $beforeFiles = $this->snapshotReconciliationFiles();
        $pdo = DB::connection()->getPdo();
        match ($kind) {
            'application' => DB::beginTransaction(),
            'PDO' => $pdo->beginTransaction(),
            'raw SQLite' => $pdo->exec('BEGIN IMMEDIATE'),
        };
        try {
            // A raw SQLite BEGIN may be invisible to PDO; apply still rejects its nested BEGIN.
            foreach ($kind === 'raw SQLite' ? [true] : [false, true] as $apply) {
                $counts = $this->signatureStorage->reconcile($apply);
                $this->assertSame(1, $counts['unresolved']);
                $this->assertSame(0, $counts['removed']);
                $this->assertSame($beforeFiles, $this->snapshotReconciliationFiles());
            }
            if ($kind !== 'raw SQLite') {
                $this->assertTrue($pdo->inTransaction());
                $this->assertSame($kind === 'application' ? 1 : 0, DB::connection()->transactionLevel());
            }
        } finally {
            match ($kind) {
                'application' => DB::rollBack(),
                'PDO' => $pdo->rollBack(),
                'raw SQLite' => $pdo->exec('ROLLBACK'),
            };
        }
        $this->assertFileExists($path);
        $this->assertCandidateLeaseReleased($path.'.lock');
        $this->assertSame(1, $this->signatureStorage->reconcile(true)['removed']);
    }

    public static function ambientTransactions(): array
    {
        return ['application' => ['application'], 'PDO' => ['PDO'], 'raw SQLite' => ['raw SQLite']];
    }

    public function test_revalidation_allows_png_atime_only_change(): void
    {
        $this->assertAtimeOnlyRevalidation('');
    }

    public function test_revalidation_allows_receipt_atime_only_change(): void
    {
        $this->assertAtimeOnlyRevalidation('.json');
    }

    public function test_revalidation_retains_candidate_when_png_size_changes(): void
    {
        $receipt = $this->agedReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $storage = $this->reconciliationProbe();
        $changed = false;
        $storage->checkpoint = function (string $phase, string $candidate) use ($path, &$changed): void {
            if ($phase === 'before-delete' && $candidate === $path) {
                $changed = file_put_contents($path, 'changed', FILE_APPEND) === strlen('changed');
            }
        };

        $counts = $storage->reconcile(true);

        $this->assertTrue($changed, 'The fixture must change bytes after the initial inspection.');
        $this->assertSame($receipt->sizeBytes + strlen('changed'), $this->freshRevalidationStat($path)['size']);
        $this->assertRetainedAfterRevalidation($path, $counts, $storage);
    }

    public function test_revalidation_retains_candidate_when_png_mtime_changes(): void
    {
        $receipt = $this->agedReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $before = $this->freshRevalidationStat($path);
        $storage = $this->reconciliationProbe();
        $changed = false;
        $storage->checkpoint = function (string $phase, string $candidate) use ($path, $before, &$changed): void {
            if ($phase === 'before-delete' && $candidate === $path) {
                $changed = touch($path, $before['mtime'] + 120, $before['atime']);
            }
        };

        $counts = $storage->reconcile(true);

        $this->assertTrue($changed);
        $this->assertSame($before['mtime'] + 120, $this->freshRevalidationStat($path)['mtime']);
        $this->assertSame($receipt->sha256, hash_file('sha256', $path), 'A metadata-only change must still fail revalidation.');
        $this->assertRetainedAfterRevalidation($path, $counts, $storage);
    }

    public function test_revalidation_checks_integrity_when_same_size_content_changes(): void
    {
        $receipt = $this->agedReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $before = $this->freshRevalidationStat($path);
        $bytes = file_get_contents($path);
        $bytes[20] = chr(ord($bytes[20]) ^ 1);
        $storage = $this->reconciliationProbe();
        $changed = false;
        $storage->checkpoint = function (string $phase, string $candidate) use ($path, $before, $bytes, &$changed): void {
            if ($phase === 'before-delete' && $candidate === $path) {
                $changed = file_put_contents($path, $bytes) === strlen($bytes)
                    && touch($path, $before['mtime'], $before['atime']);
            }
        };

        $counts = $storage->reconcile(true);

        $this->assertTrue($changed);
        $after = $this->freshRevalidationStat($path);
        $this->assertSame($before['size'], $after['size']);
        $this->assertSame($before['mtime'], $after['mtime']);
        $this->assertNotSame($receipt->sha256, hash_file('sha256', $path));
        $this->assertRetainedAfterRevalidation($path, $counts, $storage);
        if ($this->stableRevalidationFields($before) !== $this->stableRevalidationFields($after)) {
            $this->markTestSkipped('BLOCKED / NOT PASSED for equal-stat integrity: this filesystem changed ctime or another stable field after the real content write; retain/no-unlink assertions passed.');
        }
        $this->assertSame($this->stableRevalidationFields($before), $this->stableRevalidationFields($after), 'Equal stable metadata must not bypass the final content hash check.');
    }

    public function test_revalidation_retains_candidate_when_receipt_public_identity_changes(): void
    {
        $receipt = $this->agedReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $publicId = (string) Str::uuid();
        $storage = $this->reconciliationProbe();
        $changes = 0;
        $storage->checkpoint = function (string $phase, string $candidate) use ($path, $publicId, &$changes): void {
            if ($phase === 'before-delete' && $candidate === $path) {
                $this->updateReceiptJournal($path, ['public_id' => $publicId]);
                $changes++;
            }
        };

        $counts = $storage->reconcile(true);

        $this->assertSame(1, $changes);
        $this->assertSame($publicId, json_decode(file_get_contents($path.'.json'), true, flags: JSON_THROW_ON_ERROR)['public_id']);
        $this->assertSame($receipt->sha256, hash_file('sha256', $path));
        $this->assertCount(2, $storage->readIdentities[$path], 'A valid replacement receipt still requires initial and fresh PNG integrity inspections.');
        $this->assertRetainedAfterRevalidation($path, $counts, $storage);
    }

    public function test_revalidation_rejects_replacement_with_distinct_filesystem_identity(): void
    {
        $receipt = $this->agedReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $replacement = $path.'.replacement';
        $original = $path.'.original';
        $before = $this->freshRevalidationStat($path);
        $this->assertSame($receipt->sizeBytes, file_put_contents($replacement, file_get_contents($path)));
        $this->assertTrue(chmod($replacement, $before['mode'] & 0777));
        $this->assertTrue(touch($replacement, $before['mtime'], $before['atime']));
        $different = $this->freshRevalidationStat($replacement);
        if ($before['dev'] === 0 || $before['ino'] === 0 || $different['dev'] === 0 || $different['ino'] === 0
            || [$before['dev'], $before['ino']] === [$different['dev'], $different['ino']]) {
            $this->markTestSkipped('BLOCKED / NOT PASSED: this platform does not expose distinct nonzero dev/inode identities for the two real fixture files.');
        }
        $storage = $this->reconciliationProbe();
        $replaced = false;
        $storage->checkpoint = function (string $phase, string $candidate) use ($path, $replacement, $original, &$replaced): void {
            if ($phase === 'before-delete' && $candidate === $path) {
                // All three paths are generated within this case-owned private assets directory.
                $replaced = rename($path, $original) && rename($replacement, $path);
            }
        };

        $counts = $storage->reconcile(true);

        $this->assertTrue($replaced);
        $after = $this->freshRevalidationStat($path);
        $this->assertNotSame([$before['dev'], $before['ino']], [$after['dev'], $after['ino']]);
        $this->assertSame($before['size'], $after['size']);
        $this->assertSame($before['mtime'], $after['mtime']);
        $this->assertSame($receipt->sha256, hash_file('sha256', $path));
        $this->assertSame($receipt->sha256, hash_file('sha256', $original));
        $this->assertRetainedAfterRevalidation($path, $counts, $storage);
    }

    private function assertAtimeOnlyRevalidation(string $suffix): void
    {
        $receipt = $this->agedReceipt();
        $path = $receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey;
        $target = $path.$suffix;
        $before = $this->freshRevalidationStat($target);
        $this->assertTrue(touch($target, $before['mtime'], time() - 14400));
        $storage = $this->reconciliationProbe();
        $observed = [];
        $held = [];
        $storage->checkpoint = function (string $phase, string $candidate) use ($path, $target, $storage, &$observed, &$held): void {
            if ($phase !== 'before-delete' || $candidate !== $path) {
                return;
            }
            $initial = $storage->readIdentities[$target][0];
            $observed['initial'] = $initial;
            $observed['read_length'] = strlen(file_get_contents($target));
            $after = $this->freshRevalidationStat($target);
            $observed['atime_change_source'] = 'actual file read';
            if ($after['atime'] === $initial['atime']) {
                // Windows/filesystems may disable or defer last-access updates. The actual
                // read above still runs; explicitly change only the test-owned access time.
                $observed['atime_change_source'] = 'explicit fixture touch after actual read (access-time updates deferred)';
                $observed['touch_succeeded'] = touch($target, $initial['mtime'], $initial['atime'] + 3600);
                $after = $this->freshRevalidationStat($target);
            }
            $observed['after'] = $after;
            $held['before-delete'] = $this->candidateLeaseIsHeld($path.'.lock');
        };
        $storage->beforeRemoval = function (string $removed) use ($path, &$held): void {
            $held[$removed === $path ? 'unlink-png' : 'cleanup-receipt'] = $this->candidateLeaseIsHeld($path.'.lock');
        };

        $counts = $storage->reconcile(true);

        $this->assertArrayHasKey('initial', $observed, 'The fixture must reach fresh revalidation.');
        $this->assertSame($observed['initial']['size'], $observed['read_length']);
        $this->assertTrue($observed['touch_succeeded'] ?? true);
        $this->assertNotSame($observed['initial']['atime'], $observed['after']['atime'], $observed['atime_change_source']);
        if (isset($observed['touch_succeeded'])
            && $this->stableRevalidationFields($observed['initial']) !== $this->stableRevalidationFields($observed['after'])) {
            $this->assertRetainedAfterRevalidation($path, $counts, $storage);
            $this->markTestSkipped('BLOCKED / NOT PASSED for atime-only mutation: this filesystem deferred read access-time updates and its explicit fixture touch changed another stable field; retain/no-unlink assertions passed.');
        }
        $this->assertSame($this->stableRevalidationFields($observed['initial']), $this->stableRevalidationFields($observed['after']), $observed['atime_change_source']);
        $this->assertSame(1, $counts['removed'], json_encode($counts, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $counts['unresolved']);
        $this->assertSame(0, $counts['corrupt']);
        $this->assertSame(0, $counts['partial_error']);
        $this->assertSame([$path, $path.'.json'], $storage->deletions);
        $this->assertSame(['before-delete' => true, 'unlink-png' => true, 'cleanup-receipt' => true], $held, 'The same candidate lease must stay held through receipt cleanup.');
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path.'.json');
        $this->assertCandidateLeaseReleased($path.'.lock');
        $this->assertNoGlobalReconciliationArtifact();
    }

    private function assertRetainedAfterRevalidation(string $path, array $counts, ReconciliationStorageProbe $storage): void
    {
        $this->assertSame(1, $counts['unresolved'], json_encode($counts, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $counts['removed']);
        $this->assertSame(0, $counts['corrupt'], 'A change after an initially valid observation is unresolved, not proven corruption.');
        $this->assertSame(0, $counts['partial_error']);
        $this->assertSame([], $storage->deletions, 'Fresh revalidation must reject the change before any unlink.');
        $this->assertFileExists($path);
        $this->assertFileExists($path.'.json');
        $this->assertCandidateLeaseReleased($path.'.lock');
        $this->assertNoGlobalReconciliationArtifact();
    }

    private function freshRevalidationStat(string $path): array
    {
        clearstatcache(true, $path);

        return lstat($path);
    }

    /** Independent test expectation: access time/numeric aliases never define stable identity. */
    private function stableRevalidationFields(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'mtime', 'ctime', 'blksize', 'blocks']));
    }

    private function candidateLeaseIsHeld(string $path): bool
    {
        $competitor = fopen($path, 'r+b');
        if (! is_resource($competitor)) {
            return false;
        }
        try {
            $wouldBlock = 0;
            $acquired = flock($competitor, LOCK_EX | LOCK_NB, $wouldBlock);

            return ! $acquired && $wouldBlock === 1;
        } finally {
            fclose($competitor);
        }
    }

    private function independentReconciliationMysql(): PDO
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real MySQL contention requires the guarded isolated MySQL gate.');
        }
        $this->assertSame('1', getenv('ALLOW_MYSQL_FOUNDATION_TESTS'));
        // runDatabaseMigrations already verified this exact disposable server.
        $pdo = new PDO('mysql:host=127.0.0.1;port=33084;dbname=school_dss_foundation_test;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $this->assertNotSame(DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(), $pdo->query('SELECT CONNECTION_ID()')->fetchColumn());

        return $pdo;
    }

    private function insertMysqlReference(PDO $pdo, SignatureAsset $asset): void
    {
        $asset->setCreatedAt(now());
        $asset->setUpdatedAt(now());
        $values = $asset->getAttributes();
        $sql = 'INSERT INTO signature_assets (`'.implode('`, `', array_keys($values)).'`) VALUES ('
            .implode(', ', array_fill(0, count($values), '?')).')';
        $pdo->prepare($sql)->execute(array_values($values));
    }

    private function agedReceipt(bool $release = true): OwnedSignatureFile
    {
        $receipt = $this->privateSignatureReceipt();
        if ($release) {
            $this->signatureStorage->preserve($receipt);
        }
        $this->updateReceiptJournal($receipt->root.DIRECTORY_SEPARATOR.$receipt->storageKey, ['created_at' => time() - 7200]);

        return $receipt;
    }

    private function reconciliationProbe(): ReconciliationStorageProbe
    {
        $this->assertInstanceOf(ReconciliationStorageProbe::class, $this->signatureStorage);

        return $this->signatureStorage;
    }

    /** Keep real primary statements and transactions, recording only the pinned primary calls. */
    private function withRecordingPrimary(Closure $operation, bool $failReferenceQuery = false): array
    {
        $connection = DB::connection();
        $original = $connection->getPdo();
        $recording = new ReconciliationRecordingPdo($original, $failReferenceQuery);
        $connection->setPdo($recording);
        try {
            return [$operation(), $recording];
        } finally {
            $connection->setPdo($original);
        }
    }

    /** Fixtures alone own cleanup; reconciliation must never access this retired pathname. */
    private function createStaleArtifact(string $path, string $outside, string $kind): Closure
    {
        if ($kind === 'directory') {
            $this->assertTrue(mkdir($path));
            file_put_contents($path.DIRECTORY_SEPARATOR.'sentinel', 'stale directory bytes');

            return static fn () => null;
        }
        if (in_array($kind, ['symlink', 'directory reparse'], true)) {
            if ($kind === 'directory reparse') {
                $this->assertTrue(mkdir($outside));
                file_put_contents($outside.DIRECTORY_SEPARATOR.'sentinel', 'outside sentinel');
            } else {
                file_put_contents($outside, 'outside sentinel');
            }
            if (PHP_OS_FAMILY === 'Windows') {
                $this->signatureFixturePowershell(
                    '$item=New-Item -ItemType $env:SIGNATURE_FIXTURE_TYPE -Path $env:SIGNATURE_FIXTURE_ALIAS -Target $env:SIGNATURE_FIXTURE_TARGET; if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0){throw "Fixture is not a reparse point"}',
                    ['SIGNATURE_FIXTURE_ALIAS' => $path, 'SIGNATURE_FIXTURE_TARGET' => $outside,
                        'SIGNATURE_FIXTURE_TYPE' => $kind === 'directory reparse' ? 'Junction' : 'SymbolicLink'],
                    true,
                );
            } else {
                $this->assertTrue(symlink($outside, $path));
            }

            return function () use ($path, $kind): void {
                $this->assertTrue(PHP_OS_FAMILY === 'Windows' && $kind === 'directory reparse' ? rmdir($path) : unlink($path));
            };
        }
        file_put_contents($path, 'stale coordination bytes');
        if ($kind !== 'unreadable') {
            return static fn () => null;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertTrue(chmod($path, 0000));

            return fn () => $this->assertTrue(chmod($path, 0600));
        }
        $sddl = $this->signatureFixturePowershell(
            '$acl=Get-Acl -LiteralPath $env:SIGNATURE_FIXTURE_PATH; $original=$acl.Sddl; $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User; $deny=New-Object Security.AccessControl.FileSystemAccessRule($sid,"ReadData","Deny"); $acl.AddAccessRule($deny); Set-Acl -LiteralPath $env:SIGNATURE_FIXTURE_PATH -AclObject $acl; [Console]::Write($original)',
            ['SIGNATURE_FIXTURE_PATH' => $path], true,
        );

        return function () use ($path, $sddl): void {
            $this->signatureFixturePowershell(
                '$acl=New-Object Security.AccessControl.FileSecurity; $acl.SetSecurityDescriptorSddlForm($env:SIGNATURE_FIXTURE_SDDL); Set-Acl -LiteralPath $env:SIGNATURE_FIXTURE_PATH -AclObject $acl',
                ['SIGNATURE_FIXTURE_PATH' => $path, 'SIGNATURE_FIXTURE_SDDL' => $sddl],
            );
        };
    }

    private function updateReceiptJournal(string $path, array $changes): void
    {
        $record = json_decode(file_get_contents($path.'.json'), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($path.'.json', json_encode(array_replace($record, $changes), JSON_THROW_ON_ERROR));
    }

    private function snapshotReconciliationRows(): array
    {
        return array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)->all(), ['signature_assets', 'audit_logs']);
    }

    private function snapshotReconciliationFiles(): array
    {
        clearstatcache();
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->signatureTestDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen($this->signatureTestDirectory) + 1);
            $entries[$relative] = ['type' => $entry->getType(), 'mode' => $entry->getPerms(), 'mtime' => $entry->getMTime()];
            if ($entry->isFile()) {
                $entries[$relative]['size'] = $entry->getSize();
                // A request can hold an empty lease exclusively on Windows.
                $entries[$relative]['sha256'] = $entry->getSize() === 0 ? hash('sha256', '') : hash_file('sha256', $path);
            }
        }
        ksort($entries);

        return $entries;
    }

    private function assertNoGlobalReconciliationArtifact(): void
    {
        $this->assertNotContains('.reconcile.lock', scandir(config('signature_assets.storage_root')));
    }

    private function assertCandidateLeaseReleased(string $path): void
    {
        $this->assertFileExists($path);
        $mutex = fopen($path, 'r+b');
        $this->assertIsResource($mutex);
        try {
            $this->assertTrue(flock($mutex, LOCK_EX | LOCK_NB), 'Reconciliation must release its handle even when it fails.');
        } finally {
            fclose($mutex);
        }
    }
}

/** Exercises the real storage adapter with deterministic filesystem schedules and observations. */
class ReconciliationStorageProbe extends SignatureAssetStorage
{
    /** Opt-in, test-local observations; never include signature bytes or hashes. */
    public ?array $diagnostics = null;
    private mixed $diagnosticLease = null;
    private ?string $diagnosticLeasePath = null;
    public ?Closure $checkpoint = null;
    public ?Closure $beforeRemoval = null;
    public ?array $candidateKeys = null;
    public ?string $unreadable = null;
    public array $stats = [];
    public array $opens = [];
    public array $reads = [];
    public array $readIdentities = [];
    public array $deletions = [];
    public int $locks = 0;

    public function resetObservations(): void
    {
        $this->stats = $this->opens = $this->reads = $this->readIdentities = $this->deletions = [];
        $this->locks = 0;
    }

    protected function reconciliationCandidates(string $root): iterable
    {
        return $this->candidateKeys ?? parent::reconciliationCandidates($root);
    }

    protected function reconciliationCheckpoint(string $phase, string $path): void
    {
        $this->diagnostic('checkpoint', ['phase' => $phase, 'path' => $path]);
        ($this->checkpoint ?? static fn () => null)($phase, $path);
    }

    protected function candidateStat(string $path): array|false
    {
        $this->stats[] = $path;

        $stat = parent::candidateStat($path);
        $this->diagnostic('stat', [
            'path' => $path, 'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? null,
            'stat' => $stat,
            'held_lease' => $path === $this->diagnosticLeasePath && is_resource($this->diagnosticLease)
                ? fstat($this->diagnosticLease) : null,
        ]);

        return $stat;
    }

    protected function openCandidateFile(string $path, string $mode): mixed
    {
        $this->opens[] = ['path' => $path, 'mode' => $mode];

        $handle = parent::openCandidateFile($path, $mode);
        if ($this->diagnostics !== null && $mode === 'r+b') {
            $this->diagnosticLease = $handle;
            $this->diagnosticLeasePath = $path;
        }
        $this->diagnostic('open', ['path' => $path, 'mode' => $mode, 'opened' => is_resource($handle)]);

        return $handle;
    }

    protected function readCandidateFile(string $path, int $limit): ?array
    {
        $this->reads[] = $path;

        $result = $path === $this->unreadable ? null : parent::readCandidateFile($path, $limit);
        if ($result !== null) {
            $this->readIdentities[$path][] = $result['identity'];
        }
        $this->diagnostic('read', [
            'path' => $path, 'available' => $result !== null,
            'identity' => $result['identity'] ?? null, 'length' => isset($result['bytes']) ? strlen($result['bytes']) : null,
        ]);

        return $result;
    }

    protected function lockCandidate(mixed $lease, int &$wouldBlock): bool
    {
        $this->locks++;

        $locked = parent::lockCandidate($lease, $wouldBlock);
        $this->diagnostic('lock', [
            'path' => $this->diagnosticLeasePath, 'locked' => $locked, 'would_block' => $wouldBlock,
            'held_identity' => is_resource($lease) ? fstat($lease) : null,
        ]);

        return $locked;
    }

    protected function removeCandidateFile(string $path): bool
    {
        ($this->beforeRemoval ?? static fn () => null)($path);
        $this->deletions[] = $path;

        $removed = parent::removeCandidateFile($path);
        $this->diagnostic('unlink', ['path' => $path, 'removed' => $removed]);

        return $removed;
    }

    protected function reconciliationTime(): int
    {
        $now = parent::reconciliationTime();
        $this->diagnostic('clock', ['unix_seconds' => $now]);

        return $now;
    }

    private function diagnostic(string $event, array $data): void
    {
        if ($this->diagnostics !== null) {
            $this->diagnostics[] = ['event' => $event] + $data;
        }
    }
}

/** Forwards to this case's real primary PDO; no other connection is opened. */
class ReconciliationRecordingPdo extends PDO
{
    public array $queries = [];
    public array $commands = [];
    public array $events = [];

    public function __construct(private readonly PDO $primary, private readonly bool $failReferenceQuery) {}

    public function getAttribute(int $attribute): mixed
    {
        return $this->primary->getAttribute($attribute);
    }

    public function inTransaction(): bool
    {
        $active = $this->primary->inTransaction();
        $this->events[] = ['operation' => 'inTransaction', 'result' => $active];

        return $active;
    }

    public function beginTransaction(): bool
    {
        try {
            $result = $this->primary->beginTransaction();
            $this->events[] = ['operation' => 'beginTransaction', 'result' => $result];

            return $result;
        } catch (\Throwable $exception) {
            $this->events[] = ['operation' => 'beginTransaction', 'error' => $exception::class, 'code' => $exception->getCode()];
            throw $exception;
        }
    }

    public function rollBack(): bool
    {
        try {
            $result = $this->primary->rollBack();
            $this->events[] = ['operation' => 'rollBack', 'result' => $result];

            return $result;
        } catch (\Throwable $exception) {
            $this->events[] = ['operation' => 'rollBack', 'error' => $exception::class, 'code' => $exception->getCode()];
            throw $exception;
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;

        try {
            // Let MySQL capture session settings and begin its transaction first.
            if ($this->failReferenceQuery && str_starts_with($query, 'SELECT id, storage_key, public_id FROM signature_assets')) {
                throw new \PDOException('Simulated authoritative reference query failure');
            }
            $statement = $this->primary->prepare($query, $options);
            $this->events[] = ['operation' => 'prepare', 'query' => $query, 'success' => $statement !== false];

            return $statement;
        } catch (\Throwable $exception) {
            $this->events[] = ['operation' => 'prepare', 'query' => $query, 'error' => $exception::class, 'code' => $exception->getCode()];
            throw $exception;
        }
    }

    public function exec(string $statement): int|false
    {
        $this->commands[] = $statement;

        try {
            $result = $this->primary->exec($statement);
            $this->events[] = ['operation' => 'exec', 'statement' => $statement, 'result' => $result];

            return $result;
        } catch (\Throwable $exception) {
            $this->events[] = ['operation' => 'exec', 'statement' => $statement, 'error' => $exception::class, 'code' => $exception->getCode()];
            throw $exception;
        }
    }
}
