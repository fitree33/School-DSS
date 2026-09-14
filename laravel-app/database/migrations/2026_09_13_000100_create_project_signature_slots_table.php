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
            throw new RuntimeException('Project signature slots require SQLite or MySQL.');
        }

        // Keep historical schema literals independent of application enums.
        $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $foreignId = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED';
        $slotNo = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT UNSIGNED';
        $revision = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
        $slotCode = $driver === 'sqlite' ? 'VARCHAR(32) COLLATE BINARY' : 'VARCHAR(32)';
        $exactCode = $driver === 'sqlite' ? 'slot_code COLLATE BINARY' : 'CAST(slot_code AS BINARY)';
        $slotTypeCheck = $driver === 'sqlite' ? "typeof(slot_no) = 'integer' AND typeof(slot_code) = 'text' AND " : '';
        $revisionTypeCheck = $driver === 'sqlite' ? "typeof(assignment_revision) = 'integer' AND " : '';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : '';

        DB::unprepared(<<<SQL
            CREATE TABLE project_signature_slots (
                id {$id},
                project_id {$foreignId} NOT NULL,
                slot_code {$slotCode} NOT NULL,
                slot_no {$slotNo} NOT NULL,
                assigned_user_id {$foreignId} NULL DEFAULT NULL,
                assignment_revision {$revision} NOT NULL DEFAULT 0,
                assigned_by {$foreignId} NULL DEFAULT NULL,
                assigned_at DATETIME(6) NULL DEFAULT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                CONSTRAINT project_signature_slots_project_code_unique UNIQUE (project_id, slot_code),
                CONSTRAINT project_signature_slots_project_no_unique UNIQUE (project_id, slot_no),
                CONSTRAINT project_signature_slots_project_fk FOREIGN KEY (project_id)
                    REFERENCES projects(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT project_signature_slots_assignee_fk FOREIGN KEY (assigned_user_id)
                    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT project_signature_slots_assigner_fk FOREIGN KEY (assigned_by)
                    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT project_signature_slots_mapping_check CHECK (
                    {$slotTypeCheck}(
                        (slot_no = 1 AND {$exactCode} = 'project_proposer')
                        OR (slot_no = 2 AND {$exactCode} = 'related_approver')
                        OR (slot_no = 3 AND {$exactCode} = 'deputy_director')
                        OR (slot_no = 4 AND {$exactCode} = 'director')
                    )
                ),
                CONSTRAINT project_signature_slots_revision_check CHECK (
                    {$revisionTypeCheck}assignment_revision BETWEEN 0 AND 4294967295
                ),
                CONSTRAINT project_signature_slots_assignment_check CHECK (
                    (assigned_user_id IS NULL AND assigned_by IS NULL AND assigned_at IS NULL)
                    OR (assigned_user_id IS NOT NULL AND assigned_by IS NOT NULL
                        AND assigned_at IS NOT NULL AND assignment_revision >= 1)
                )
            ){$engine}
            SQL);

        DB::statement('CREATE INDEX project_signature_slots_assignee_idx ON project_signature_slots (assigned_user_id)');
        DB::statement('CREATE INDEX project_signature_slots_assigner_idx ON project_signature_slots (assigned_by)');
    }

    public function down(): void
    {
        // MySQL DDL implicitly commits. Refuse before the first DDL statement,
        // including bootstrap rows that have never had an assignee.
        if (Schema::hasTable('project_signature_slots') && DB::table('project_signature_slots')->exists()) {
            throw new RuntimeException('Cannot roll back populated project_signature_slots; preserve and export assignments first.');
        }

        Schema::dropIfExists('project_signature_slots');
    }
};
