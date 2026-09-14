<?php

namespace Tests\Feature\Api\V2;

use App\Models\AuditLog;
use App\Models\ProjectSignatureSlot;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\BuildsProjectSignatureSlots;
use Tests\TestCase;

class ProjectSignatureAssignmentTest extends TestCase
{
    use BuildsProjectSignatureSlots;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectSignatures();
    }

    public function test_assignment_lifecycle_revisions_and_audits_are_atomic_without_granting_project_access(): void
    {
        $manager = $this->signatureUser('director');
        $a = $this->signatureUser();
        $b = $this->signatureUser();
        $project = $this->signatureProject($manager);
        $url = "/api/v2/projects/{$project->id}/signature-slots/project_proposer/assignment";
        $slot = ProjectSignatureSlot::query()->where('project_id', $project->id)->where('slot_code', 'project_proposer')->firstOrFail();

        $this->actingAs($manager)->putJson($url, $this->signaturePayload($a->id))->assertOk()
            ->assertJsonPath('data.assigned_user_id', $a->id)->assertJsonPath('data.assignment_revision', 1);
        $this->assertSame($manager->id, $slot->refresh()->assigned_by);
        $this->assertNotNull($slot->assigned_at);
        $this->travel(1)->minute();
        $this->putJson($url, $this->signaturePayload($b->id, 1))->assertOk()
            ->assertJsonPath('data.assigned_user_id', $b->id)->assertJsonPath('data.assignment_revision', 2);
        $this->putJson($url, $this->signaturePayload(null, 2))->assertOk()
            ->assertJsonPath('data.assigned_user_id', null)->assertJsonPath('data.assignment_revision', 3)
            ->assertJsonPath('data.assignee_status', 'unassigned');
        $slot->refresh();
        $this->assertNull($slot->assigned_by);
        $this->assertNull($slot->assigned_at);
        $this->assertSame(3, $slot->assignment_revision);

        $audits = AuditLog::query()->where('auditable_type', ProjectSignatureSlot::class)->orderBy('id')->get();
        $this->assertSame(['project_signature_slot.assigned', 'project_signature_slot.reassigned', 'project_signature_slot.unassigned'], $audits->pluck('action')->all());
        foreach ($audits as $index => $audit) {
            $this->assertSame($manager->id, $audit->user_id);
            $this->assertSame($slot->id, $audit->auditable_id);
            $this->assertSame($project->id, $audit->new_values['project_id']);
            $this->assertSame('project_proposer', $audit->new_values['slot_code']);
            $this->assertSame($index, $audit->old_values['assignment_revision']);
            $this->assertSame($index + 1, $audit->new_values['assignment_revision']);
            $this->assertSame('assignment_api', $audit->new_values['source']);
        }
        $this->assertSame([null, $a->id, $b->id], $audits->map(fn ($audit) => $audit->old_values['assigned_user_id'])->all());
        $this->assertSame([$a->id, $b->id, null], $audits->map(fn ($audit) => $audit->new_values['assigned_user_id'])->all());
        $this->assertDatabaseCount('project_access', 0);
        $this->assertFalse($a->fresh()->can('view', $project->fresh()));
        $this->assertFalse($b->fresh()->can('view', $project->fresh()));
    }

    public function test_the_same_user_can_hold_multiple_eligible_slots_without_receiving_access(): void
    {
        $manager = $this->signatureUser('director');
        $candidate = $this->signatureUser();
        $project = $this->signatureProject($manager);
        $this->actingAs($manager);
        foreach (['project_proposer', 'related_approver'] as $slot) {
            $this->putJson("/api/v2/projects/{$project->id}/signature-slots/{$slot}/assignment", $this->signaturePayload($candidate->id))
                ->assertOk()->assertJsonPath('data.assigned_user_id', $candidate->id)->assertJsonPath('data.assignment_revision', 1);
        }

        $this->assertSame(2, ProjectSignatureSlot::query()->where('project_id', $project->id)->where('assigned_user_id', $candidate->id)->count());
        $this->assertDatabaseCount('project_access', 0);
        $this->actingAs($candidate)->getJson("/api/v2/projects/{$project->id}/signature-slots")->assertNotFound();
    }

    public function test_returning_from_a_to_b_to_a_does_not_allow_an_old_revision_to_write(): void
    {
        $manager = $this->signatureUser('director');
        $a = $this->signatureUser();
        $b = $this->signatureUser();
        $project = $this->signatureProject($manager);
        $url = "/api/v2/projects/{$project->id}/signature-slots/project_proposer/assignment";
        $this->actingAs($manager)->putJson($url, $this->signaturePayload($a->id))->assertOk();
        $this->putJson($url, $this->signaturePayload($b->id, 1))->assertOk();
        $this->putJson($url, $this->signaturePayload($a->id, 2))->assertOk()->assertJsonPath('data.assignment_revision', 3);
        $slot = ProjectSignatureSlot::query()->where('project_id', $project->id)->where('slot_code', 'project_proposer')->firstOrFail();
        $before = $slot->getRawOriginal();
        $auditCount = AuditLog::query()->count();

        $this->putJson($url, $this->signaturePayload($a->id, 1))->assertConflict()->assertJsonPath('code', 'assignment_revision_conflict');
        $this->putJson($url, $this->signaturePayload($b->id, 1))->assertConflict()->assertJsonPath('code', 'assignment_revision_conflict');

        $this->assertSame($before, $slot->refresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', $auditCount);
    }

    public function test_assignee_resource_tracks_inactive_soft_deleted_restored_and_role_ineligible_states_without_mutating_assignment(): void
    {
        $manager = $this->signatureUser('director');
        $assignee = $this->signatureUser('deputy_director');
        $project = $this->signatureProject($manager);
        $this->actingAs($manager)->putJson("/api/v2/projects/{$project->id}/signature-slots/deputy_director/assignment", $this->signaturePayload($assignee->id))->assertOk();
        $slot = ProjectSignatureSlot::query()->where('project_id', $project->id)->where('slot_code', 'deputy_director')->firstOrFail();
        $before = $slot->getRawOriginal();
        $audits = AuditLog::query()->count();
        $url = "/api/v2/projects/{$project->id}/signature-slots";
        $this->getJson($url)->assertOk()->assertJsonPath('data.2.assignee_status', 'eligible')->assertJsonPath('data.2.assignee.id', $assignee->id);

        $assignee->update(['is_active' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.2.assignee_status', 'inactive')->assertJsonPath('data.2.assignee.id', $assignee->id);
        $assignee->delete();
        $this->getJson($url)->assertOk()->assertJsonPath('data.2.assignee_status', 'soft_deleted')->assertJsonPath('data.2.assignee', null)->assertJsonPath('data.2.assigned_user_id', $assignee->id);
        $assignee->restore();
        $this->getJson($url)->assertOk()->assertJsonPath('data.2.assignee_status', 'inactive');
        $assignee->update(['is_active' => true]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.2.assignee_status', 'eligible');
        $assignee->update(['role_id' => Role::query()->where('code', 'teacher')->firstOrFail()->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.2.assignee_status', 'ineligible')->assertJsonPath('data.2.assignee.role.code', 'teacher');

        $this->assertSame($before, $slot->refresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_logs', $audits);
    }

    public function test_audit_exception_rolls_back_assignment_revision_timestamps_and_success_audit(): void
    {
        $manager = $this->signatureUser('director');
        $candidate = $this->signatureUser();
        $project = $this->signatureProject($manager);
        $slot = ProjectSignatureSlot::query()->where('project_id', $project->id)->where('slot_code', 'project_proposer')->firstOrFail();
        $before = $slot->getRawOriginal();
        $audits = AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $reject = true;
        AuditLog::creating(function (AuditLog $audit) use (&$reject): void {
            if ($reject && $audit->action === 'project_signature_slot.assigned') {
                throw new RuntimeException('Forced signature audit failure.');
            }
        });

        try {
            $this->actingAs($manager)->putJson("/api/v2/projects/{$project->id}/signature-slots/project_proposer/assignment", $this->signaturePayload($candidate->id))->assertInternalServerError();
        } finally {
            $reject = false;
        }

        $this->assertSame($before, $slot->refresh()->getRawOriginal());
        $this->assertSame($audits, AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('project_access', 0);
    }

    #[DataProvider('invalidAssignments')]
    public function test_assignment_input_is_a_strict_allowlist_of_json_identifiers_and_revision(array $payload): void
    {
        $manager = $this->signatureUser('director');
        $project = $this->signatureProject($manager);
        $before = ProjectSignatureSlot::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $audits = AuditLog::query()->count();

        $this->actingAs($manager)->putJson("/api/v2/projects/{$project->id}/signature-slots/project_proposer/assignment", $payload)->assertUnprocessable();

        $this->assertSame($before, ProjectSignatureSlot::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('audit_logs', $audits);
    }

    public static function invalidAssignments(): array
    {
        return [
            'missing target' => [['assignment_revision' => 0]],
            'missing revision' => [['assigned_user_id' => null]],
            'string user' => [['assigned_user_id' => '1', 'assignment_revision' => 0]],
            'boolean user' => [['assigned_user_id' => true, 'assignment_revision' => 0]],
            'fractional user' => [['assigned_user_id' => 1.5, 'assignment_revision' => 0]],
            'array user' => [['assigned_user_id' => [1], 'assignment_revision' => 0]],
            'zero user' => [['assigned_user_id' => 0, 'assignment_revision' => 0]],
            'negative user' => [['assigned_user_id' => -1, 'assignment_revision' => 0]],
            'string revision' => [['assigned_user_id' => null, 'assignment_revision' => '0']],
            'boolean revision' => [['assigned_user_id' => null, 'assignment_revision' => false]],
            'fractional revision' => [['assigned_user_id' => null, 'assignment_revision' => 0.5]],
            'null revision' => [['assigned_user_id' => null, 'assignment_revision' => null]],
            'negative revision' => [['assigned_user_id' => null, 'assignment_revision' => -1]],
            'overflow revision' => [['assigned_user_id' => null, 'assignment_revision' => 4294967296]],
            'unsupported fields' => [['assigned_user_id' => null, 'assignment_revision' => 0, 'assigned_by' => 1, 'slot_no' => 4]],
        ];
    }
}
