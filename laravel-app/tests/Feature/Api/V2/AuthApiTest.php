<?php

namespace Tests\Feature\Api\V2;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'http://localhost:5173';

    public function test_stateful_login_me_and_logout_flow(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create([
            'email' => 'teacher@example.test',
            'password' => 'password',
            'role_id' => Role::query()->where('code', 'teacher')->value('id'),
            'is_active' => true,
        ]);

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/v2/auth/login', [
                'email' => $user->email,
                'password' => 'password',
                'remember' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role.code', 'teacher')
            ->assertJsonPath('data.permissions', ['imports.create', 'projects.create'])
            ->assertJsonMissingPath('data.tokens');

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->getJson('/api/v2/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/v2/auth/logout')
            ->assertNoContent();

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->getJson('/api/v2/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_authentication_and_validation_errors_use_the_v2_contract(): void
    {
        $this->getJson('/api/v2/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Authentication is required.',
                'code' => 'unauthenticated',
            ]);

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/v2/auth/login', [
                'email' => 'missing@example.test',
                'password' => 'incorrect',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure([
                'message',
                'code',
                'errors' => ['email'],
            ]);
    }

    public function test_inactive_accounts_are_rejected_by_protected_v2_routes(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)
            ->getJson('/api/v2/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    }

    public function test_personal_bearer_tokens_cannot_authenticate_v2_session_routes(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('phase-2-review')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v2/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Authentication is required.',
                'code' => 'unauthenticated',
            ]);
    }
}
