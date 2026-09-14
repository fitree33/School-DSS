<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProjectSignaturePermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $roleCodes = ['director', 'deputy_director'];
            $roles = Role::query()->whereIn('code', $roleCodes)->lockForUpdate()->get()->keyBy('code');

            foreach ($roleCodes as $code) {
                if (! $roles->has($code)) {
                    throw new RuntimeException("Project signature permission requires existing role [{$code}].");
                }
            }

            $permission = Permission::query()->firstOrCreate(
                ['code' => 'projects.signatures.manage'],
                ['name' => 'Manage project signature assignments']
            );

            foreach ($roleCodes as $code) {
                $roles->get($code)->permissions()->syncWithoutDetaching([$permission->id]);
            }
        });
    }
}
