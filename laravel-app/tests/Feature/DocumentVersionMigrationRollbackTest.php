<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Concerns\BuildsDocumentVersions;
use Tests\TestCase;

class DocumentVersionMigrationRollbackTest extends TestCase
{
    use BuildsDocumentVersions;

    protected function setUp(): void
    {
        parent::setUp();

        // MySQL DDL implicitly commits: migration tests must not use the
        // transaction shared by RefreshDatabase. CreatesApplication has already
        // verified the isolated target before this fresh migration is allowed.
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertSame(0, DB::transactionLevel());

        $this->beforeApplicationDestroyed(function (): void {
            // The next test must rebuild its schema, including tests that use
            // RefreshDatabase. A rollback here would correctly refuse populated
            // fixtures, so let the next guarded migrate:fresh remove them.
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
            DB::disconnect();
        });
    }

    public function test_populated_rollback_refuses_before_any_ddl_and_preserves_every_protection(): void
    {
        $version = $this->versionedDocument();
        $before = $version->getRawOriginal();
        $columns = Schema::getColumns('document_versions');
        $indexes = Schema::getIndexes('document_versions');
        $foreignKeys = Schema::getForeignKeys('document_versions');
        $triggers = $this->triggers();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        try {
            $this->migration()->down();
            $this->fail('Rollback accepted a populated version registry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back populated document_versions', $exception->getMessage());
        }
        foreach ($statements as $statement) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(DROP|ALTER|CREATE|RENAME|TRUNCATE)\b/i', $statement);
        }
        $this->assertSame($columns, Schema::getColumns('document_versions'));
        $this->assertSame($indexes, Schema::getIndexes('document_versions'));
        $this->assertSame($foreignKeys, Schema::getForeignKeys('document_versions'));
        $this->assertSame($triggers, $this->triggers());
        $this->assertSame($before, $version->refresh()->getRawOriginal());
        foreach ([
            fn () => DB::table('document_versions')->where('id', $version->id)->update(['original_name' => 'changed']),
            fn () => DB::table('document_versions')->where('id', $version->id)->delete(),
            fn () => DB::table('project_documents')->where('id', $version->project_document_id)->update(['path' => 'changed']),
            fn () => DB::table('project_documents')->where('id', $version->project_document_id)->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Rollback refusal left a protection disabled.');
            } catch (QueryException) {
                $this->assertSame($before, $version->refresh()->getRawOriginal());
            }
        }
    }

    public function test_empty_rollback_and_reapply_restore_schema_and_triggers(): void
    {
        $triggers = $this->triggers();
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasTable('document_versions'));
        $this->assertSame([], $this->triggers());
        $this->assertTrue(Schema::hasTable('project_documents'));
        $this->assertTrue(Schema::hasTable('document_imports'));
        $this->assertTrue(Schema::hasTable('document_contents'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('document_versions'));
        $this->assertSame($triggers, $this->triggers());
    }

    public function test_empty_partial_schema_can_be_cleaned_and_reapplied(): void
    {
        DB::unprepared('DROP TRIGGER project_documents_protect_versioned_identity');
        DB::unprepared('DROP TRIGGER document_versions_no_update');
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('document_versions'));
        $this->migration()->up();
        $version = $this->versionedDocument();
        $this->expectException(QueryException::class);
        DB::table('document_versions')->where('id', $version->id)->update(['revision_no' => 2]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_11_000100_create_document_versions_table.php');
    }

    private function triggers(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'trigger')
                ->whereIn('tbl_name', ['document_versions', 'project_documents'])
                ->orderBy('name')->pluck('sql', 'name')->all();
        }

        return DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->whereIn('EVENT_OBJECT_TABLE', ['document_versions', 'project_documents'])
            ->orderBy('TRIGGER_NAME')->pluck('ACTION_STATEMENT', 'TRIGGER_NAME')->all();
    }
}
