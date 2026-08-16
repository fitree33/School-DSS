<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('fiscal_year_id')
                ->nullable()
                ->constrained('fiscal_years')
                ->restrictOnDelete();
            $table->foreignId('school_plan_id')
                ->nullable()
                ->constrained('school_plans')
                ->restrictOnDelete();
            $table->foreignId('project_execution_status_id')
                ->nullable()
                ->constrained('project_execution_statuses')
                ->restrictOnDelete();
            $table->foreignId('evaluation_status_id')
                ->nullable()
                ->constrained('evaluation_statuses')
                ->restrictOnDelete();
            $table->longText('key_points')->nullable();
            $table->string('monitor_person')->nullable();
            $table->text('evaluation_method')->nullable();
            $table->text('evaluation_tools')->nullable();

            $table->index(
                ['fiscal_year_id', 'department_id', 'project_execution_status_id'],
                'projects_v2_dashboard_idx'
            );
            $table->index(
                ['fiscal_year_id', 'evaluation_status_id'],
                'projects_v2_evaluation_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['fiscal_year_id']);
            $table->dropForeign(['school_plan_id']);
            $table->dropForeign(['project_execution_status_id']);
            $table->dropForeign(['evaluation_status_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_v2_dashboard_idx');
            $table->dropIndex('projects_v2_evaluation_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'fiscal_year_id',
                'school_plan_id',
                'project_execution_status_id',
                'evaluation_status_id',
                'key_points',
                'monitor_person',
                'evaluation_method',
                'evaluation_tools',
            ]);
        });
    }
};
