<?php

namespace Database\Seeders;

use App\Models\ProjectStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProjectStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['name' => 'Draft', 'code' => 'draft', 'color' => 'slate', 'sort_order' => 10, 'is_terminal' => false],
            ['name' => 'Returned for Revision', 'code' => 'returned', 'color' => 'rose', 'sort_order' => 15, 'is_terminal' => false],
            ['name' => 'Pending Deputy Review', 'code' => 'pending_deputy', 'color' => 'amber', 'sort_order' => 20, 'is_terminal' => false],
            ['name' => 'Pending Director Approval', 'code' => 'pending_director', 'color' => 'amber', 'sort_order' => 30, 'is_terminal' => false],
            ['name' => 'Approved', 'code' => 'approved', 'color' => 'emerald', 'sort_order' => 40, 'is_terminal' => false],
            ['name' => 'Rejected', 'code' => 'rejected', 'color' => 'rose', 'sort_order' => 50, 'is_terminal' => true],
            ['name' => 'In Progress', 'code' => 'in_progress', 'color' => 'blue', 'sort_order' => 60, 'is_terminal' => false],
            ['name' => 'Completed', 'code' => 'completed', 'color' => 'violet', 'sort_order' => 70, 'is_terminal' => true],
            ['name' => 'Archived', 'code' => 'archived', 'color' => 'slate', 'sort_order' => 80, 'is_terminal' => true],
        ];

        DB::transaction(function () use ($statuses): void {
            foreach ($statuses as $status) {
                if (ProjectStatus::query()->where('code', $status['code'])->exists()) {
                    continue;
                }

                $sameName = ProjectStatus::query()->where('name', $status['name'])->first();

                if ($sameName) {
                    if ($sameName->code !== null) {
                        throw new RuntimeException(
                            "Project status name [{$status['name']}] is already assigned to code [{$sameName->code}]."
                        );
                    }

                    $sameName->update(['code' => $status['code']]);

                    continue;
                }

                ProjectStatus::create($status);
            }
        });
    }
}
