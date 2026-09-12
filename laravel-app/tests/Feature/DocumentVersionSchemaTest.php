<?php

namespace Tests\Feature;

use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsDocumentVersions;
use Tests\TestCase;

class DocumentVersionSchemaTest extends TestCase
{
    use BuildsDocumentVersions;
    use RefreshDatabase;

    public function test_exact_columns_indexes_foreign_keys_and_named_checks(): void
    {
        $columns = collect(Schema::getColumns('document_versions'))->keyBy('name');
        $this->assertSame([
            'id', 'public_id', 'project_document_id', 'revision_no', 'created_via',
            'storage_disk', 'storage_path', 'original_name', 'mime_type', 'size_bytes',
            'sha256', 'integrity_basis', 'created_by', 'verified_at', 'created_at',
        ], $columns->keys()->all());
        foreach ($columns as $name => $column) {
            // SQLite reports a rowid primary key nullable even though NULL allocates a new integer.
            if ($name !== 'id') {
                $this->assertSame($name === 'created_by', $column['nullable'], $name);
            }
            if ($name === 'created_by' && DB::getDriverName() === 'sqlite') {
                // SQLite exposes the explicit DEFAULT NULL clause as SQL text.
                $this->assertSame('NULL', $column['default'], $name);
            } else {
                $this->assertNull($column['default'], $name);
            }
        }

        $indexes = collect(Schema::getIndexes('document_versions'));
        foreach ([
            ['public_id'], ['project_document_id', 'revision_no'],
        ] as $uniqueColumns) {
            $this->assertTrue($indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === $uniqueColumns));
        }
        foreach ([
            'document_versions_sha256_idx' => ['sha256'],
            'document_versions_storage_locator_idx' => ['storage_disk', 'storage_path'],
            'document_versions_created_by_idx' => ['created_by'],
        ] as $name => $indexedColumns) {
            $this->assertTrue($indexes->contains(fn ($index) => $index['name'] === $name
                && ! $index['unique'] && $index['columns'] === $indexedColumns));
        }
        $foreignKeys = collect(Schema::getForeignKeys('document_versions'));
        foreach (['project_document_id' => 'project_documents', 'created_by' => 'users'] as $column => $table) {
            $key = $foreignKeys->first(fn ($key) => $key['columns'] === [$column]);
            $this->assertNotNull($key);
            $this->assertSame($table, $key['foreign_table']);
            $this->assertSame(['id'], $key['foreign_columns']);
            $this->assertSame('restrict', strtolower($key['on_delete']));
            $this->assertSame('restrict', strtolower($key['on_update']));
        }
        $ddl = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'table')->where('name', 'document_versions')->value('sql')
            : array_values((array) DB::selectOne('SHOW CREATE TABLE document_versions'))[1];
        foreach ([
            'public_id_unique', 'document_revision_unique', 'document_fk', 'created_by_fk',
            'revision_positive_check', 'size_nonnegative_check', 'sha256_format_check',
            'created_via_check', 'integrity_basis_check', 'creator_check',
        ] as $constraint) {
            $this->assertStringContainsString('document_versions_'.$constraint, $ddl);
        }
    }

    public function test_invalid_revisions_sizes_hashes_enums_and_creators_are_rejected_by_database(): void
    {
        $version = $this->versionedDocument();
        $row = $version->getRawOriginal();
        unset($row['id']);
        $actor = User::factory()->create();
        foreach ([
            ['revision_no' => 0], ['revision_no' => -1], ['size_bytes' => -1],
            ['sha256' => str_repeat('a', 63)], ['sha256' => str_repeat('A', 64)],
            ['sha256' => str_repeat('g', 64)], ['sha256' => str_repeat('a', 63)."\n"],
            ['created_via' => 'invented'], ['created_via' => 'BACKFILL'],
            ['integrity_basis' => 'invented'], ['integrity_basis' => 'OBSERVED_SHA256'],
            ['created_by' => $actor->id],
            ['created_via' => DocumentVersionCreatedVia::LegacyUpload->value],
            ['created_via' => DocumentVersionCreatedVia::PhaseFiveConfirm->value],
            ['project_document_id' => 99999999],
            ['created_via' => DocumentVersionCreatedVia::LegacyUpload->value, 'created_by' => 99999999],
        ] as $invalid) {
            try {
                DB::table('document_versions')->insert(array_replace($row, [
                    'public_id' => (string) Str::uuid(), 'revision_no' => 2,
                ], $invalid));
                $this->fail('Accepted invalid version: '.json_encode($invalid));
            } catch (QueryException) {
                $this->assertDatabaseCount('document_versions', 1);
            }
        }
    }

    public function test_sqlite_revisions_and_sizes_require_stored_integers(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite affinity needs explicit stored-type checks.');
        }

        $version = $this->versionedDocument();
        $row = $version->getRawOriginal();
        unset($row['id']);
        foreach (['revision_no', 'size_bytes'] as $column) {
            foreach (['invalid', 1.5] as $value) {
                try {
                    DB::table('document_versions')->insert(array_replace($row, [
                        'public_id' => (string) Str::uuid(),
                        'revision_no' => 2,
                        $column => $value,
                    ]));
                    $this->fail("Accepted noninteger {$column}: ".json_encode($value));
                } catch (QueryException) {
                    $this->assertDatabaseCount('document_versions', 1);
                }
            }
        }
    }

    public function test_uniqueness_is_document_revision_and_uuid_not_hash_or_locator(): void
    {
        $version = $this->versionedDocument();
        $other = $this->versionedDocument([], [
            'sha256' => $version->sha256,
            'storage_disk' => $version->storage_disk,
            'storage_path' => $version->storage_path,
        ]);
        $this->assertDatabaseCount('document_versions', 2);
        $row = $version->getRawOriginal();
        unset($row['id']);
        foreach ([
            ['public_id' => (string) Str::uuid()],
            ['project_document_id' => $other->project_document_id, 'revision_no' => 2],
        ] as $duplicate) {
            try {
                DB::table('document_versions')->insert(array_replace($row, $duplicate));
                $this->fail('Accepted duplicate version identity.');
            } catch (QueryException) {
                $this->assertDatabaseCount('document_versions', 2);
            }
        }
    }

    public function test_model_relations_enums_timestamps_and_uuid_route_key(): void
    {
        $version = $this->versionedDocument();
        $this->assertTrue(Str::isUuid($version->public_id));
        $this->assertSame('public_id', $version->getRouteKeyName());
        $this->assertSame(DocumentVersionCreatedVia::Backfill, $version->created_via);
        $this->assertSame(DocumentVersionIntegrityBasis::RecordedSha256, $version->integrity_basis);
        $this->assertNotNull($version->created_at);
        $this->assertSame($version->id, $version->document->initialVersion->id);
        $this->assertSame([$version->id], $version->document->versions->modelKeys());
        $this->assertNull($version->creator);
        $this->assertArrayNotHasKey('storage_disk', $version->toArray());
        $this->assertArrayNotHasKey('storage_path', $version->toArray());
        $this->assertArrayNotHasKey('updated_at', $version->getAttributes());

        $actor = User::factory()->create();
        $uploaded = $this->versionedDocument([], [
            'created_via' => DocumentVersionCreatedVia::LegacyUpload,
            'created_by' => $actor->id,
            'integrity_basis' => DocumentVersionIntegrityBasis::ObservedSha256,
        ]);
        $actor->delete();
        $this->assertSame($actor->id, $uploaded->creator->id);
    }
}
