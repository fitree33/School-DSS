<?php

namespace Tests\Feature;

use App\Enums\DocumentVersionCreatedVia;
use App\Models\DocumentVersion;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Concerns\BuildsDocumentVersions;
use Tests\TestCase;

class DocumentVersionImmutabilityTest extends TestCase
{
    use BuildsDocumentVersions;
    use RefreshDatabase;

    public function test_every_version_column_is_immutable_even_for_noop_raw_sql_updates(): void
    {
        $version = $this->versionedDocument();
        $original = $version->getRawOriginal();
        foreach (array_keys($original) as $column) {
            try {
                DB::statement("UPDATE document_versions SET {$column} = {$column} WHERE id = ?", [$version->id]);
                $this->fail("Allowed update to {$column}.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }
        $this->assertSame($original, $version->refresh()->getRawOriginal());
    }

    public function test_orm_query_builder_delete_and_replace_cannot_change_existing_versions(): void
    {
        $version = $this->versionedDocument();
        $original = $version->getRawOriginal();
        foreach ([
            fn () => $version->update(['original_name' => 'modified.txt']),
            fn () => $version->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('ORM changed an immutable version.');
            } catch (LogicException) {
                $this->assertSame($original, $version->refresh()->getRawOriginal());
            }
        }
        foreach ([
            fn () => DocumentVersion::query()->whereKey($version->id)->update(['original_name' => 'modified.txt']),
            fn () => DB::table('document_versions')->where('id', $version->id)->delete(),
            fn () => DB::delete('DELETE FROM document_versions WHERE id = ?', [$version->id]),
            fn () => DB::statement('REPLACE INTO document_versions ('.implode(', ', array_keys($original)).') VALUES ('.implode(', ', array_fill(0, count($original), '?')).')', array_values($original)),
        ] as $operation) {
            try {
                $operation();
                $this->fail('SQL changed an immutable version.');
            } catch (QueryException) {
                $this->assertSame($original, $version->refresh()->getRawOriginal());
            }
        }
    }

    public function test_parent_source_identity_is_frozen_at_orm_and_database_layers(): void
    {
        $version = $this->versionedDocument();
        $document = $version->document;
        $before = $document->getRawOriginal();
        foreach ([
            'id' => 99999999,
            'project_id' => 99999999,
            'source_import_id' => 99999999,
            'original_name' => 'ORIGINAL.txt',
            'path' => 'DOCUMENTS/changed.txt',
            'storage_disk' => 'LOCAL',
            'mime_type' => 'TEXT/plain',
            'size' => 9,
            'uploaded_by' => 99999999,
            'checksum' => null,
        ] as $column => $value) {
            try {
                $document->forceFill([$column => $value])->save();
                $this->fail("ORM allowed parent {$column} mutation.");
            } catch (LogicException) {
                $document->setRawAttributes($before, true);
            }
            try {
                DB::table('project_documents')->where('id', $document->id)->update([$column => $value]);
                $this->fail("SQL allowed parent {$column} mutation.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('identity', $exception->getMessage());
            }
            try {
                DB::statement("UPDATE project_documents SET {$column} = ? WHERE id = ?", [$value, $document->id]);
                $this->fail("Raw SQL allowed parent {$column} mutation.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('identity', $exception->getMessage());
            }
        }
        $this->assertSame($before, $document->refresh()->getRawOriginal());
    }

    public function test_raw_replace_cannot_replace_a_versioned_parent(): void
    {
        $version = $this->versionedDocument();
        $document = $version->document;
        $before = $document->getRawOriginal();
        $replacement = array_replace($before, ['path' => 'documents/replacement.txt']);

        try {
            DB::statement(
                'REPLACE INTO project_documents ('.implode(', ', array_keys($replacement)).') VALUES ('
                .implode(', ', array_fill(0, count($replacement), '?')).')',
                array_values($replacement),
            );
            $this->fail('Raw SQL replaced a versioned parent.');
        } catch (QueryException) {
            $this->assertSame($before, $document->refresh()->getRawOriginal());
            $this->assertSame($version->getRawOriginal(), $version->fresh()->getRawOriginal());
        }
    }

    public function test_parent_nullable_identity_cannot_be_populated_and_processing_and_legacy_scalar_remain_mutable(): void
    {
        $version = $this->versionedDocument(['checksum' => null], ['sha256' => hash('sha256', 'original')]);
        $document = $version->document;
        try {
            DB::table('project_documents')->where('id', $document->id)->update(['checksum' => $version->sha256]);
            $this->fail('Allowed a null identity field to be filled after registration.');
        } catch (QueryException) {
            $this->assertNull($document->refresh()->checksum);
        }
        $document->update([
            'version' => 8,
            'processing_status' => 'completed',
            'processed_at' => now(),
            'processing_error' => null,
        ]);
        $content = $document->content()->create(['extracted_text' => 'Original extraction']);
        $content->update(['extracted_text' => 'Revised extraction']);
        $content->delete();
        $this->assertSame(8, $document->refresh()->version);
        $this->assertSame('completed', $document->processing_status);
        $this->assertSame(1, $version->refresh()->revision_no);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_versioned_document_project_uploader_and_creator_hard_delete_and_id_updates_are_restricted(): void
    {
        $actor = User::factory()->create();
        $version = $this->versionedDocument([], [
            'created_via' => DocumentVersionCreatedVia::LegacyUpload,
            'created_by' => $actor->id,
        ]);
        $document = $version->document;
        try {
            $document->delete();
            $this->fail('ORM deleted a versioned parent.');
        } catch (LogicException) {
            $this->assertDatabaseHas('project_documents', ['id' => $document->id]);
        }
        foreach ([
            ['project_documents', $document->id],
            ['projects', $document->project_id],
            ['users', $document->uploaded_by],
            ['users', $actor->id],
        ] as [$table, $id]) {
            foreach (['delete', 'update'] as $operation) {
                try {
                    $query = DB::table($table)->where('id', $id);
                    $operation === 'delete' ? $query->delete() : $query->update(['id' => 99999999]);
                    $this->fail("Allowed {$table} {$operation}.");
                } catch (QueryException) {
                    $this->assertDatabaseHas($table, ['id' => $id]);
                    $this->assertDatabaseHas('document_versions', ['id' => $version->id]);
                }
            }
        }
        $document->project->delete();
        $document->uploader->delete();
        $actor->delete();
        $this->assertDatabaseHas('document_versions', ['id' => $version->id]);
        $this->assertDatabaseHas('project_documents', ['id' => $document->id]);
    }

    public function test_unversioned_legacy_document_retains_existing_edit_and_delete_behavior(): void
    {
        $version = $this->versionedDocument();
        $document = $version->document->replicate();
        $document->save();
        $document->update(['original_name' => 'renamed.txt', 'version' => 9]);
        $this->assertSame('renamed.txt', $document->refresh()->original_name);
        $id = $document->id;
        $document->delete();
        $this->assertNull(ProjectDocument::query()->find($id));
    }
}
