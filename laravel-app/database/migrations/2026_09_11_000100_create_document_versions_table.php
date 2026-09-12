<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Document versions require SQLite or MySQL.');
        }

        // These SQL literals deliberately preserve this migration's historical
        // definition independently of future additions to application enums.
        $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $integer = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED';
        $revision = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
        $revisionCheck = $driver === 'sqlite'
            ? "typeof(revision_no) = 'integer' AND revision_no >= 1"
            : 'revision_no >= 1';
        $sizeCheck = $driver === 'sqlite'
            ? "typeof(size_bytes) = 'integer' AND size_bytes >= 0"
            : 'size_bytes >= 0';
        $hashCheck = $driver === 'sqlite'
            ? "length(sha256) = 64 AND sha256 NOT GLOB '*[^0-9a-f]*'"
            : "REGEXP_LIKE(sha256, '^[0-9a-f]{64}$', 'c')";
        $caseSensitive = $driver === 'mysql' ? 'BINARY ' : '';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : '';

        DB::unprepared(<<<SQL
            CREATE TABLE document_versions (
                id {$id},
                public_id CHAR(36) NOT NULL,
                project_document_id {$integer} NOT NULL,
                revision_no {$revision} NOT NULL,
                created_via VARCHAR(20) NOT NULL,
                storage_disk VARCHAR(50) NOT NULL,
                storage_path VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                size_bytes {$integer} NOT NULL,
                sha256 CHAR(64) NOT NULL,
                integrity_basis VARCHAR(20) NOT NULL,
                created_by {$integer} NULL DEFAULT NULL,
                verified_at DATETIME(6) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                CONSTRAINT document_versions_public_id_unique UNIQUE (public_id),
                CONSTRAINT document_versions_document_revision_unique UNIQUE (project_document_id, revision_no),
                CONSTRAINT document_versions_document_fk FOREIGN KEY (project_document_id)
                    REFERENCES project_documents(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT document_versions_created_by_fk FOREIGN KEY (created_by)
                    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT document_versions_revision_positive_check CHECK ({$revisionCheck}),
                CONSTRAINT document_versions_size_nonnegative_check CHECK ({$sizeCheck}),
                CONSTRAINT document_versions_sha256_format_check CHECK ({$hashCheck}),
                CONSTRAINT document_versions_created_via_check CHECK ({$caseSensitive}created_via IN ('phase5_confirm', 'legacy_upload', 'backfill')),
                CONSTRAINT document_versions_integrity_basis_check CHECK ({$caseSensitive}integrity_basis IN ('recorded_sha256', 'observed_sha256')),
                CONSTRAINT document_versions_creator_check CHECK (
                    (created_via = 'backfill' AND created_by IS NULL)
                    OR (created_via IN ('phase5_confirm', 'legacy_upload') AND created_by IS NOT NULL)
                )
            ){$engine}
            SQL);

        DB::statement('CREATE INDEX document_versions_sha256_idx ON document_versions (sha256)');
        DB::statement('CREATE INDEX document_versions_storage_locator_idx ON document_versions (storage_disk, storage_path)');
        DB::statement('CREATE INDEX document_versions_created_by_idx ON document_versions (created_by)');

        foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $operation => $suffix) {
            $body = $driver === 'sqlite'
                ? "SELECT RAISE(ABORT, 'Document versions are immutable');"
                : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document versions are immutable';";
            DB::unprepared("CREATE TRIGGER document_versions_no_{$suffix} BEFORE {$operation} ON document_versions FOR EACH ROW BEGIN {$body} END");
        }

        if ($driver === 'sqlite') {
            // SQLite REPLACE can implicitly delete without running DELETE triggers.
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER document_versions_no_replace BEFORE INSERT ON document_versions
                FOR EACH ROW WHEN EXISTS (
                    SELECT 1 FROM document_versions WHERE id = NEW.id OR public_id = NEW.public_id
                        OR (project_document_id = NEW.project_document_id AND revision_no = NEW.revision_no)
                ) BEGIN SELECT RAISE(ABORT, 'Document versions are immutable'); END
                SQL);
        }

        $identity = ['id', 'project_id', 'source_import_id', 'original_name', 'path', 'storage_disk', 'mime_type', 'size', 'uploaded_by', 'checksum'];
        $changed = implode(' OR ', array_map(
            fn (string $column): string => $driver === 'sqlite'
                ? "OLD.{$column} IS NOT NEW.{$column}"
                : "NOT (BINARY OLD.{$column} <=> BINARY NEW.{$column})",
            $identity,
        ));
        $exists = 'EXISTS (SELECT 1 FROM document_versions WHERE project_document_id = OLD.id)';
        $protection = $driver === 'sqlite'
            ? "WHEN {$exists} AND ({$changed}) BEGIN SELECT RAISE(ABORT, 'Versioned document source identity is immutable'); END"
            : "BEGIN IF {$exists} AND ({$changed}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Versioned document source identity is immutable'; END IF; END";
        DB::unprepared("CREATE TRIGGER project_documents_protect_versioned_identity BEFORE UPDATE ON project_documents FOR EACH ROW {$protection}");
    }

    public function down(): void
    {
        // Must precede every DDL statement: MySQL DDL implicitly commits and
        // cannot be rescued by wrapping a partially destructive down() in a transaction.
        if (Schema::hasTable('document_versions') && DB::table('document_versions')->exists()) {
            throw new RuntimeException('Cannot roll back populated document_versions; preserve and export the version registry first.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS project_documents_protect_versioned_identity');
        DB::unprepared('DROP TRIGGER IF EXISTS document_versions_no_replace');
        DB::unprepared('DROP TRIGGER IF EXISTS document_versions_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS document_versions_no_delete');
        Schema::dropIfExists('document_versions');
    }
};
