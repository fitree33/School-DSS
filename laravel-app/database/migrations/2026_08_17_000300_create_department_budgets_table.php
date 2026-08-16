<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_budget_id')
                ->constrained('school_budgets')
                ->restrictOnDelete();
            $table->foreignId('department_id')
                ->constrained('departments')
                ->restrictOnDelete();
            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->boolean('is_allocated')->default(false);
            $table->timestamp('allocated_at')->nullable();
            $table->foreignId('allocated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['school_budget_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_budgets');
    }
};
