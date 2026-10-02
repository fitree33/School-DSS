<?php

namespace Tests\Feature;

use App\Models\DocumentSignature;
use App\Models\DocumentVersion;
use App\Models\ProjectSignatureSlot;
use App\Models\SignatureAsset;
use App\Models\SignaturePlacement;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Feature\Concerns\BuildsDocumentVersions;
use Tests\Feature\Concerns\BuildsSignatureAssetRows;
use Tests\Feature\Concerns\GuardsSignatureAssetMysql;
use Tests\TestCase;

class DocumentSigningSchemaTest extends TestCase
{
    use BuildsDocumentVersions;
    use BuildsSignatureAssetRows;
    use GuardsSignatureAssetMysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardSignatureAssetDatabase();
        // Additive migrations only: never rebuild the disposable MySQL database.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        });
    }

    public function test_evidence_schema_has_unique_identities_and_restrict_foreign_keys(): void
    {
        $this->assertSame([
            'id', 'public_id', 'project_id', 'project_document_id', 'source_document_version_id',
            'signed_document_version_id', 'project_signature_slot_id', 'assignment_revision',
            'signer_id', 'signature_asset_id', 'page', 'x', 'y', 'width', 'height',
            'before_sha256', 'after_sha256', 'signed_at', 'idempotency_key', 'placement_fingerprint',
        ], Schema::getColumnListing('document_signatures'));
        $indexes = collect(Schema::getIndexes('document_signatures'));
        foreach ([
            ['public_id'], ['source_document_version_id', 'project_signature_slot_id'],
            ['signed_document_version_id'], ['signer_id', 'idempotency_key'],
        ] as $columns) {
            $this->assertTrue($indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === $columns));
        }
        $foreignKeys = Schema::getForeignKeys('document_signatures');
        $this->assertCount(7, $foreignKeys);
        foreach ($foreignKeys as $key) {
            $this->assertSame('restrict', strtolower($key['on_delete']));
            $this->assertSame('restrict', strtolower($key['on_update']));
        }
    }

    public function test_database_rejects_invalid_rectangles_hashes_and_inconsistent_derivations(): void
    {
        DB::beginTransaction();
        $row = $this->evidenceRow();
        $count = DB::table('document_signatures')->count();
        $other = $this->versionedDocument(['mime_type' => 'application/pdf']);
        $invalid = [
            ['page' => 0], ['assignment_revision' => 0], ['assignment_revision' => 2],
            ['x' => -0.1], ['width' => 0], ['x' => 0.9, 'width' => 0.2], ['y' => 0.95, 'height' => 0.1],
            ['before_sha256' => str_repeat('b', 64)], ['after_sha256' => str_repeat('a', 64)],
            ['placement_fingerprint' => str_repeat('A', 64)], ['placement_fingerprint' => str_repeat('f', 63)],
            ['source_document_version_id' => $row['signed_document_version_id']],
            ['source_document_version_id' => $other->id], ['project_document_id' => $other->project_document_id],
            ['signer_id' => User::factory()->create()->id], ['signature_asset_id' => $this->persistedSignatureAsset()->id],
            ['project_id' => $other->document->project_id], ['project_signature_slot_id' => 999999999],
        ];
        if (DB::getDriverName() === 'sqlite') {
            $invalid = [...$invalid, ['page' => 1.5], ['assignment_revision' => 1.5], ['x' => 'invalid']];
        }
        foreach ($invalid as $overrides) {
            $this->assertRejected(fn () => DB::table('document_signatures')->insert(array_replace($row, $overrides)));
            $this->assertSame($count, DB::table('document_signatures')->count());
        }
        DB::table('document_signatures')->insert($row);
        $this->assertSame($count + 1, DB::table('document_signatures')->count());
    }

    public function test_evidence_is_immutable_in_models_queries_upserts_and_replace(): void
    {
        DB::beginTransaction();
        $signature = DocumentSignature::query()->create($this->evidenceRow())->refresh();
        $before = $signature->getRawOriginal();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $signature->fill(['page' => 2])->save() : $signature->delete();
                $this->fail('The model accepted an evidence mutation.');
            } catch (LogicException) {
                $this->assertSame($before, $signature->fresh()->getRawOriginal());
            }
        }
        $this->assertRejected(fn () => DB::table('document_signatures')->where('id', $signature->id)->update(['page' => 2]));
        $this->assertRejected(fn () => DB::table('document_signatures')->where('id', $signature->id)->delete());
        $this->assertRejected(fn () => DB::table('document_signatures')->upsert([$before], ['public_id'], ['page']));
        $columns = implode(', ', array_keys($before));
        $placeholders = implode(', ', array_fill(0, count($before), '?'));
        $replace = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
        $this->assertRejected(fn () => DB::insert("{$replace} INTO document_signatures ({$columns}) VALUES ({$placeholders})", array_values($before)));
        $this->assertSame($before, $signature->fresh()->getRawOriginal());
        $this->assertArrayNotHasKey('idempotency_key', $signature->toArray());
        $this->assertArrayNotHasKey('placement_fingerprint', $signature->toArray());
    }

    public function test_duplicate_source_slot_signed_version_and_signer_key_are_rejected(): void
    {
        DB::beginTransaction();
        $row = $this->evidenceRow();
        DB::table('document_signatures')->insert($row);
        $count = DB::table('document_signatures')->count();
        foreach ([
            ['public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()],
            ['public_id' => (string) Str::uuid()],
        ] as $overrides) {
            $this->assertRejected(fn () => DB::table('document_signatures')->insert(array_replace($row, $overrides)));
        }
        $second = $this->evidenceRow(User::query()->findOrFail($row['signer_id']));
        $second['idempotency_key'] = $row['idempotency_key'];
        $this->assertRejected(fn () => DB::table('document_signatures')->insert($second));
        $this->assertSame($count, DB::table('document_signatures')->count());
    }

    public function test_later_retirement_and_reassignment_preserve_historical_evidence(): void
    {
        DB::beginTransaction();
        $signature = DocumentSignature::query()->create($this->evidenceRow())->refresh();
        $before = $signature->getRawOriginal();
        $asset = SignatureAsset::query()->findOrFail($signature->signature_asset_id);
        $asset->fill($this->retirementValues($asset))->save();
        ProjectSignatureSlot::query()->whereKey($signature->project_signature_slot_id)->update([
            'assigned_user_id' => User::factory()->create()->id, 'assignment_revision' => 2,
        ]);
        $this->assertSame($before, $signature->refresh()->getRawOriginal());
        $this->assertRejected(fn () => DB::table('users')->where('id', $signature->signer_id)->delete());
    }

    public function test_sqlite_migration_preserves_populated_baselines_drafts_and_existing_protection(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite table rebuild compatibility uses an isolated in-memory schema.');
        }
        $source = $this->versionedDocument(['mime_type' => 'application/pdf']);
        $before = $source->getRawOriginal();
        $owner = $source->document->project->owner ?? User::query()->findOrFail($source->document->project->user_id);
        $asset = $this->persistedSignatureAsset($owner);
        $slot = $this->slot($source, $owner);
        $draft = SignaturePlacement::query()->create([
            'document_version_id' => $source->id, 'project_signature_slot_id' => $slot->id,
            'signature_asset_id' => $asset->id, 'assignment_revision' => 1, 'page' => 1,
            'x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.1,
            'created_by' => $owner->id, 'updated_by' => $owner->id,
        ])->refresh();
        $draftBefore = $draft->getRawOriginal();
        $migration = require database_path('migrations/2026_09_29_000100_add_document_signing.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('document_signatures'));
        $this->assertSame($before, $source->refresh()->getRawOriginal());
        $this->assertSame($draftBefore, $draft->refresh()->getRawOriginal());
        $migration->up();
        $this->assertSame($before, $source->refresh()->getRawOriginal());
        $this->assertSame($draftBefore, $draft->refresh()->getRawOriginal());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertRejected(fn () => DB::table('document_versions')->where('id', $source->id)->update(['original_name' => 'changed']));
        $this->assertRejected(fn () => DB::table('project_documents')->where('id', $source->project_document_id)->update(['path' => 'changed']));
        $signature = DocumentSignature::query()->create($this->evidenceRow());
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void { $statements[] = $query->sql; });
        try {
            $migration->down();
            $this->fail('Rollback discarded signed history.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back populated document signing', $exception->getMessage());
        }
        foreach ($statements as $statement) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(DROP|ALTER|CREATE|RENAME|TRUNCATE)\b/i', $statement);
        }
        $this->assertNotNull($signature->fresh());
    }

    public function test_mysql_empty_signing_migration_round_trip_preserves_baselines_and_enforces_checks(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the guarded disposable MySQL instance.');
        }
        // Never discard existing evidence, even in the disposable database.
        if (DB::table('document_signatures')->exists()
            || DB::table('document_versions')->where('created_via', 'signature')->exists()) {
            $this->markTestSkipped('Populated signing history must be preserved.');
        }
        $versions = DB::table('document_versions')->orderBy('id')->get()->toJson();
        $drafts = DB::table('signature_placements')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_09_29_000100_add_document_signing.php');
        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('document_signatures'));
            $this->assertSame($versions, DB::table('document_versions')->orderBy('id')->get()->toJson());
            $this->assertSame($drafts, DB::table('signature_placements')->orderBy('id')->get()->toJson());
        } finally {
            if (! Schema::hasTable('document_signatures')) {
                $migration->up();
            }
        }
        $this->assertSame($versions, DB::table('document_versions')->orderBy('id')->get()->toJson());
        $this->assertSame($drafts, DB::table('signature_placements')->orderBy('id')->get()->toJson());
        $checks = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())->where('table_name', 'document_signatures')
            ->where('constraint_type', 'CHECK')->selectRaw('ENFORCED AS enforced')->get()->pluck('enforced');
        $this->assertCount(4, $checks);
        $this->assertTrue($checks->every(fn ($value) => $value === 'YES'));
        $this->assertCount(3, DB::table('information_schema.triggers')
            ->where('trigger_schema', DB::getDatabaseName())->where('event_object_table', 'document_signatures')->get());
    }

    private function evidenceRow(?User $owner = null): array
    {
        $source = $this->versionedDocument(['mime_type' => 'application/pdf', 'original_name' => 'original.pdf']);
        $owner ??= User::query()->findOrFail($source->document->project->user_id);
        $asset = $this->persistedSignatureAsset($owner);
        $slot = $this->slot($source, $owner);
        $signed = DocumentVersion::query()->create(array_replace($source->getAttributes(), [
            'id' => null, 'public_id' => (string) Str::uuid(), 'revision_no' => 2,
            'created_via' => 'signature', 'created_by' => $owner->id,
            'storage_disk' => 'signed-documents', 'storage_path' => Str::uuid().'.pdf', 'sha256' => str_repeat('b', 64),
        ]));

        return [
            'public_id' => (string) Str::uuid(), 'project_id' => $source->document->project_id,
            'project_document_id' => $source->project_document_id, 'source_document_version_id' => $source->id,
            'signed_document_version_id' => $signed->id, 'project_signature_slot_id' => $slot->id,
            'assignment_revision' => 1, 'signer_id' => $owner->id, 'signature_asset_id' => $asset->id,
            'page' => 1, 'x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.1,
            'before_sha256' => $source->sha256, 'after_sha256' => $signed->sha256,
            'signed_at' => now()->format('Y-m-d H:i:s.u'), 'idempotency_key' => (string) Str::uuid(),
            'placement_fingerprint' => str_repeat('f', 64),
        ];
    }

    private function slot(DocumentVersion $source, User $owner): ProjectSignatureSlot
    {
        return ProjectSignatureSlot::query()->create([
            'project_id' => $source->document->project_id, 'slot_code' => 'project_proposer', 'slot_no' => 1,
            'assigned_user_id' => $owner->id, 'assignment_revision' => 1, 'assigned_by' => $owner->id, 'assigned_at' => now(),
        ]);
    }

    private function assertRejected(callable $write): void
    {
        try {
            $write();
            $this->fail('The database accepted an invalid or immutable evidence mutation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
