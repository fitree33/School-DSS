<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_extraction_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('document_import_id')
                ->constrained('document_imports')
                ->restrictOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('provider', 50);
            $table->string('model_name', 100)->nullable();
            $table->string('schema_version', 50);
            $table->string('prompt_version', 50)->nullable();
            $table->string('status', 30)->default('queued');
            $table->longText('extracted_text')->nullable();
            $table->char('extracted_text_sha256', 64)->nullable();
            $table->json('raw_result')->nullable();
            $table->json('normalized_result')->nullable();
            $table->json('confidence')->nullable();
            $table->json('warnings')->nullable();
            $table->string('provider_job_id', 191)->nullable();
            $table->string('provider_event_id', 191)->nullable();
            $table->char('callback_digest', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('callback_received_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['document_import_id', 'attempt_no'],
                'ai_extraction_runs_import_attempt_unique'
            );
            $table->unique(
                ['provider', 'provider_event_id'],
                'ai_extraction_runs_provider_event_unique'
            );
            $table->index(
                ['document_import_id', 'status', 'created_at'],
                'ai_extraction_runs_import_status_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_extraction_runs');
    }
};
