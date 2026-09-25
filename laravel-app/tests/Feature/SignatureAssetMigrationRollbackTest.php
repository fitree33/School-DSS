<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Concerns\BuildsSignatureAssetRows;
use Tests\Feature\Concerns\GuardsSignatureAssetMysql;
use Tests\TestCase;

class SignatureAssetMigrationRollbackTest extends TestCase
{
    use BuildsSignatureAssetRows;
    use GuardsSignatureAssetMysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardSignatureAssetDatabase();
        // MySQL DDL commits implicitly: do not share RefreshDatabase's transaction.
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertSame(0, DB::transactionLevel());
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
            DB::disconnect();
        });
    }

    public function test_populated_active_and_retired_registries_refuse_rollback_before_any_ddl(): void
    {
        $asset = $this->persistedSignatureAsset();

        foreach (['active', 'retired'] as $status) {
            if ($status === 'retired') {
                DB::table('signature_assets')->where('id', $asset->id)->update($this->retirementValues($asset));
            }
            $before = $asset->fresh()->getRawOriginal();
            $schema = $this->schemaSnapshot();
            $statements = [];
            DB::listen(function (QueryExecuted $query) use (&$statements): void {
                $statements[] = $query->sql;
            });
            try {
                $this->migration()->down();
                $this->fail('Rollback accepted populated '.$status.' signature assets.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Cannot roll back populated signature_assets; preserve the asset registry first.', $exception->getMessage());
            }
            foreach ($statements as $statement) {
                $this->assertDoesNotMatchRegularExpression('/^\s*(DROP|ALTER|CREATE|RENAME|TRUNCATE)\b/i', $statement);
            }
            $this->assertSame($schema, $this->schemaSnapshot());
            $this->assertSame($before, $asset->fresh()->getRawOriginal());

            foreach ([
                fn () => DB::table('signature_assets')->where('id', $asset->id)->update(['sha256' => str_repeat('b', 64)]),
                fn () => DB::table('signature_assets')->where('id', $asset->id)->delete(),
                fn () => DB::table('users')->where('id', $asset->owner_id)->delete(),
            ] as $mutation) {
                try {
                    $mutation();
                    $this->fail('Rollback refusal removed an asset protection.');
                } catch (QueryException) {
                    $this->assertSame($before, $asset->fresh()->getRawOriginal());
                }
            }
        }
    }

    public function test_empty_rollback_and_reapply_restore_schema_indexes_checks_and_triggers(): void
    {
        $before = $this->schemaSnapshot();
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('signature_assets'));
        $this->assertSame([], $this->triggers());
        $this->assertTrue(Schema::hasTable('document_versions'));
        $this->assertTrue(Schema::hasTable('project_signature_slots'));

        $this->migration()->up();
        $this->assertSame($before, $this->schemaSnapshot());
        $asset = $this->persistedSignatureAsset();
        $this->expectException(QueryException::class);
        DB::table('signature_assets')->where('id', $asset->id)->delete();
    }

    public function test_empty_partial_migration_can_be_removed_and_reapplied(): void
    {
        DB::unprepared('DROP TRIGGER signature_assets_protect_update');
        $this->migration()->down();
        $this->migration()->up();
        $asset = $this->persistedSignatureAsset();
        $this->expectException(QueryException::class);
        DB::table('signature_assets')->where('id', $asset->id)->update(['storage_key' => str_repeat('b', 64).'.png']);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_14_000100_create_signature_assets_table.php');
    }

    private function schemaSnapshot(): array
    {
        $ddl = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'table')->where('name', 'signature_assets')->value('sql')
            : array_values((array) DB::selectOne('SHOW CREATE TABLE signature_assets'))[1];

        return [Schema::getColumns('signature_assets'), Schema::getIndexes('signature_assets'), Schema::getForeignKeys('signature_assets'), $ddl, $this->triggers()];
    }

    private function triggers(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'signature_assets')
                ->orderBy('name')->pluck('sql', 'name')->all();
        }

        return DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('EVENT_OBJECT_TABLE', 'signature_assets')->orderBy('TRIGGER_NAME')
            ->pluck('ACTION_STATEMENT', 'TRIGGER_NAME')->all();
    }
}
