<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_evaluation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnDelete();
            $table->foreignId('project_evaluation_id')
                ->unique()
                ->constrained('project_evaluations')
                ->restrictOnDelete();
            $table->foreignId('evaluation_framework_id')
                ->constrained('evaluation_frameworks')
                ->restrictOnDelete();
            $table->foreignId('evaluation_status_id')
                ->constrained('evaluation_statuses')
                ->restrictOnDelete();
            $table->decimal('total_score', 10, 2);
            $table->decimal('maximum_score', 10, 2);
            $table->decimal('percentage', 7, 2);
            $table->decimal('weighted_percentage', 7, 2)->nullable();
            $table->json('scores_snapshot');
            $table->text('decision_note');
            $table->foreignId('finalized_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('finalized_at');
            $table->timestamps();

            $table->index(
                ['project_id', 'finalized_at'],
                'project_evaluation_results_project_latest_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_evaluation_results');
    }
};
