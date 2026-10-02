<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->assertDriverAndTransaction();
        $this->changeVersionChecks(true);
        $driver = DB::getDriverName();
        $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $foreignId = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED';
        $integer = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
        $integerCheck = $driver === 'sqlite' ? "typeof(page) = 'integer' AND typeof(assignment_revision) = 'integer' AND " : '';
        $numberCheck = $driver === 'sqlite'
            ? "typeof(x) IN ('integer', 'real') AND typeof(y) IN ('integer', 'real') AND typeof(width) IN ('integer', 'real') AND typeof(height) IN ('integer', 'real') AND "
            : '';
        $hashChecks = [];
        foreach (['before_sha256', 'after_sha256', 'placement_fingerprint'] as $column) {
            $hashChecks[] = $driver === 'sqlite'
                ? "length({$column}) = 64 AND {$column} NOT GLOB '*[^0-9a-f]*'"
                : "REGEXP_LIKE({$column}, '^[0-9a-f]{64}$', 'c')";
        }
        $hashCheck = implode(' AND ', $hashChecks);
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : '';

        DB::unprepared(<<<SQL
            CREATE TABLE document_signatures (
                id {$id},
                public_id CHAR(36) NOT NULL,
                project_id {$foreignId} NOT NULL,
                project_document_id {$foreignId} NOT NULL,
                source_document_version_id {$foreignId} NOT NULL,
                signed_document_version_id {$foreignId} NOT NULL,
                project_signature_slot_id {$foreignId} NOT NULL,
                assignment_revision {$integer} NOT NULL,
                signer_id {$foreignId} NOT NULL,
                signature_asset_id {$foreignId} NOT NULL,
                page {$integer} NOT NULL,
                x DECIMAL(10,8) NOT NULL,
                y DECIMAL(10,8) NOT NULL,
                width DECIMAL(10,8) NOT NULL,
                height DECIMAL(10,8) NOT NULL,
                before_sha256 CHAR(64) NOT NULL,
                after_sha256 CHAR(64) NOT NULL,
                signed_at DATETIME(6) NOT NULL,
                idempotency_key CHAR(36) NOT NULL,
                placement_fingerprint CHAR(64) NOT NULL,
                CONSTRAINT document_signatures_public_id_unique UNIQUE (public_id),
                CONSTRAINT document_signatures_source_slot_unique UNIQUE (source_document_version_id, project_signature_slot_id),
                CONSTRAINT document_signatures_signed_version_unique UNIQUE (signed_document_version_id),
                CONSTRAINT document_signatures_signer_idempotency_unique UNIQUE (signer_id, idempotency_key),
                CONSTRAINT document_signatures_project_fk FOREIGN KEY (project_id)
                    REFERENCES projects(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT document_signatures_document_fk FOREIGN KEY (project_document_id)
                    REFERENCES project_documents(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT document_signatures_source_version_fk FOREIGN KEY (source_document_version_id)
                    REFERENCES document_versions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT document_signatures_signed_version_fk FOREIGN KEY (signed_document_version_id)
                    REFERENCES document_versions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT document_signatures_slot_fk FOREIGN KEY (project_signature_slot_id)
                    REFERENCES project_signature_slots(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT document_signatures_signer_fk FOREIGN KEY (signer_id)
                    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT document_signatures_asset_fk FOREIGN KEY (signature_asset_id)
                    REFERENCES signature_assets(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT document_signatures_page_revision_check CHECK (
                    {$integerCheck}page BETWEEN 1 AND 4294967295 AND assignment_revision BETWEEN 1 AND 4294967295
                ),
                CONSTRAINT document_signatures_rectangle_check CHECK (
                    {$numberCheck}x >= 0 AND y >= 0 AND width > 0 AND height > 0
                    AND x <= 1 AND y <= 1 AND width <= 1 AND height <= 1
                    AND x + width <= 1 AND y + height <= 1
                ),
                CONSTRAINT document_signatures_hashes_check CHECK ({$hashCheck}),
                CONSTRAINT document_signatures_distinct_versions_check CHECK (source_document_version_id <> signed_document_version_id)
            ){$engine}
            SQL);

        foreach (['project_id', 'project_document_id', 'project_signature_slot_id', 'signature_asset_id'] as $column) {
            DB::statement("CREATE INDEX document_signatures_{$column}_idx ON document_signatures ({$column})");
        }

        foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $operation => $suffix) {
            $body = $driver === 'sqlite'
                ? "SELECT RAISE(ABORT, 'Document signature evidence is immutable');"
                : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document signature evidence is immutable';";
            DB::unprepared("CREATE TRIGGER document_signatures_no_{$suffix} BEFORE {$operation} ON document_signatures FOR EACH ROW BEGIN {$body} END");
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER document_signatures_no_replace BEFORE INSERT ON document_signatures
                FOR EACH ROW WHEN EXISTS (
                    SELECT 1 FROM document_signatures WHERE id = NEW.id OR public_id = NEW.public_id
                        OR signed_document_version_id = NEW.signed_document_version_id
                        OR (source_document_version_id = NEW.source_document_version_id AND project_signature_slot_id = NEW.project_signature_slot_id)
                        OR (signer_id = NEW.signer_id AND idempotency_key = NEW.idempotency_key)
                ) BEGIN SELECT RAISE(ABORT, 'Document signature evidence is immutable'); END
                SQL);
        }

        // Immutable identifiers/hashes must describe one actual derivation. These
        // insert checks do not prevent later slot reassignment or asset retirement.
        $consistent = <<<'SQL'
            EXISTS (
                SELECT 1 FROM document_versions source
                JOIN document_versions signed ON signed.id = NEW.signed_document_version_id
                JOIN project_documents document ON document.id = NEW.project_document_id
                JOIN project_signature_slots slot ON slot.id = NEW.project_signature_slot_id
                JOIN signature_assets asset ON asset.id = NEW.signature_asset_id
                WHERE source.id = NEW.source_document_version_id
                    AND source.project_document_id = document.id AND signed.project_document_id = document.id
                    AND document.project_id = NEW.project_id AND slot.project_id = NEW.project_id
                    AND source.sha256 = NEW.before_sha256 AND signed.sha256 = NEW.after_sha256
                    AND source.mime_type = 'application/pdf' AND signed.mime_type = 'application/pdf'
                    AND signed.created_via = 'signature' AND signed.created_by = NEW.signer_id
                    AND signed.revision_no = source.revision_no + 1
                    AND (signed.storage_disk <> source.storage_disk OR signed.storage_path <> source.storage_path)
                    AND asset.owner_id = NEW.signer_id AND asset.status = 'active'
                    AND slot.assigned_user_id = NEW.signer_id AND slot.assignment_revision = NEW.assignment_revision
            )
            SQL;
        $body = $driver === 'sqlite'
            ? "WHEN NOT ({$consistent}) BEGIN SELECT RAISE(ABORT, 'Document signature evidence is inconsistent'); END"
            : "BEGIN IF NOT ({$consistent}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document signature evidence is inconsistent'; END IF; END";
        DB::unprepared("CREATE TRIGGER document_signatures_consistent_insert BEFORE INSERT ON document_signatures FOR EACH ROW {$body}");
    }

    public function down(): void
    {
        $this->assertDriverAndTransaction();
        // MySQL DDL auto-commits. Refuse before changing any table or trigger.
        if ((Schema::hasTable('document_signatures') && DB::table('document_signatures')->exists())
            || DB::table('document_versions')->where('created_via', 'signature')->exists()) {
            throw new RuntimeException('Cannot roll back populated document signing; preserve and export signed history first.');
        }
        foreach (['consistent_insert', 'no_replace', 'no_update', 'no_delete'] as $suffix) {
            DB::unprepared("DROP TRIGGER IF EXISTS document_signatures_{$suffix}");
        }
        Schema::dropIfExists('document_signatures');
        $this->changeVersionChecks(false);
    }

    private function assertDriverAndTransaction(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Document signing requires SQLite or MySQL.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Document signing migration must own its schema transaction.');
        }
    }

    private function changeVersionChecks(bool $enableSigning): void
    {
        $signature = $enableSigning ? ", 'signature'" : '';
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<SQL
                ALTER TABLE document_versions
                    DROP CHECK document_versions_created_via_check,
                    DROP CHECK document_versions_creator_check,
                    ADD CONSTRAINT document_versions_created_via_check CHECK (BINARY created_via IN ('phase5_confirm', 'legacy_upload', 'backfill'{$signature})),
                    ADD CONSTRAINT document_versions_creator_check CHECK (
                        (created_via = 'backfill' AND created_by IS NULL)
                        OR (created_via IN ('phase5_confirm', 'legacy_upload'{$signature}) AND created_by IS NOT NULL)
                    )
                SQL);

            return;
        }

        $definition = DB::table('sqlite_master')->where('type', 'table')->where('name', 'document_versions')->value('sql');
        if (! is_string($definition)) {
            throw new RuntimeException('Document version schema is unavailable.');
        }
        $definition = preg_replace(
            "/(CONSTRAINT document_versions_created_via_check CHECK \\(created_via IN )\\([^)]*\\)/",
            "\$1('phase5_confirm', 'legacy_upload', 'backfill'{$signature})",
            $definition, 1, $originCount,
        );
        $definition = preg_replace(
            "/(OR \\(created_via IN )\\('phase5_confirm', 'legacy_upload'(?:, 'signature')?\\)/",
            "\$1('phase5_confirm', 'legacy_upload'{$signature})",
            $definition, 1, $creatorCount,
        );
        if ($originCount !== 1 || $creatorCount !== 1) {
            throw new RuntimeException('Document version checks differ from the supported schema.');
        }
        $definition = preg_replace('/^CREATE TABLE ["`\\[]?document_versions["`\\]]?/i', 'CREATE TABLE document_versions_phase6d', $definition, 1, $tableCount);
        if ($tableCount !== 1) {
            throw new RuntimeException('Document version table definition is unsupported.');
        }
        $indexes = DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', 'document_versions')->whereNotNull('sql')->pluck('sql');
        // External triggers may refer to document_versions; temporarily remove
        // those too so ALTER TABLE never observes a dangling schema reference.
        $triggers = DB::table('sqlite_master')->where('type', 'trigger')
            ->where(fn ($query) => $query->where('tbl_name', 'document_versions')->orWhere('sql', 'like', '%document_versions%'))
            ->get(['name', 'sql']);
        $sequence = DB::table('sqlite_sequence')->where('name', 'document_versions')->value('seq');
        $columns = collect(DB::select('PRAGMA table_info(document_versions)'))->pluck('name')
            ->map(fn ($name) => '"'.str_replace('"', '""', $name).'"')->implode(', ');
        $foreignKeys = (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
        DB::statement('PRAGMA foreign_keys = OFF');
        if ((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys !== 0) {
            throw new RuntimeException('Cannot safely rebuild document version checks.');
        }
        try {
            DB::beginTransaction();
            foreach ($triggers as $trigger) {
                DB::unprepared('DROP TRIGGER "'.str_replace('"', '""', $trigger->name).'"');
            }
            DB::unprepared($definition);
            DB::statement("INSERT INTO document_versions_phase6d ({$columns}) SELECT {$columns} FROM document_versions");
            DB::statement('DROP TABLE document_versions');
            DB::statement('ALTER TABLE document_versions_phase6d RENAME TO document_versions');
            foreach ($indexes as $sql) {
                DB::unprepared($sql);
            }
            foreach ($triggers as $trigger) {
                DB::unprepared($trigger->sql);
            }
            if ($sequence !== null) {
                DB::table('sqlite_sequence')->where('name', 'document_versions')->update(['seq' => $sequence]);
            }
            if (DB::select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('Document version rebuild failed foreign-key verification.');
            }
            DB::commit();
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        } finally {
            DB::statement('PRAGMA foreign_keys = '.$foreignKeys);
        }
    }
};
