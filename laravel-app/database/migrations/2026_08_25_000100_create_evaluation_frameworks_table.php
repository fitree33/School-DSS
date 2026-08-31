<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_frameworks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100);
            $table->string('version', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('fiscal_year_id')
                ->nullable()
                ->constrained('fiscal_years')
                ->restrictOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(
                ['fiscal_year_id', 'is_active', 'effective_from', 'effective_to'],
                'evaluation_frameworks_availability_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_frameworks');
    }
};
