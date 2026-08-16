<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')
                ->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'code']);
            $table->index(
                ['fiscal_year_id', 'is_active', 'sort_order'],
                'school_plans_fiscal_active_sort_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_plans');
    }
};
