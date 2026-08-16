<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_change_another_users_role_and_status(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $director = User::factory()->create([
            'role_id' => Role::where('code', 'director')->value('id'),
        ]);
        $teacher = User::factory()->create([
            'role_id' => Role::where('code', 'teacher')->value('id'),
        ]);
        $deputyRole = Role::where('code', 'deputy_director')->firstOrFail();

        $this->actingAs($director)
            ->put(route('users.update', $teacher), [
                'role_id' => $deputyRole->id,
                'department_id' => null,
                'teacher_code' => 'T-001',
                'is_active' => false,
            ])
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $teacher->id,
            'role_id' => $deputyRole->id,
            'teacher_code' => 'T-001',
            'is_active' => false,
        ]);
    }

    public function test_teacher_cannot_open_user_management(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $teacher = User::factory()->create([
            'role_id' => Role::where('code', 'teacher')->value('id'),
        ]);

        $this->actingAs($teacher)
            ->get(route('users.index'))
            ->assertForbidden();
    }

    public function test_director_cannot_deactivate_or_demote_self(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $directorRole = Role::where('code', 'director')->firstOrFail();
        $teacherRole = Role::where('code', 'teacher')->firstOrFail();
        $director = User::factory()->create(['role_id' => $directorRole->id]);

        $this->actingAs($director)
            ->put(route('users.update', $director), [
                'role_id' => $teacherRole->id,
                'department_id' => null,
                'teacher_code' => null,
                'is_active' => false,
            ])
            ->assertRedirect(route('users.index'));

        $director->refresh();
        $this->assertSame($directorRole->id, $director->role_id);
        $this->assertTrue($director->is_active);
    }
}
