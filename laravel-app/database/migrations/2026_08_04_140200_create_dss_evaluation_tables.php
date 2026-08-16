<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_criteria', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('weight', 5, 2)->default(0);
            $table->decimal('max_score', 8, 2)->default(5);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('project_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('round')->default(1);
            $table->decimal('total_score', 10, 2)->default(0);
            $table->text('comment')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'evaluator_id', 'round']);
        });

        Schema::create('evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('project_evaluations')->cascadeOnDelete();
            $table->foreignId('criteria_id')->constrained('evaluation_criteria')->restrictOnDelete();
            $table->decimal('score', 8, 2);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_id', 'criteria_id']);
        });

        Schema::create('dss_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('calculation_version', 50)->default('1.0');
            $table->decimal('total_score', 10, 2);
            $table->unsignedInteger('rank')->nullable();
            $table->string('recommendation')->nullable();
            $table->longText('explanation')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->index(['project_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dss_results');
        Schema::dropIfExists('evaluation_scores');
        Schema::dropIfExists('project_evaluations');
        Schema::dropIfExists('evaluation_criteria');
    }
};
