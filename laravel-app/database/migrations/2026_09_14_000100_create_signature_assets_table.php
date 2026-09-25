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
            throw new RuntimeException('Signature assets require SQLite or MySQL.');
        }

        // Historical SQL definitions intentionally do not depend on mutable application configuration.
        $sqlite = $driver === 'sqlite';
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $foreignId = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
        $integer = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
        $exact = static fn (string $column): string => $sqlite ? "{$column} COLLATE BINARY" : "CAST({$column} AS BINARY)";
        $integerType = static fn (string $column): string => $sqlite ? "typeof({$column}) = 'integer' AND " : '';
        $uuidCheck = $sqlite
            ? "typeof(public_id) = 'text' AND length(public_id) = 36 AND substr(public_id, 9, 1) = '-' AND substr(public_id, 14, 1) = '-' AND substr(public_id, 19, 1) = '-' AND substr(public_id, 24, 1) = '-' AND length(replace(public_id, '-', '')) = 32 AND replace(public_id, '-', '') NOT GLOB '*[^0-9a-f]*'"
            : "CHAR_LENGTH(public_id) = 36 AND REGEXP_LIKE(public_id, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')";
        $keyCheck = $sqlite
            ? "typeof(storage_key) = 'text' AND length(storage_key) = 68 AND substr(storage_key, 65) = '.png' AND substr(storage_key, 1, 64) NOT GLOB '*[^0-9a-f]*'"
            : "CHAR_LENGTH(storage_key) = 68 AND REGEXP_LIKE(storage_key, '^[0-9a-f]{64}[.]png$', 'c')";
        $hashCheck = $sqlite
            ? "typeof(sha256) = 'text' AND length(sha256) = 64 AND sha256 NOT GLOB '*[^0-9a-f]*'"
            : "CHAR_LENGTH(sha256) = 64 AND REGEXP_LIKE(sha256, '^[0-9a-f]{64}$', 'c')";
        $reasonCheck = $sqlite
            ? "typeof(retirement_reason) = 'text' AND length(retirement_reason) <= 500"
            : 'CHAR_LENGTH(retirement_reason) <= 500';
        $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        $mime = $exact('mime_type');
        $normalization = $exact('normalization_version');
        $status = $exact('status');
        $sizeType = $integerType('size_bytes');
        $widthType = $integerType('width');
        $heightType = $integerType('height');

        DB::unprepared(<<<SQL
            CREATE TABLE signature_assets (
                id {$id},
                public_id VARCHAR(36) NOT NULL,
                owner_id {$foreignId} NOT NULL,
                storage_key VARCHAR(68) NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                size_bytes {$integer} NOT NULL,
                width {$integer} NOT NULL,
                height {$integer} NOT NULL,
                mime_type VARCHAR(32) NOT NULL,
                normalization_version VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                retired_at DATETIME(6) NULL DEFAULT NULL,
                retired_by {$foreignId} NULL DEFAULT NULL,
                retirement_reason TEXT NULL DEFAULT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                CONSTRAINT signature_assets_public_id_unique UNIQUE (public_id),
                CONSTRAINT signature_assets_storage_key_unique UNIQUE (storage_key),
                CONSTRAINT signature_assets_owner_fk FOREIGN KEY (owner_id)
                    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT signature_assets_retired_by_fk FOREIGN KEY (retired_by)
                    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT signature_assets_public_id_check CHECK ({$uuidCheck}),
                CONSTRAINT signature_assets_storage_key_check CHECK ({$keyCheck}),
                CONSTRAINT signature_assets_sha256_check CHECK ({$hashCheck}),
                CONSTRAINT signature_assets_size_check CHECK ({$sizeType}size_bytes BETWEEN 1 AND 8388608),
                CONSTRAINT signature_assets_dimensions_check CHECK (
                    {$widthType}{$heightType}width BETWEEN 1 AND 2048 AND height BETWEEN 1 AND 1024
                    AND width * height <= 1048576
                ),
                CONSTRAINT signature_assets_mime_check CHECK ({$mime} = 'image/png'),
                CONSTRAINT signature_assets_normalization_check CHECK ({$normalization} = 'gd-png-v1'),
                CONSTRAINT signature_assets_retirement_check CHECK (
                    ({$status} = 'active' AND retired_at IS NULL AND retired_by IS NULL AND retirement_reason IS NULL)
                    OR ({$status} = 'retired' AND retired_at IS NOT NULL AND retired_by IS NOT NULL
                        AND retired_by = owner_id AND (retirement_reason IS NULL OR ({$reasonCheck})))
                )
            ){$engine}
            SQL);

        DB::statement('CREATE INDEX signature_assets_owner_status_idx ON signature_assets (owner_id, status, id)');
        DB::statement('CREATE INDEX signature_assets_retired_by_idx ON signature_assets (retired_by)');

        $different = static fn (string $column): string => $sqlite
            ? "OLD.{$column} IS NOT NEW.{$column}"
            : "NOT (CAST(OLD.{$column} AS BINARY) <=> CAST(NEW.{$column} AS BINARY))";
        $identityChanged = implode(' OR ', array_map($different, [
            'id', 'public_id', 'owner_id', 'storage_key', 'sha256', 'size_bytes',
            'width', 'height', 'mime_type', 'normalization_version', 'created_at',
        ]));
        $lifecycleChanged = implode(' OR ', array_map($different, ['status', 'retired_at', 'retired_by', 'retirement_reason', 'updated_at']));
        $oldStatus = $exact('OLD.status');
        $newStatus = $exact('NEW.status');
        $invalidUpdate = "({$identityChanged}) OR (({$lifecycleChanged}) AND NOT ({$oldStatus} = 'active' AND {$newStatus} = 'retired'))";
        $this->trigger('signature_assets_protect_update', 'UPDATE', $invalidUpdate, 'Signature asset identity and retirement are immutable', $sqlite);
        $this->trigger('signature_assets_no_delete', 'DELETE', '1 = 1', 'Signature assets cannot be deleted', $sqlite);
        $this->trigger('signature_assets_active_insert', 'INSERT', "NOT ({$newStatus} = 'active')", 'Signature assets must be created active', $sqlite);

        if ($sqlite) {
            // SQLite REPLACE can delete implicitly without invoking a DELETE trigger.
            $this->trigger('signature_assets_no_replace', 'INSERT', 'EXISTS (SELECT 1 FROM signature_assets WHERE id = NEW.id OR public_id = NEW.public_id OR storage_key = NEW.storage_key)', 'Signature assets cannot be replaced', true);
        }
    }

    private function trigger(string $name, string $operation, string $condition, string $message, bool $sqlite): void
    {
        $body = $sqlite
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END"
            : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
        DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON signature_assets FOR EACH ROW {$body}");
    }

    public function down(): void
    {
        // Refuse before any DDL: MySQL DDL commits implicitly.
        if (Schema::hasTable('signature_assets') && DB::table('signature_assets')->exists()) {
            throw new RuntimeException('Cannot roll back populated signature_assets; preserve the asset registry first.');
        }

        foreach (['protect_update', 'no_delete', 'active_insert', 'no_replace'] as $suffix) {
            DB::unprepared("DROP TRIGGER IF EXISTS signature_assets_{$suffix}");
        }
        Schema::dropIfExists('signature_assets');
    }
};
