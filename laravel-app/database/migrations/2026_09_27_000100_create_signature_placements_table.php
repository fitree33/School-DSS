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
            throw new RuntimeException('Signature placements require SQLite or MySQL.');
        }
        $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $foreignId = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED';
        $integer = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
        $integerCheck = $driver === 'sqlite' ? "typeof(page) = 'integer' AND typeof(assignment_revision) = 'integer' AND " : '';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : '';

        DB::unprepared(<<<SQL
            CREATE TABLE signature_placements (
                id {$id},
                document_version_id {$foreignId} NOT NULL,
                project_signature_slot_id {$foreignId} NOT NULL,
                signature_asset_id {$foreignId} NOT NULL,
                assignment_revision {$integer} NOT NULL,
                page {$integer} NOT NULL,
                x DECIMAL(10,8) NOT NULL,
                y DECIMAL(10,8) NOT NULL,
                width DECIMAL(10,8) NOT NULL,
                height DECIMAL(10,8) NOT NULL,
                created_by {$foreignId} NOT NULL,
                updated_by {$foreignId} NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                CONSTRAINT signature_placements_version_slot_unique UNIQUE (document_version_id, project_signature_slot_id),
                CONSTRAINT signature_placements_version_fk FOREIGN KEY (document_version_id)
                    REFERENCES document_versions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT signature_placements_slot_fk FOREIGN KEY (project_signature_slot_id)
                    REFERENCES project_signature_slots(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT signature_placements_asset_fk FOREIGN KEY (signature_asset_id)
                    REFERENCES signature_assets(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT signature_placements_creator_fk FOREIGN KEY (created_by)
                    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT signature_placements_updater_fk FOREIGN KEY (updated_by)
                    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT signature_placements_page_revision_check CHECK (
                    {$integerCheck}page BETWEEN 1 AND 4294967295 AND assignment_revision BETWEEN 1 AND 4294967295
                ),
                CONSTRAINT signature_placements_rectangle_check CHECK (
                    x >= 0 AND y >= 0 AND width > 0 AND height > 0
                    AND x <= 1 AND y <= 1 AND width <= 1 AND height <= 1
                    AND x + width <= 1 AND y + height <= 1
                )
            ){$engine}
            SQL);

        // The version-first unique index covers context reads; SQLite needs explicit child FK indexes.
        DB::statement('CREATE INDEX signature_placements_slot_idx ON signature_placements (project_signature_slot_id)');
        DB::statement('CREATE INDEX signature_placements_asset_idx ON signature_placements (signature_asset_id)');
        DB::statement('CREATE INDEX signature_placements_creator_idx ON signature_placements (created_by)');
        DB::statement('CREATE INDEX signature_placements_updater_idx ON signature_placements (updated_by)');
    }

    public function down(): void
    {
        if (Schema::hasTable('signature_placements') && DB::table('signature_placements')->exists()) {
            throw new RuntimeException('Cannot roll back populated signature_placements; preserve and export drafts first.');
        }
        Schema::dropIfExists('signature_placements');
    }
};
