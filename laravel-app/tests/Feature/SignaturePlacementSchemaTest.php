<?php

namespace Tests\Feature;

use App\Models\ProjectSignatureSlot;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Concerns\BuildsDocumentVersions;
use Tests\Feature\Concerns\BuildsSignatureAssetRows;
use Tests\Feature\Concerns\GuardsSignatureAssetMysql;
use Tests\TestCase;

class SignaturePlacementSchemaTest extends TestCase
{
    use BuildsDocumentVersions;
    use BuildsSignatureAssetRows;
    use GuardsSignatureAssetMysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardSignatureAssetDatabase();
        // Rollback/reapply uses DDL, which cannot share a MySQL test transaction.
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

    public function test_schema_has_unique_version_slot_pair_child_indexes_and_restrict_foreign_keys(): void
    {
        $this->assertSame([
            'id', 'document_version_id', 'project_signature_slot_id', 'signature_asset_id',
            'assignment_revision', 'page', 'x', 'y', 'width', 'height',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ], Schema::getColumnListing('signature_placements'));
        $indexes = collect(Schema::getIndexes('signature_placements'));
        $this->assertTrue($indexes->contains(fn ($index) => $index['unique']
            && $index['columns'] === ['document_version_id', 'project_signature_slot_id']));
        foreach ([
            'slot' => 'project_signature_slot_id', 'asset' => 'signature_asset_id',
            'creator' => 'created_by', 'updater' => 'updated_by',
        ] as $name => $column) {
            $this->assertTrue($indexes->contains(fn ($index) => $index['name'] === 'signature_placements_'.$name.'_idx'
                && ! $index['unique'] && $index['columns'] === [$column]));
        }
        $keys = collect(Schema::getForeignKeys('signature_placements'));
        $this->assertCount(5, $keys);
        foreach ([
            'document_version_id' => 'document_versions', 'project_signature_slot_id' => 'project_signature_slots',
            'signature_asset_id' => 'signature_assets', 'created_by' => 'users', 'updated_by' => 'users',
        ] as $column => $table) {
            $key = $keys->first(fn ($key) => $key['columns'] === [$column]);
            $this->assertNotNull($key);
            $this->assertSame($table, $key['foreign_table']);
            $this->assertSame(['id'], $key['foreign_columns']);
            $this->assertSame('restrict', strtolower($key['on_delete']));
            $this->assertSame('restrict', strtolower($key['on_update']));
        }
    }

    public function test_database_rejects_invalid_page_revision_and_rectangles_on_insert_and_update(): void
    {
        $valid = $this->placementRow();
        $invalid = [
            ['page' => 0], ['page' => -1], ['page' => 4294967296],
            ['assignment_revision' => 0], ['assignment_revision' => 4294967296],
            ['x' => -0.00000001], ['y' => -0.00000001], ['x' => 1.00000001], ['y' => 1.00000001],
            ['width' => 0], ['height' => 0], ['width' => 1.00000001], ['height' => 1.00000001],
            ['x' => 0.9, 'width' => 0.2], ['y' => 0.95, 'height' => 0.1],
            ['x' => null], ['width' => null],
        ];
        if (DB::getDriverName() === 'sqlite') {
            $invalid = [...$invalid, ['page' => 1.5], ['assignment_revision' => 1.5], ['x' => 'invalid'], ['width' => 'invalid']];
        }
        foreach ($invalid as $overrides) {
            try {
                DB::table('signature_placements')->insert(array_replace($valid, $overrides));
                $this->fail('Database accepted invalid draft: '.json_encode($overrides));
            } catch (QueryException) {
                $this->assertDatabaseCount('signature_placements', 0);
            }
        }

        $id = DB::table('signature_placements')->insertGetId($valid);
        $before = DB::table('signature_placements')->first();
        foreach ($invalid as $overrides) {
            try {
                DB::table('signature_placements')->where('id', $id)->update($overrides);
                $this->fail('Database accepted invalid draft update: '.json_encode($overrides));
            } catch (QueryException) {
                $this->assertEquals($before, DB::table('signature_placements')->first());
            }
        }
        DB::table('signature_placements')->where('id', $id)->update([
            'page' => 4294967295, 'assignment_revision' => 4294967295,
            'x' => 0.99999999, 'y' => 0.99999999, 'width' => 0.00000001, 'height' => 0.00000001,
        ]);
        $this->assertDatabaseHas('signature_placements', ['id' => $id, 'page' => 4294967295]);
    }

    public function test_only_the_document_version_and_slot_pair_is_unique(): void
    {
        $row = $this->placementRow();
        $id = DB::table('signature_placements')->insertGetId($row);
        try {
            DB::table('signature_placements')->insert(array_replace($row, ['page' => 2]));
            $this->fail('Database accepted a second draft for the same version and slot.');
        } catch (QueryException) {
            $this->assertDatabaseCount('signature_placements', 1);
        }
        $slot = ProjectSignatureSlot::query()->findOrFail($row['project_signature_slot_id']);
        $secondSlot = ProjectSignatureSlot::query()->create([
            'project_id' => $slot->project_id, 'slot_code' => 'related_approver', 'slot_no' => 2,
            'assignment_revision' => 0,
        ]);
        $secondVersion = $this->versionedDocument(['project_id' => $slot->project_id]);
        DB::table('signature_placements')->insert(array_replace($row, ['project_signature_slot_id' => $secondSlot->id]));
        DB::table('signature_placements')->insert(array_replace($row, ['document_version_id' => $secondVersion->id]));
        $this->assertDatabaseCount('signature_placements', 3);
        DB::table('signature_placements')->where('id', $id)->update(['page' => 2, 'x' => 0.4]);
        $this->assertDatabaseHas('signature_placements', ['id' => $id, 'page' => 2, 'x' => 0.4]);
    }

    public function test_foreign_keys_reject_orphans_and_preserve_referenced_actors(): void
    {
        $row = $this->placementRow();
        foreach (['document_version_id', 'project_signature_slot_id', 'signature_asset_id', 'created_by', 'updated_by'] as $column) {
            try {
                DB::table('signature_placements')->insert(array_replace($row, [$column => 999999999]));
                $this->fail('Database accepted orphan '.$column.'.');
            } catch (QueryException) {
                $this->assertDatabaseCount('signature_placements', 0);
            }
        }
        DB::table('signature_placements')->insert($row);
        $before = DB::table('signature_placements')->first();
        foreach (['created_by', 'updated_by'] as $column) {
            foreach (['delete', 'update'] as $operation) {
                try {
                    $query = DB::table('users')->where('id', $row[$column]);
                    $operation === 'delete' ? $query->delete() : $query->update(['id' => 999999999]);
                    $this->fail('Database accepted referenced actor '.$operation.'.');
                } catch (QueryException) {
                    $this->assertDatabaseHas('users', ['id' => $row[$column]]);
                    $this->assertEquals($before, DB::table('signature_placements')->first());
                }
            }
        }
    }

    public function test_populated_rollback_is_refused_and_empty_rollback_reapply_preserves_schema(): void
    {
        $row = $this->placementRow();
        DB::table('signature_placements')->insert($row);
        $before = DB::table('signature_placements')->first();
        $schema = $this->schemaSnapshot();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        try {
            $this->migration()->down();
            $this->fail('Rollback discarded a placement draft.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot roll back populated signature_placements; preserve and export drafts first.', $exception->getMessage());
        }
        foreach ($statements as $statement) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(DROP|ALTER|CREATE|RENAME|TRUNCATE)\b/i', $statement);
        }
        $this->assertEquals($before, DB::table('signature_placements')->first());
        $this->assertSame($schema, $this->schemaSnapshot());
        DB::table('signature_placements')->delete();
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('signature_placements'));
        foreach (['document_versions', 'project_signature_slots', 'signature_assets'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->migration()->up();
        $this->assertSame($schema, $this->schemaSnapshot());
        DB::table('signature_placements')->insert($row);
        $this->assertDatabaseCount('signature_placements', 1);
    }

    private function placementRow(): array
    {
        // Schema fixtures need metadata only and never access PDF or signature files.
        $version = $this->versionedDocument();
        $owner = User::query()->findOrFail($version->document->project->user_id);
        $asset = $this->persistedSignatureAsset($owner);
        $slot = ProjectSignatureSlot::query()->create([
            'project_id' => $version->document->project_id, 'slot_code' => 'project_proposer', 'slot_no' => 1,
            'assigned_user_id' => $owner->id, 'assignment_revision' => 1,
            'assigned_by' => $owner->id, 'assigned_at' => now(),
        ]);

        return [
            'document_version_id' => $version->id, 'project_signature_slot_id' => $slot->id,
            'signature_asset_id' => $asset->id, 'assignment_revision' => 1, 'page' => 1,
            'x' => 0.1, 'y' => 0.2, 'width' => 0.2, 'height' => 0.1,
            'created_by' => User::factory()->create()->id, 'updated_by' => User::factory()->create()->id,
            'created_at' => '2026-09-27 00:00:00.000000', 'updated_at' => '2026-09-27 00:00:00.000000',
        ];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_27_000100_create_signature_placements_table.php');
    }

    private function schemaSnapshot(): array
    {
        $ddl = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'table')->where('name', 'signature_placements')->value('sql')
            : array_values((array) DB::selectOne('SHOW CREATE TABLE signature_placements'))[1];

        // MySQL's next AUTO_INCREMENT value is data state, not a schema change.
        $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);

        return [Schema::getColumns('signature_placements'), Schema::getIndexes('signature_placements'), Schema::getForeignKeys('signature_placements'), $ddl];
    }
}
