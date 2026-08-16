<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('color', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_terminal')->default(false);
            $table->timestamps();
        });

        $timestamp = now();

        DB::table('evaluation_statuses')->insert([
            [
                'code' => 'pending',
                'name' => 'รอประเมิน',
                'color' => 'amber',
                'sort_order' => 10,
                'is_terminal' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'code' => 'passed',
                'name' => 'ผ่าน',
                'color' => 'emerald',
                'sort_order' => 20,
                'is_terminal' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'code' => 'failed',
                'name' => 'ไม่ผ่าน',
                'color' => 'rose',
                'sort_order' => 30,
                'is_terminal' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_statuses');
    }
};
