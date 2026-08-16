<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('project_code', 50)->nullable()->unique()->after('name');
            $table->longText('rationale')->nullable()->after('description');
            $table->text('target_group')->nullable()->after('rationale');
            $table->text('strategy')->nullable()->after('target_group');
            $table->string('budget_source', 120)->nullable()->after('budget');
            $table->string('responsible_person')->nullable()->after('budget_source');
            $table->decimal('actual_spent', 12, 2)->default(0)->after('responsible_person');
            $table->timestamp('submitted_at')->nullable()->after('end_date');
            $table->timestamp('screened_at')->nullable()->after('submitted_at');
            $table->timestamp('approved_at')->nullable()->after('screened_at');
            $table->timestamp('completed_at')->nullable()->after('approved_at');
        });

        Schema::create('project_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('target_value', 12, 2)->nullable();
            $table->string('unit', 50)->nullable();
            $table->decimal('actual_value', 12, 2)->nullable();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('project_completion_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('success_percent', 5, 2)->default(0);
            $table->decimal('quality_score', 4, 2)->default(0);
            $table->decimal('actual_spent', 12, 2)->default(0);
            $table->longText('summary');
            $table->text('problems')->nullable();
            $table->text('suggestions')->nullable();
            $table->timestamp('reported_at');
            $table->timestamps();
            $table->index(['project_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_completion_reports');
        Schema::dropIfExists('project_kpis');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['project_code']);
            $table->dropColumn([
                'project_code',
                'rationale',
                'target_group',
                'strategy',
                'budget_source',
                'responsible_person',
                'actual_spent',
                'submitted_at',
                'screened_at',
                'approved_at',
                'completed_at',
            ]);
        });
    }
};
