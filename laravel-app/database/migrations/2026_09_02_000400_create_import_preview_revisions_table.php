<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_preview_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_import_id')
                ->constrained('document_imports')
                ->restrictOnDelete();
            $table->unsignedInteger('revision_no');
            $table->unsignedInteger('parent_revision_no')->nullable();
            $table->foreignId('source_extraction_run_id')
                ->nullable()
                ->constrained('ai_extraction_runs')
                ->restrictOnDelete();
            $table->string('source', 20);
            $table->json('payload');
            $table->json('validation_errors')->nullable();
            $table->json('warnings')->nullable();
            $table->json('field_confidence')->nullable();
            $table->foreignId('edited_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('client_idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->unique(
                ['document_import_id', 'revision_no'],
                'import_preview_revisions_import_revision_unique'
            );
            $table->unique(
                ['document_import_id', 'client_idempotency_key'],
                'import_preview_revisions_import_idempotency_unique'
            );
            $table->index(
                ['document_import_id', 'created_at'],
                'import_preview_revisions_import_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_preview_revisions');
    }
};
