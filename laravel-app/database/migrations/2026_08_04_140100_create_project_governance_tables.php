<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('teacher_code', 50)->nullable()->unique();
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });

        Schema::table('academic_years', function (Blueprint $table) {
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_locked')->default(false);
        });

        Schema::table('project_statuses', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_terminal')->default(false);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::create('project_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('project_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->constrained('project_statuses')->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_status_histories');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('project_statuses', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'is_terminal']);
        });

        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'end_date', 'is_locked']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['teacher_code']);
            $table->dropColumn(['teacher_code', 'last_login_at']);
            $table->dropSoftDeletes();
        });
    }
};
