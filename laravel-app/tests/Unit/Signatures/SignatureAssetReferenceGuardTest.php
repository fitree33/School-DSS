<?php

namespace Tests\Unit\Signatures;

use App\Services\Signatures\SignatureAssetReferenceGuard;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SignatureAssetReferenceGuardTest extends TestCase
{
    private const KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png';

    private const PUBLIC_ID = '00000000-0000-4000-8000-000000000001';

    public function test_absence_requires_both_fixed_order_identity_probes_inside_one_transaction(): void
    {
        [$guard, $pdo, $connection] = $this->fixture();
        $calls = 0;
        $result = $guard->protect(self::KEY, self::PUBLIC_ID, function () use ($pdo, &$calls): array {
            $calls++;
            $this->assertTrue($pdo->transactionOpen);
            $this->assertSame(['storage_key', 'public_id'], array_column($pdo->probes, 'column'));

            return ['state' => 'safe-orphan', 'removed' => true];
        });

        $this->assertSame(1, $calls);
        $this->assertSame(['state' => 'safe-orphan', 'identity_mismatch' => false, 'removed' => true, 'partial_error' => false], $result);
        $this->assertFalse($pdo->transactionOpen);
        $this->assertSame(1, $pdo->begins);
        $this->assertSame(1, $pdo->rollbacks);
        $this->assertSame(1, $connection->primaryResolutions);
        foreach ($pdo->probes as $probe) {
            $this->assertStringContainsString('FORCE INDEX (signature_assets_'.$probe['column'].'_unique)', $probe['sql']);
            $this->assertStringEndsWith('FOR SHARE', $probe['sql']);
            $this->assertStringNotContainsString('status', $probe['sql']);
            $this->assertStringNotContainsString('SKIP LOCKED', $probe['sql']);
            $this->assertStringNotContainsString('FOR UPDATE', $probe['sql']);
        }
        $this->assertSame([self::KEY, self::PUBLIC_ID], array_column($pdo->probes, 'identity'));
        $this->assertContains('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $pdo->commands);
        $this->assertContains('SET SESSION innodb_lock_wait_timeout = 2', $pdo->commands);
        $this->assertContains('SET SESSION lock_wait_timeout = 2', $pdo->commands);
        $this->assertSame(['SET SESSION innodb_lock_wait_timeout = 50', 'SET SESSION lock_wait_timeout = 31536000'], array_slice($pdo->commands, -2));
    }

    #[DataProvider('references')]
    public function test_either_identity_match_retains_including_mismatches(string $identity, bool $mismatch): void
    {
        [$guard, $pdo] = $this->fixture();
        $row = ['id' => 7, 'storage_key' => self::KEY, 'public_id' => self::PUBLIC_ID];
        if ($mismatch) {
            $row[$identity === 'storage_key' ? 'public_id' : 'storage_key'] = 'different-identity';
        }
        $pdo->rows[$identity] = [$row];

        $result = $guard->protect(self::KEY, self::PUBLIC_ID, fn () => $this->fail('A referenced identity cannot authorize deletion.'));

        $this->assertSame('referenced', $result['state']);
        $this->assertSame($mismatch, $result['identity_mismatch']);
        $this->assertFalse($result['removed']);
        $this->assertSame(['storage_key', 'public_id'], array_column($pdo->probes, 'column'));
        $this->assertSame(1, $pdo->rollbacks);
    }

    public static function references(): array
    {
        return [
            'key match' => ['storage_key', false],
            'public ID match' => ['public_id', false],
            'key collision' => ['storage_key', true],
            'public ID collision' => ['public_id', true],
        ];
    }

    public function test_matching_both_identities_retains_without_invoking_the_callback(): void
    {
        [$guard, $pdo] = $this->fixture();
        $row = ['id' => 7, 'storage_key' => self::KEY, 'public_id' => self::PUBLIC_ID];
        $pdo->rows = ['storage_key' => [$row], 'public_id' => [$row]];
        $calls = 0;

        $result = $guard->protect(self::KEY, self::PUBLIC_ID, function () use (&$calls): array {
            $calls++;

            return ['state' => 'safe-orphan', 'removed' => true];
        });

        $this->assertSame(0, $calls);
        $this->assertSame('referenced', $result['state']);
        $this->assertFalse($result['identity_mismatch']);
        $this->assertFalse($result['removed']);
        $this->assertFalse($result['partial_error']);
        $this->assertSame(['storage_key', 'public_id'], array_column($pdo->probes, 'column'));
        $this->assertSame(1, $pdo->rollbacks);
        $this->assertFalse($pdo->transactionOpen);
    }

    #[DataProvider('transactionDrivers')]
    public function test_each_candidate_gets_a_fresh_transaction_and_both_identity_probes(string $driver): void
    {
        [$guard, $pdo, $connection] = $this->fixture($driver);
        $candidates = [
            [self::KEY, self::PUBLIC_ID],
            [str_repeat('b', 64).'.png', '00000000-0000-4000-8000-000000000002'],
        ];
        $callbacks = [];

        foreach ($candidates as $index => [$key, $publicId]) {
            $result = $guard->protect($key, $publicId, function () use ($pdo, &$callbacks): array {
                $callbacks[] = [$pdo->transactionOpen, $pdo->begins, $pdo->rollbacks];

                return ['state' => 'disappeared'];
            });

            $this->assertSame('disappeared', $result['state']);
            $this->assertFalse($result['partial_error']);
            $this->assertFalse($pdo->transactionOpen);
            $this->assertSame($index + 1, $pdo->begins);
            $this->assertSame($index + 1, $pdo->rollbacks);
        }

        $this->assertSame([[true, 1, 0], [true, 2, 1]], $callbacks);
        $this->assertSame(['storage_key', 'public_id', 'storage_key', 'public_id'], array_column($pdo->probes, 'column'));
        $this->assertSame(array_merge(...$candidates), array_column($pdo->probes, 'identity'));
        $this->assertSame(2, $connection->primaryResolutions);
    }

    public static function transactionDrivers(): array
    {
        return [['sqlite'], ['mysql']];
    }

    public function test_uses_pinned_primary_and_never_resolves_read_pdo_or_reconnects(): void
    {
        [$guard, $pdo, $connection, $manager] = $this->fixture();
        $pdo->rows['public_id'] = [['id' => 1, 'storage_key' => self::KEY, 'public_id' => self::PUBLIC_ID]];
        $connection->setReadPdo(static fn () => throw new RuntimeException('Replica must not be resolved.'));
        $connection->setReconnector(static fn () => throw new RuntimeException('Reconnect must not occur.'));

        $this->assertSame('referenced', $guard->protect(self::KEY, self::PUBLIC_ID, fn () => $this->fail())['state']);
        $this->assertSame(1, $connection->primaryResolutions);
        $this->assertSame(1, $manager->connectionResolutions);
        $this->assertSame(0, $connection->disconnects);
    }

    #[DataProvider('ambientTransactions')]
    public function test_ambient_transaction_is_rejected_without_touching_it(string $kind): void
    {
        [$guard, $pdo, $connection] = $this->fixture($kind === 'raw SQLite' ? 'sqlite' : 'mysql');
        if ($kind === 'Laravel') {
            $connection->ambientLevel = 1;
        } else {
            $pdo->transactionOpen = true;
        }

        $result = $guard->protect(self::KEY, self::PUBLIC_ID, fn () => $this->fail('Ambient state cannot authorize deletion.'));

        $this->assertSame('ambiguous/unresolved', $result['state']);
        $this->assertSame([], $pdo->probes);
        $this->assertSame(0, $pdo->rollbacks);
        $this->assertSame(0, $connection->disconnects);
        if ($kind !== 'Laravel') {
            $this->assertTrue($pdo->transactionOpen, 'The guard must never roll back someone else’s transaction.');
        }
    }

    public static function ambientTransactions(): array
    {
        return [['Laravel'], ['PDO'], ['raw SQLite']];
    }

    #[DataProvider('uncertainties')]
    public function test_timeout_deadlock_query_error_and_uncertainty_abort_without_callback_replay(string $kind): void
    {
        [$guard, $pdo, $connection] = $this->fixture();
        $pdo->onProbe = function (string $column) use ($pdo, $kind): void {
            if ($column !== 'public_id') {
                return;
            }
            if ($kind === 'deadlock') {
                $pdo->transactionOpen = false; // InnoDB may roll back the whole transaction.
            }
            throw new PDOException($kind, match ($kind) {
                'timeout' => 1205,
                'deadlock' => 1213,
                'connection lost' => 2013,
                default => 1,
            });
        };
        $calls = 0;

        $result = $guard->protect(self::KEY, self::PUBLIC_ID, function () use (&$calls): array {
            $calls++;

            return ['state' => 'safe-orphan', 'removed' => true];
        });

        $this->assertSame(0, $calls);
        $this->assertSame('ambiguous/unresolved', $result['state']);
        $this->assertFalse($result['removed']);
        $this->assertFalse($result['partial_error']);
        $this->assertFalse($pdo->transactionOpen);
        $this->assertSame(1, $pdo->begins);
        $this->assertSame(2, count($pdo->probes), 'A failed proof must not restart either probe.');
        $this->assertSame($kind === 'deadlock' ? 0 : 1, $pdo->rollbacks);
        $this->assertSame(1, $connection->primaryResolutions);
    }

    public static function uncertainties(): array
    {
        return [['timeout'], ['deadlock'], ['query error'], ['connection lost']];
    }

    #[DataProvider('connectionChanges')]
    public function test_connection_change_invalidates_authorization(string $change): void
    {
        [$guard, $pdo, $connection, $manager] = $this->fixture();
        $pdo->onProbe = function (string $column) use ($connection, $manager, $change): void {
            if ($column !== 'public_id') {
                return;
            }
            match ($change) {
                'PDO' => $connection->setPdo(new ReferenceGuardPdo('mysql')),
                'manager connection' => $manager->replace(new ReferenceGuardConnection(new ReferenceGuardPdo('mysql'))),
                'default name' => $manager->defaultName = 'replacement',
                'lazy reconnect' => $connection->setPdo(static fn () => throw new RuntimeException('Must not resolve replacement.')),
            };
        };

        $result = $guard->protect(self::KEY, self::PUBLIC_ID, fn () => $this->fail('Changed connection cannot authorize deletion.'));

        $this->assertSame('ambiguous/unresolved', $result['state']);
        $this->assertFalse($result['removed']);
        $this->assertSame(1, $connection->primaryResolutions);
        $this->assertFalse($pdo->transactionOpen, 'The original pinned transaction still needs cleanup.');
    }

    public static function connectionChanges(): array
    {
        return [['PDO'], ['manager connection'], ['default name'], ['lazy reconnect']];
    }

    public function test_disappearing_mysql_transaction_invalidates_absence(): void
    {
        [$guard, $pdo] = $this->fixture();
        $pdo->onProbe = function (string $column) use ($pdo): void {
            if ($column === 'public_id') {
                $pdo->transactionOpen = false;
            }
        };

        $this->assertSame('ambiguous/unresolved', $guard->protect(self::KEY, self::PUBLIC_ID, fn () => $this->fail())['state']);
    }

    public function test_sqlite_tracks_raw_begin_immediate_and_releases_with_sql(): void
    {
        [$guard, $pdo] = $this->fixture('sqlite');
        $calls = 0;
        $result = $guard->protect(self::KEY, self::PUBLIC_ID, function () use ($pdo, &$calls): array {
            $calls++;
            $this->assertTrue($pdo->transactionOpen);
            $this->assertFalse($pdo->inTransaction(), 'Model PHP versions that do not expose raw SQLite transactions.');

            return ['state' => 'disappeared'];
        });

        $this->assertSame('disappeared', $result['state']);
        $this->assertSame(1, $calls);
        $this->assertSame(['BEGIN IMMEDIATE', 'ROLLBACK'], $pdo->commands);
        $this->assertFalse($pdo->transactionOpen);
        foreach ($pdo->probes as $probe) {
            $this->assertStringNotContainsString('FOR SHARE', $probe['sql']);
        }
    }

    public function test_sqlite_busy_does_not_create_a_proof_or_roll_back_an_unowned_transaction(): void
    {
        [$guard, $pdo] = $this->fixture('sqlite');
        $pdo->onExec = static function (string $sql): void {
            if ($sql === 'BEGIN IMMEDIATE') {
                throw new PDOException('SQLITE_BUSY', 5);
            }
        };

        $result = $guard->protect(self::KEY, self::PUBLIC_ID, fn () => $this->fail());

        $this->assertSame('ambiguous/unresolved', $result['state']);
        $this->assertSame([], $pdo->probes);
        $this->assertSame(0, $pdo->rollbacks);
    }

    public function test_sqlite_second_probe_error_retains_and_releases_the_owned_transaction(): void
    {
        [$guard, $pdo, $connection] = $this->fixture('sqlite');
        $pdo->onProbe = static function (string $column): void {
            if ($column === 'public_id') {
                throw new PDOException('SQLite identity query failed');
            }
        };
        $calls = 0;

        $result = $guard->protect(self::KEY, self::PUBLIC_ID, function () use (&$calls): array {
            $calls++;

            return ['state' => 'safe-orphan', 'removed' => true];
        });

        $this->assertSame(0, $calls);
        $this->assertSame('ambiguous/unresolved', $result['state']);
        $this->assertFalse($result['removed']);
        $this->assertFalse($result['partial_error']);
        $this->assertSame(['storage_key', 'public_id'], array_column($pdo->probes, 'column'));
        $this->assertSame(['BEGIN IMMEDIATE', 'ROLLBACK'], $pdo->commands);
        $this->assertSame(1, $pdo->begins);
        $this->assertSame(1, $pdo->rollbacks);
        $this->assertSame(1, $connection->primaryResolutions);
        $this->assertFalse($pdo->transactionOpen);
    }

    public function test_lock_free_observation_never_opens_a_transaction_or_certifies_absence(): void
    {
        [$guard, $pdo, $connection] = $this->fixture();

        $result = $guard->observe(self::KEY, self::PUBLIC_ID);

        $this->assertSame('aged-unreferenced-candidate', $result['state']);
        $this->assertFalse($result['removed']);
        $this->assertSame([], $pdo->commands);
        $this->assertSame(0, $pdo->begins);
        $this->assertSame(0, $pdo->rollbacks);
        $this->assertSame(1, $connection->primaryResolutions);
        $this->assertSame(['storage_key', 'public_id'], array_column($pdo->probes, 'column'));
        foreach ($pdo->probes as $probe) {
            $this->assertStringNotContainsString('FOR SHARE', $probe['sql']);
            $this->assertStringNotContainsString('FOR UPDATE', $probe['sql']);
        }
    }

    public function test_observation_error_reports_unknown(): void
    {
        [$guard, $pdo] = $this->fixture();
        $pdo->onProbe = static fn () => throw new PDOException('Primary unavailable');

        $this->assertSame('ambiguous/unresolved', $guard->observe(self::KEY, self::PUBLIC_ID)['state']);
        $this->assertSame(0, $pdo->begins);
    }

    public function test_observation_rejects_an_ambient_snapshot(): void
    {
        [$guard, $pdo] = $this->fixture();
        $pdo->transactionOpen = true;

        $this->assertSame('ambiguous/unresolved', $guard->observe(self::KEY, self::PUBLIC_ID)['state']);
        $this->assertSame([], $pdo->probes);
        $this->assertTrue($pdo->transactionOpen);
    }

    public function test_callback_exception_is_not_replayed_and_reports_uncertain_partial_work(): void
    {
        [$guard, $pdo] = $this->fixture();
        $calls = 0;
        $result = $guard->protect(self::KEY, self::PUBLIC_ID, function () use (&$calls): array {
            $calls++;
            throw new RuntimeException('Filesystem outcome uncertain');
        });

        $this->assertSame(1, $calls);
        $this->assertTrue($result['partial_error']);
        $this->assertSame(1, $pdo->rollbacks);
        $this->assertFalse($pdo->transactionOpen);
    }

    #[DataProvider('releaseFailures')]
    public function test_release_failure_after_mutation_preserves_removed_result_and_discards_session(string $failure): void
    {
        [$guard, $pdo, $connection] = $this->fixture();
        if ($failure === 'rollback') {
            $pdo->onRollback = static fn () => throw new PDOException('Release failed');
        } else {
            $pdo->onExec = static function (string $sql): void {
                if ($sql === 'SET SESSION innodb_lock_wait_timeout = 50') {
                    throw new PDOException('Session restoration failed');
                }
            };
        }
        $calls = 0;
        $result = $guard->protect(self::KEY, self::PUBLIC_ID, function () use (&$calls): array {
            $calls++;

            return ['state' => 'safe-orphan', 'removed' => true];
        });

        $this->assertSame(1, $calls);
        $this->assertTrue($result['removed'], 'DB rollback cannot restore an unlinked file.');
        $this->assertTrue($result['partial_error']);
        $this->assertSame(1, $connection->disconnects);
        $this->assertNull($connection->getRawPdo());
        $this->assertSame(1, $pdo->begins);
    }

    public static function releaseFailures(): array
    {
        return [['rollback'], ['session restoration']];
    }

    public function test_connection_change_after_callback_work_preserves_removed_and_partial_evidence(): void
    {
        [$guard, $pdo, $connection] = $this->fixture();
        $replacement = new ReferenceGuardPdo('mysql');
        $calls = 0;

        $result = $guard->protect(self::KEY, self::PUBLIC_ID, function () use ($connection, $replacement, &$calls): array {
            $calls++;
            $connection->setPdo($replacement);

            return ['state' => 'safe-orphan', 'removed' => true];
        });

        $this->assertSame(1, $calls, 'Connection uncertainty must never replay completed filesystem work.');
        $this->assertTrue($result['removed'], 'A connection change cannot restore an unlinked file.');
        $this->assertTrue($result['partial_error']);
        $this->assertSame(1, $pdo->begins);
        $this->assertSame(1, $pdo->rollbacks);
        $this->assertFalse($pdo->transactionOpen, 'Cleanup must release the original pinned transaction.');
        $this->assertSame(1, $connection->primaryResolutions);
        $this->assertSame($replacement, $connection->getRawPdo());
        $this->assertSame(0, $connection->disconnects, 'Cleanup must not disconnect a replacement session.');
        $this->assertSame([], $replacement->commands);
        $this->assertSame([], $replacement->probes);
        $this->assertSame(0, $replacement->begins);
    }

    public function test_partial_filesystem_result_is_preserved(): void
    {
        [$guard] = $this->fixture();
        $result = $guard->protect(self::KEY, self::PUBLIC_ID, static fn (): array => ['state' => 'safe-orphan', 'removed' => true, 'partial_error' => true]);

        $this->assertTrue($result['removed']);
        $this->assertTrue($result['partial_error']);
    }

    private function fixture(string $driver = 'mysql'): array
    {
        $pdo = new ReferenceGuardPdo($driver);
        $connection = new ReferenceGuardConnection($pdo);
        $manager = new ReferenceGuardDatabaseManager($connection);

        return [new SignatureAssetReferenceGuard($manager, 2), $pdo, $connection, $manager];
    }
}

/** Scripted PDO doubles: this suite never opens any database connection. */
class ReferenceGuardPdo extends PDO
{
    public bool $transactionOpen = false;
    public int $begins = 0;
    public int $rollbacks = 0;
    public array $commands = [];
    public array $probes = [];
    public array $rows = [];
    public ?Closure $onProbe = null;
    public ?Closure $onExec = null;
    public ?Closure $onRollback = null;

    public function __construct(public readonly string $driver) {}

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? $this->driver : null;
    }

    public function inTransaction(): bool
    {
        return $this->driver === 'mysql' && $this->transactionOpen;
    }

    public function beginTransaction(): bool
    {
        $this->begins++;
        $this->transactionOpen = true;

        return true;
    }

    public function rollBack(): bool
    {
        $this->rollbacks++;
        ($this->onRollback ?? static fn () => null)();
        $this->transactionOpen = false;

        return true;
    }

    public function exec(string $statement): int|false
    {
        $this->commands[] = $statement;
        ($this->onExec ?? static fn () => null)($statement);
        if ($statement === 'BEGIN IMMEDIATE') {
            if ($this->transactionOpen) {
                throw new PDOException('Cannot start a transaction within a transaction');
            }
            $this->begins++;
            $this->transactionOpen = true;
        } elseif ($statement === 'ROLLBACK') {
            $this->rollbacks++;
            $this->transactionOpen = false;
        }

        return 0;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ReferenceGuardStatement(function (array $parameters) use ($query): array {
            if (str_starts_with($query, 'SELECT @@SESSION.')) {
                return [['innodb_wait' => 50, 'metadata_wait' => 31536000]];
            }
            $column = str_contains($query, 'WHERE storage_key') ? 'storage_key' : 'public_id';
            $this->probes[] = ['column' => $column, 'sql' => $query, 'identity' => $parameters['identity']];
            ($this->onProbe ?? static fn () => null)($column);

            return $this->rows[$column] ?? [];
        });
    }
}

class ReferenceGuardStatement extends PDOStatement
{
    private array $rows = [];

    public function __construct(private readonly Closure $executeCallback) {}

    public function execute(?array $params = null): bool
    {
        $this->rows = ($this->executeCallback)($params ?? []);

        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}

class ReferenceGuardConnection extends Connection
{
    public int $primaryResolutions = 0;
    public int $disconnects = 0;
    public int $ambientLevel = 0;

    public function __construct(ReferenceGuardPdo $pdo)
    {
        parent::__construct($pdo, 'unit-no-database', '', ['driver' => $pdo->driver]);
    }

    public function getPdo()
    {
        $this->primaryResolutions++;

        return parent::getPdo();
    }

    public function transactionLevel()
    {
        return $this->ambientLevel;
    }

    public function disconnect()
    {
        $this->disconnects++;
        parent::disconnect();
    }
}

class ReferenceGuardDatabaseManager extends DatabaseManager
{
    public string $defaultName = 'authoritative';
    public int $connectionResolutions = 0;

    public function __construct(Connection $connection)
    {
        $this->connections = ['authoritative' => $connection];
    }

    public function getDefaultConnection()
    {
        return $this->defaultName;
    }

    public function connection($name = null)
    {
        $this->connectionResolutions++;

        return $this->connections[$name ?? $this->defaultName];
    }

    public function replace(Connection $connection): void
    {
        $this->connections['authoritative'] = $connection;
    }
}
