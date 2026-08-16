<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_execution_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('color', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_terminal')->default(false);
            $table->timestamps();
        });

        $timestamp = now();

        DB::table('project_execution_statuses')->insert([
            [
                'code' => 'not_started',
                'name' => 'ยังไม่ดำเนินการ',
                'color' => 'slate',
                'sort_order' => 10,
                'is_terminal' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'code' => 'in_progress',
                'name' => 'กำลังดำเนินการ',
                'color' => 'blue',
                'sort_order' => 20,
                'is_terminal' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'code' => 'completed',
                'name' => 'ดำเนินการแล้ว',
                'color' => 'emerald',
                'sort_order' => 30,
                'is_terminal' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('project_execution_statuses');
    }
};
