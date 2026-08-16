<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_logs', function (Blueprint $table) {
            $table->string('intent')->nullable();
            $table->json('filters')->nullable();
            $table->string('model_name')->nullable();
            $table->string('response_status', 30)->default('pending');
            $table->unsignedInteger('response_time_ms')->nullable();
        });

        Schema::create('search_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->decimal('relevance_score', 8, 4)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['search_log_id', 'project_id']);
            $table->index(['search_log_id', 'rank']);
        });

        Schema::table('project_documents', function (Blueprint $table) {
            $table->string('checksum', 64)->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('processing_status', 30)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
        });

        Schema::create('document_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('project_documents')->cascadeOnDelete();
            $table->longText('extracted_text')->nullable();
            $table->string('language', 10)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_contents');

        Schema::table('project_documents', function (Blueprint $table) {
            $table->dropIndex(['checksum']);
            $table->dropColumn(['checksum', 'version', 'processing_status', 'processed_at', 'processing_error']);
        });

        Schema::dropIfExists('search_results');

        Schema::table('search_logs', function (Blueprint $table) {
            $table->dropColumn(['intent', 'filters', 'model_name', 'response_status', 'response_time_ms']);
        });
    }
};
