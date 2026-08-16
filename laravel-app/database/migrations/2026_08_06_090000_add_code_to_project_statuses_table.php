<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_statuses', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->unique()->after('name');
        });

        foreach ([
            'Draft' => 'draft',
            'Pending' => 'pending_deputy',
            'Approved' => 'approved',
            'Rejected' => 'rejected',
            'In Progress' => 'in_progress',
            'Completed' => 'completed',
            'Archived' => 'archived',
        ] as $name => $code) {
            DB::table('project_statuses')->where('name', $name)->update(['code' => $code]);
        }

        DB::table('project_statuses')->updateOrInsert(
            ['code' => 'pending_director'],
            [
                'name' => 'Pending Director',
                'color' => 'amber',
                'sort_order' => 30,
                'is_terminal' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('project_statuses')->updateOrInsert(
            ['code' => 'returned'],
            [
                'name' => 'Returned for Revision',
                'color' => 'rose',
                'sort_order' => 15,
                'is_terminal' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::table('project_statuses', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
