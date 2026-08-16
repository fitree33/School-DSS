<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_execution_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('from_status_id')
                ->nullable()
                ->constrained('project_execution_statuses')
                ->nullOnDelete();
            $table->foreignId('to_status_id')
                ->constrained('project_execution_statuses')
                ->restrictOnDelete();
            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(
                ['project_id', 'created_at'],
                'project_exec_history_project_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_execution_status_histories');
    }
};
