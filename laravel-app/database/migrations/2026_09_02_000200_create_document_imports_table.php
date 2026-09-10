<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('uploader_department_id')
                ->nullable()
                ->constrained('departments')
                ->restrictOnDelete();
            $table->string('status', 30)->default('uploaded');
            $table->string('processing_stage', 50)->nullable();
            $table->string('original_name');
            $table->string('storage_disk', 50);
            $table->string('storage_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedInteger('page_count')->nullable();
            $table->string('language', 10)->nullable();
            $table->unsignedInteger('active_extraction_attempt')->nullable();
            $table->unsignedInteger('current_preview_revision')->nullable();
            $table->unsignedInteger('confirmed_preview_revision')->nullable();
            $table->char('confirmation_idempotency_key_hash', 64)->nullable();
            $table->foreignId('confirmed_project_id')
                ->nullable()
                ->unique()
                ->constrained('projects')
                ->restrictOnDelete();
            $table->foreignId('confirmed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('failure_stage', 50)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index('sha256');
            $table->index(['status', 'created_at'], 'document_imports_status_created_idx');
            $table->index(['uploaded_by', 'created_at'], 'document_imports_uploader_created_idx');
            $table->index(
                ['uploader_department_id', 'status', 'created_at'],
                'document_imports_department_status_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_imports');
    }
};
