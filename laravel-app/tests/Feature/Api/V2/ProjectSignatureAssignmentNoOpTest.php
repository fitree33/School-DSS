<?php

namespace Tests\Feature\Api\V2;

use App\Models\AuditLog;
use App\Models\ProjectSignatureSlot;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\BuildsProjectSignatureSlots;
use Tests\TestCase;

class ProjectSignatureAssignmentNoOpTest extends TestCase
{
    use BuildsProjectSignatureSlots;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectSignatures();
    }

    #[DataProvider('assigneeStates')]
    public function test_same_target_and_current_revision_is_a_no_op_after_assignee_state_changes(string $state): void
    {
        $manager = $this->signatureUser('director');
        $assignee = $this->signatureUser('deputy_director');
        $project = $this->signatureProject($manager);
        $url = "/api/v2/projects/{$project->id}/signature-slots/deputy_director/assignment";

        $this->actingAs($manager)->putJson($url, $this->signaturePayload($assignee->id))
            ->assertOk()
            ->assertJsonPath('data.assignment_revision', 1)
            ->assertJsonPath('data.assigned_user_id', $assignee->id);

        if ($state === 'inactive') {
            $assignee->update(['is_active' => false]);
        } elseif ($state === 'soft_deleted') {
            $assignee->delete();
        } elseif ($state === 'restored') {
            $assignee->delete();
            $assignee->restore();
        } elseif ($state === 'role_changed') {
            $assignee->update(['role_id' => Role::query()->where('code', 'teacher')->firstOrFail()->id]);
        }

        $slot = ProjectSignatureSlot::query()->where('project_id', $project->id)
            ->where('slot_code', 'deputy_director')->firstOrFail();
        $before = $slot->getRawOriginal();
        $auditsBefore = AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->travel(1)->minute();

        $response = $this->putJson($url, $this->signaturePayload($assignee->id, 1));

        $this->assertSame($before, $slot->refresh()->getRawOriginal());
        $this->assertSame($auditsBefore, AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $response->assertOk()
            ->assertJsonPath('data.assignment_revision', 1)
            ->assertJsonPath('data.assigned_user_id', $assignee->id)
            ->assertJsonPath('data.assignee_status', match ($state) {
                'inactive' => 'inactive',
                'soft_deleted' => 'soft_deleted',
                'role_changed' => 'ineligible',
                default => 'eligible',
            });

        if ($state === 'soft_deleted') {
            $response->assertJsonPath('data.assignee', null);
        }

        $this->putJson($url, $this->signaturePayload($assignee->id, 0))
            ->assertConflict()->assertJsonPath('code', 'assignment_revision_conflict');
        $this->assertSame($before, $slot->refresh()->getRawOriginal());
        $this->assertSame($auditsBefore, AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_current_empty_clear_is_a_no_op_but_stale_empty_clear_conflicts(): void
    {
        $manager = $this->signatureUser('director');
        $assignee = $this->signatureUser();
        $project = $this->signatureProject($manager);
        $url = "/api/v2/projects/{$project->id}/signature-slots/project_proposer/assignment";
        $slot = ProjectSignatureSlot::query()->where('project_id', $project->id)
            ->where('slot_code', 'project_proposer')->firstOrFail();
        $before = $slot->getRawOriginal();
        $auditsBefore = AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->travel(1)->minute();

        $this->actingAs($manager)->putJson($url, $this->signaturePayload(null, 0))
            ->assertOk()->assertJsonPath('data.assignment_revision', 0)->assertJsonPath('data.assignee_status', 'unassigned');
        $this->assertSame($before, $slot->refresh()->getRawOriginal());
        $this->assertSame($auditsBefore, AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all());

        $this->putJson($url, $this->signaturePayload($assignee->id, 0))->assertOk();
        $this->putJson($url, $this->signaturePayload(null, 1))->assertOk()->assertJsonPath('data.assignment_revision', 2);
        $before = $slot->refresh()->getRawOriginal();
        $auditsBefore = AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->travel(1)->minute();

        $this->putJson($url, $this->signaturePayload(null, 2))->assertOk()->assertJsonPath('data.assignment_revision', 2);
        $this->putJson($url, $this->signaturePayload(null, 0))
            ->assertConflict()->assertJsonPath('code', 'assignment_revision_conflict');
        $this->assertSame($before, $slot->refresh()->getRawOriginal());
        $this->assertSame($auditsBefore, AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    #[DataProvider('ineligibleStates')]
    public function test_actual_change_to_an_ineligible_candidate_is_rejected_without_writes(string $state): void
    {
        $manager = $this->signatureUser('director');
        $current = $this->signatureUser('deputy_director');
        $candidate = $this->signatureUser('deputy_director');
        $project = $this->signatureProject($manager);
        $url = "/api/v2/projects/{$project->id}/signature-slots/deputy_director/assignment";
        $this->actingAs($manager)->putJson($url, $this->signaturePayload($current->id, 0))->assertOk();

        if ($state === 'inactive') {
            $candidate->update(['is_active' => false]);
        } elseif ($state === 'soft_deleted') {
            $candidate->delete();
        } else {
            $candidate->update(['role_id' => Role::query()->where('code', 'teacher')->firstOrFail()->id]);
        }

        $slot = ProjectSignatureSlot::query()->where('project_id', $project->id)
            ->where('slot_code', 'deputy_director')->firstOrFail();
        $before = $slot->getRawOriginal();
        $auditsBefore = AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->putJson($url, $this->signaturePayload($candidate->id, 1))
            ->assertUnprocessable()->assertJsonPath('code', 'signature_candidate_ineligible');
        $this->assertSame($before, $slot->refresh()->getRawOriginal());
        $this->assertSame($auditsBefore, AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public static function ineligibleStates(): array
    {
        return [
            'inactive' => ['inactive'],
            'soft deleted' => ['soft_deleted'],
            'role changed' => ['role_changed'],
        ];
    }

    public static function assigneeStates(): array
    {
        return [
            'active' => ['active'],
            'inactive' => ['inactive'],
            'soft deleted' => ['soft_deleted'],
            'restored' => ['restored'],
            'role changed' => ['role_changed'],
        ];
    }
}
