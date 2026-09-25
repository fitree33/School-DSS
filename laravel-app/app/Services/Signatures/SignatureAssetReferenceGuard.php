<?php

namespace App\Services\Signatures;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Keeps an absence proof alive only while its caller owns the candidate lease.
 * No Laravel query/transaction API is used after pinning: those APIs may reconnect
 * or replay a query. The callback must only perform the guarded filesystem work.
 */
class SignatureAssetReferenceGuard
{
    public function __construct(
        private readonly ?DatabaseManager $database = null,
        private readonly ?int $lockWaitSeconds = null,
    ) {}

    /** Nonlocking observation only; an absent result never authorizes deletion. */
    public function observe(string $storageKey, string $publicId): array
    {
        try {
            [$manager, $name, $connection, $pdo, $driver] = $this->pin();
            $rows = [];
            foreach (['storage_key' => $storageKey, 'public_id' => $publicId] as $column => $value) {
                $this->assertPinned($manager, $name, $connection, $pdo);
                if ($pdo->inTransaction()) {
                    throw new RuntimeException('Observation cannot reuse an ambient transaction.');
                }
                $rows = array_merge($rows, $this->probe($pdo, $driver, $column, $value, false));
            }
            $this->assertPinned($manager, $name, $connection, $pdo);

            return $this->referenceResult($rows, $storageKey, $publicId);
        } catch (Throwable) {
            return $this->result('ambiguous/unresolved');
        }
    }

    /**
     * The callback is invoked at most once, with both absence locks still held.
     * It must return an outcome even after a partial filesystem mutation; throwing
     * cannot establish whether any bytes were removed and is reported uncertain.
     */
    public function protect(string $storageKey, string $publicId, Closure $whenAbsent): array
    {
        $connection = $pdo = null;
        $driver = null;
        $started = $configured = $nextIsolationPending = $callbackEntered = false;
        $settings = [];
        $outcome = $this->result('ambiguous/unresolved');
        $discard = false;

        try {
            [$manager, $name, $connection, $pdo, $driver] = $this->pin();
            $wait = $this->lockWaitSeconds ?? (int) config('signature_assets.reconciliation_lock_wait_seconds', 2);
            if ($wait < 1 || $wait > 60) {
                throw new RuntimeException('Invalid reconciliation lock wait.');
            }

            if ($driver === 'mysql') {
                $settings = $this->select($pdo, 'SELECT @@SESSION.innodb_lock_wait_timeout AS innodb_wait, @@SESSION.lock_wait_timeout AS metadata_wait');
                if (count($settings) !== 1 || ! ctype_digit((string) ($settings[0]['innodb_wait'] ?? ''))
                    || ! ctype_digit((string) ($settings[0]['metadata_wait'] ?? ''))) {
                    throw new RuntimeException('Unable to capture primary session settings.');
                }
                $configured = true;
                $this->exec($pdo, 'SET SESSION innodb_lock_wait_timeout = '.$wait);
                $this->exec($pdo, 'SET SESSION lock_wait_timeout = '.$wait);
                $this->exec($pdo, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $nextIsolationPending = true;
                $this->assertPinned($manager, $name, $connection, $pdo);
                if (! $pdo->beginTransaction()) {
                    throw new RuntimeException('Unable to start primary transaction.');
                }
                $nextIsolationPending = false;
            } else {
                // PDO SQLite inTransaction() does not track a raw BEGIN on all
                // supported PHP versions. Track ownership explicitly and end it
                // with SQL too. An existing raw transaction makes BEGIN fail.
                $this->exec($pdo, 'BEGIN IMMEDIATE');
            }
            $started = true;

            $rows = [];
            foreach (['storage_key' => $storageKey, 'public_id' => $publicId] as $column => $value) {
                $this->assertLive($manager, $name, $connection, $pdo, $driver, $started);
                $rows = array_merge($rows, $this->probe($pdo, $driver, $column, $value, true));
            }
            $this->assertLive($manager, $name, $connection, $pdo, $driver, $started);
            $outcome = $this->referenceResult($rows, $storageKey, $publicId);
            if ($outcome['state'] === 'aged-unreferenced-candidate') {
                $callbackEntered = true;
                $outcome = array_replace($this->result('ambiguous/unresolved'), $whenAbsent());
            }
            $this->assertLive($manager, $name, $connection, $pdo, $driver, $started);
        } catch (Throwable) {
            $outcome = $callbackEntered
                ? array_replace($outcome, ['partial_error' => true])
                : $this->result('ambiguous/unresolved');
        } finally {
            if ($pdo instanceof PDO) {
                try {
                    if ($started) {
                        if ($driver === 'sqlite') {
                            $this->exec($pdo, 'ROLLBACK');
                        } elseif ($pdo->inTransaction() && ! $pdo->rollBack()) {
                            throw new RuntimeException('Unable to release primary transaction.');
                        }
                    }
                    if ($configured) {
                        // Restore only the captured PDO; never acquire a new PDO
                        // to clean up an uncertain or replaced connection.
                        $this->exec($pdo, 'SET SESSION innodb_lock_wait_timeout = '.(int) $settings[0]['innodb_wait']);
                        $this->exec($pdo, 'SET SESSION lock_wait_timeout = '.(int) $settings[0]['metadata_wait']);
                    }
                    if (isset($manager, $name) && $connection instanceof Connection) {
                        $this->assertPinned($manager, $name, $connection, $pdo);
                    }
                } catch (Throwable) {
                    $discard = true;
                    $outcome = $callbackEntered
                        ? array_replace($outcome, ['partial_error' => true])
                        : $this->result('ambiguous/unresolved');
                }
                if (($discard || $nextIsolationPending) && $connection instanceof Connection && $connection->getRawPdo() === $pdo) {
                    // An unusable session must not leak locks/settings into later
                    // work. This does not reconnect or replay the callback.
                    $connection->disconnect();
                }
            }
        }

        return $outcome;
    }

    private function pin(): array
    {
        $manager = $this->database ?? DB::getFacadeRoot();
        if (! $manager instanceof DatabaseManager) {
            throw new RuntimeException('Authoritative database manager unavailable.');
        }
        $name = $manager->getDefaultConnection();
        $connection = $manager->connection($name);
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Ambient application transaction.');
        }
        // getPdo() is the write/primary PDO. Resolve it exactly once, before any
        // proof; subsequent identity checks use getRawPdo() exclusively.
        $pdo = $connection->getPdo();
        if (! $pdo instanceof PDO || $pdo->inTransaction()) {
            throw new RuntimeException('Ambient or unavailable primary transaction.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($driver, ['mysql', 'sqlite'], true) || $driver !== $connection->getDriverName()) {
            throw new RuntimeException('Unsupported or uncertain primary driver.');
        }
        $this->assertPinned($manager, $name, $connection, $pdo);

        return [$manager, $name, $connection, $pdo, $driver];
    }

    private function assertPinned(DatabaseManager $manager, string $name, Connection $connection, PDO $pdo): void
    {
        if ($manager->getDefaultConnection() !== $name
            || ($manager->getConnections()[$name] ?? null) !== $connection
            || $connection->getRawPdo() !== $pdo || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('Authoritative connection changed.');
        }
    }

    private function assertLive(DatabaseManager $manager, string $name, Connection $connection, PDO $pdo, string $driver, bool $started): void
    {
        $this->assertPinned($manager, $name, $connection, $pdo);
        if (! $started || ($driver === 'mysql' && ! $pdo->inTransaction())) {
            throw new RuntimeException('Authoritative transaction no longer active.');
        }
    }

    private function probe(PDO $pdo, string $driver, string $column, string $value, bool $locking): array
    {
        $index = $locking && $driver === 'mysql' ? ' FORCE INDEX (signature_assets_'.$column.'_unique)' : '';
        $lock = $locking && $driver === 'mysql' ? ' FOR SHARE' : '';

        return $this->select($pdo, 'SELECT id, storage_key, public_id FROM signature_assets'.$index.' WHERE '.$column.' = :identity'.$lock, ['identity' => $value]);
    }

    private function select(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false || ! $statement->execute($parameters)) {
            throw new RuntimeException('Authoritative query failed.');
        }
        try {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            if (! $statement->closeCursor()) {
                throw new RuntimeException('Authoritative cursor cleanup failed.');
            }
        }
    }

    private function exec(PDO $pdo, string $sql): void
    {
        if ($pdo->exec($sql) === false) {
            throw new RuntimeException('Authoritative statement failed.');
        }
    }

    private function referenceResult(array $rows, string $storageKey, string $publicId): array
    {
        $result = $this->result($rows === [] ? 'aged-unreferenced-candidate' : 'referenced');
        foreach ($rows as $row) {
            if (($row['storage_key'] ?? null) !== $storageKey || ($row['public_id'] ?? null) !== $publicId) {
                $result['identity_mismatch'] = true;
            }
        }

        return $result;
    }

    private function result(string $state): array
    {
        return ['state' => $state, 'identity_mismatch' => false, 'removed' => false, 'partial_error' => false];
    }
}
