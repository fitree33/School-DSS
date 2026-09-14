<?php

namespace Tests\Feature\Api\V2;

use App\Enums\ProjectSignatureSlotCode;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\ProjectAccess;
use App\Models\ProjectSignatureSlot;
use App\Models\Role;
use App\Models\User;
use App\Services\Projects\ProjectSignatureSlotService;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectSignaturePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Concerns\BuildsProjectSignatureSlots;
use Tests\TestCase;

class ProjectSignatureAuthorizationTest extends TestCase
{
    use BuildsProjectSignatureSlots;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectSignatures();
    }

    public function test_permission_seed_is_additive_idempotent_and_grants_only_two_existing_roles(): void
    {
        $roles = Role::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->assertSame(['department_head', 'deputy_director', 'director', 'teacher'], Role::query()->orderBy('code')->pluck('code')->all());
        $teacher = Role::query()->where('code', 'teacher')->firstOrFail();
        $extra = Permission::query()->create(['code' => 'fixture.existing', 'name' => 'Existing custom grant']);
        $teacher->permissions()->attach($extra);
        $grants = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all();
        $permissions = Permission::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->travel(1)->day();

        $this->seed(ProjectSignaturePermissionSeeder::class);
        $this->seed(ProjectSignaturePermissionSeeder::class);
        $this->seed(AuthorizationSeeder::class);

        $this->assertSame($roles, Role::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($permissions, Permission::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($grants, DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($row) => (array) $row)->all());
        $permission = Permission::query()->where('code', 'projects.signatures.manage')->firstOrFail();
        $this->assertSame(['deputy_director', 'director'], $permission->roles()->orderBy('code')->pluck('code')->all());
    }

    public function test_signature_seeder_reports_missing_required_role_without_creating_one(): void
    {
        $director = Role::query()->where('code', 'director')->firstOrFail();
        $director->permissions()->detach();
        $director->delete();
        $before = Permission::query()->orderBy('id')->get()->map->getRawOriginal()->all();

        try {
            $this->seed(ProjectSignaturePermissionSeeder::class);
            $this->fail('A missing seeded role must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires existing role [director]', $exception->getMessage());
        }

        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseMissing('roles', ['code' => 'director']);
        $this->assertSame($before, Permission::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    #[DataProvider('roleCodes')]
    public function test_owner_update_edit_all_and_high_role_cannot_bypass_signature_permission(string $roleCode): void
    {
        $actor = $this->signatureUser($roleCode);
        $role = $actor->role;
        $role->permissions()->detach(Permission::query()->where('code', 'projects.signatures.manage')->value('id'));
        $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('code', ['projects.view_all', 'projects.edit_all'])->pluck('id')->all());
        $actor = $actor->fresh();
        $project = $this->signatureProject($actor);
        $target = $this->signatureUser();
        $audits = AuditLog::query()->count();
        $this->assertTrue($actor->can('update', $project));
        $this->assertTrue($actor->hasPermission('projects.edit_all'));
        $this->assertFalse($actor->hasPermission('projects.signatures.manage'));

        $this->actingAs($actor)->getJson("/api/v2/projects/{$project->id}/signature-slots")->assertOk()->assertJsonCount(4, 'data');
        $this->getJson("/api/v2/projects/{$project->id}/signature-slots/project_proposer/candidates?q=Fixture")->assertForbidden();
        $this->putJson("/api/v2/projects/{$project->id}/signature-slots/project_proposer/assignment", $this->signaturePayload($target->id))->assertForbidden();

        $this->assertSame(4, ProjectSignatureSlot::query()->where('project_id', $project->id)->whereNull('assigned_user_id')->where('assignment_revision', 0)->count());
        $this->assertDatabaseCount('audit_logs', $audits);
    }

    public static function roleCodes(): array
    {
        return [
            'teacher owner' => ['teacher'],
            'department head' => ['department_head'],
            'deputy director' => ['deputy_director'],
            'director' => ['director'],
        ];
    }

    public function test_delegated_project_edit_does_not_grant_signature_management(): void
    {
        $owner = $this->signatureUser();
        $actor = $this->signatureUser();
        $project = $this->signatureProject($owner);
        ProjectAccess::query()->create([
            'project_id' => $project->id, 'user_id' => $actor->id,
            'can_view' => true, 'can_edit' => true, 'granted_by' => $owner->id,
        ]);
        $this->assertTrue($actor->can('update', $project));
        $this->actingAs($actor)->putJson("/api/v2/projects/{$project->id}/signature-slots/project_proposer/assignment", $this->signaturePayload($actor->id))->assertForbidden();
        $this->assertDatabaseCount('project_access', 1);
    }

    public function test_signature_permission_without_project_visibility_cannot_cross_project_boundary(): void
    {
        $actor = $this->signatureUser();
        $actor->role->permissions()->syncWithoutDetaching([Permission::query()->where('code', 'projects.signatures.manage')->firstOrFail()->id]);
        $actor = $actor->fresh();
        $own = $this->signatureProject($actor);
        $foreign = $this->signatureProject($this->signatureUser());
        $target = $this->signatureUser();
        $before = ProjectSignatureSlot::query()->where('project_id', $foreign->id)->orderBy('id')->get()->map->getRawOriginal()->all();
        $audits = AuditLog::query()->count();

        $this->actingAs($actor)->getJson("/api/v2/projects/{$own->id}/signature-slots")->assertOk();
        $this->getJson("/api/v2/projects/{$foreign->id}/signature-slots")->assertNotFound();
        $this->getJson("/api/v2/projects/{$foreign->id}/signature-slots/project_proposer/candidates?q=Fixture")->assertNotFound();
        $this->putJson("/api/v2/projects/{$foreign->id}/signature-slots/project_proposer/assignment", $this->signaturePayload($target->id))->assertNotFound();
        $foreignSlot = ProjectSignatureSlot::query()->where('project_id', $foreign->id)->firstOrFail();
        $this->putJson("/api/v2/projects/{$own->id}/signature-slots/{$foreignSlot->id}/assignment", $this->signaturePayload($target->id))->assertNotFound();
        $this->putJson("/api/v2/projects/{$own->id}/signature-slots/project_proposer/assignment", $this->signaturePayload($target->id) + ['project_id' => $foreign->id, 'id' => $foreignSlot->id])->assertUnprocessable();

        $this->assertSame($before, ProjectSignatureSlot::query()->where('project_id', $foreign->id)->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('audit_logs', $audits);
        $this->assertDatabaseCount('project_access', 0);
    }

    public function test_deleted_project_is_unavailable_even_to_a_signature_manager(): void
    {
        $manager = $this->signatureUser('director');
        $project = $this->signatureProject($manager);
        $project->delete();
        $audits = AuditLog::query()->count();

        $this->actingAs($manager)->getJson("/api/v2/projects/{$project->id}/signature-slots")->assertNotFound();
        $this->getJson("/api/v2/projects/{$project->id}/signature-slots/director/candidates?q=Fixture")->assertNotFound();
        $this->putJson("/api/v2/projects/{$project->id}/signature-slots/director/assignment", $this->signaturePayload($manager->id))->assertNotFound();

        $this->assertDatabaseCount('project_signature_slots', 4);
        $this->assertDatabaseCount('audit_logs', $audits);
    }

    #[DataProvider('managerRoleCodes')]
    public function test_each_seeded_signature_manager_can_assign_on_a_visible_project(string $roleCode): void
    {
        $manager = $this->signatureUser($roleCode);
        $project = $this->signatureProject($this->signatureUser());
        $candidate = $this->signatureUser();

        $this->actingAs($manager)->putJson("/api/v2/projects/{$project->id}/signature-slots/project_proposer/assignment", $this->signaturePayload($candidate->id))
            ->assertOk()->assertJsonPath('data.assigned_user_id', $candidate->id)->assertJsonPath('data.assignment_revision', 1);
    }

    public static function managerRoleCodes(): array
    {
        return ['director' => ['director'], 'deputy director' => ['deputy_director']];
    }

    #[DataProvider('revokedActorStates')]
    public function test_assignment_service_revalidates_a_cached_actor_against_current_database_state(string $state): void
    {
        $actor = $this->signatureUser('director');
        $project = $this->signatureProject($actor);
        $candidate = $this->signatureUser();
        $actor->load('role.permissions');
        $this->assertTrue($actor->can('manageSignatures', $project));
        if ($state === 'permission_revoked') {
            DB::table('role_permissions')->where('role_id', $actor->role_id)
                ->where('permission_id', Permission::query()->where('code', 'projects.signatures.manage')->value('id'))->delete();
        } elseif ($state === 'inactive') {
            User::query()->whereKey($actor->id)->update(['is_active' => false]);
        } else {
            User::query()->whereKey($actor->id)->update(['deleted_at' => now()]);
        }
        $this->assertTrue($actor->can('manageSignatures', $project), 'The caller deliberately retains stale actor state.');
        $before = ProjectSignatureSlot::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $audits = AuditLog::query()->count();

        try {
            app(ProjectSignatureSlotService::class)->updateAssignment($actor, $project, ProjectSignatureSlotCode::ProjectProposer, $candidate->id, 0);
            $this->fail('Current actor authorization must be enforced within the transaction.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('permission_revoked', $state);
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($before, ProjectSignatureSlot::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('audit_logs', $audits);
        $this->assertDatabaseCount('project_access', 0);
    }

    public static function revokedActorStates(): array
    {
        return [
            'permission revoked' => ['permission_revoked'],
            'inactive actor' => ['inactive'],
            'soft deleted actor' => ['soft_deleted'],
        ];
    }
}
