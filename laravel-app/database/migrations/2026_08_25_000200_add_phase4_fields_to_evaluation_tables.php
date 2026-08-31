<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_criteria', function (Blueprint $table) {
            $table->foreignId('evaluation_framework_id')
                ->nullable()
                ->constrained('evaluation_frameworks')
                ->restrictOnDelete();
            $table->text('evaluation_method')->nullable();
            $table->text('evaluation_tools')->nullable();

            $table->index(
                ['evaluation_framework_id', 'is_active', 'sort_order'],
                'evaluation_criteria_framework_order_idx',
            );
        });

        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->foreignId('evaluation_framework_id')
                ->nullable()
                ->constrained('evaluation_frameworks')
                ->restrictOnDelete();
            $table->decimal('maximum_score', 10, 2)->nullable();
            $table->decimal('percentage', 7, 2)->nullable();
            $table->decimal('weighted_percentage', 7, 2)->nullable();
            $table->foreignId('finalized_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();

            $table->index(
                ['project_id', 'evaluation_framework_id', 'evaluated_at'],
                'project_evaluations_framework_history_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('project_evaluations', function (Blueprint $table) {
            $table->dropForeign(['evaluation_framework_id']);
            $table->dropForeign(['finalized_by']);
            $table->dropIndex('project_evaluations_framework_history_idx');
            $table->dropColumn([
                'evaluation_framework_id',
                'maximum_score',
                'percentage',
                'weighted_percentage',
                'finalized_by',
                'finalized_at',
            ]);
        });

        Schema::table('evaluation_criteria', function (Blueprint $table) {
            $table->dropForeign(['evaluation_framework_id']);
            $table->dropIndex('evaluation_criteria_framework_order_idx');
            $table->dropColumn([
                'evaluation_framework_id',
                'evaluation_method',
                'evaluation_tools',
            ]);
        });
    }
};
