<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect([
            ['code' => 'projects.view_all', 'name' => 'ดูทุกโครงการ'],
            ['code' => 'projects.view_department', 'name' => 'ดูโครงการภายในฝ่าย'],
            ['code' => 'projects.create', 'name' => 'สร้างโครงการ'],
            ['code' => 'projects.edit_all', 'name' => 'แก้ไขทุกโครงการ'],
            ['code' => 'projects.edit_department', 'name' => 'แก้ไขโครงการภายในฝ่าย'],
            ['code' => 'projects.delete_all', 'name' => 'ลบทุกโครงการ'],
            ['code' => 'projects.delete_department', 'name' => 'ลบโครงการภายในฝ่าย'],
            ['code' => 'projects.manage_access', 'name' => 'จัดการสิทธิ์โครงการ'],
            ['code' => 'projects.evaluate', 'name' => 'ประเมินโครงการ'],
            ['code' => 'budgets.manage', 'name' => 'จัดการงบประมาณ'],
            ['code' => 'users.manage', 'name' => 'จัดการผู้ใช้งาน'],
        ])->mapWithKeys(function (array $permission) {
            $model = Permission::firstOrCreate(
                ['code' => $permission['code']],
                ['name' => $permission['name']]
            );

            return [$permission['code'] => $model];
        });

        $roles = [
            'director' => [
                'name' => 'ผู้อำนวยการ',
                'permissions' => $permissions->keys()->all(),
            ],
            'deputy_director' => [
                'name' => 'รองผู้อำนวยการ',
                'permissions' => [
                    'projects.view_all', 'projects.create', 'projects.edit_all',
                    'projects.manage_access', 'projects.evaluate',
                ],
            ],
            'department_head' => [
                'name' => 'หัวหน้าฝ่าย',
                'permissions' => [
                    'projects.view_department', 'projects.create',
                    'projects.edit_department', 'projects.delete_department',
                    'projects.evaluate',
                ],
            ],
            'teacher' => [
                'name' => 'ครู',
                'permissions' => ['projects.create'],
            ],
        ];

        foreach ($roles as $code => $definition) {
            $role = Role::firstOrCreate(
                ['code' => $code],
                ['name' => $definition['name']]
            );

            $role->permissions()->syncWithoutDetaching(
                $permissions->only($definition['permissions'])->pluck('id')->all()
            );
        }

        $teacherRole = Role::where('code', 'teacher')->firstOrFail();
        User::whereNull('role_id')->update(['role_id' => $teacherRole->id]);
    }
}
