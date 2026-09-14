<?php

namespace App\Services\Projects;

use App\Enums\ProjectSignatureSlotCode;
use App\Exceptions\ApiProblemException;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectSignatureSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

class ProjectSignatureSlotService
{
    public const MAX_REVISION = 4294967295;

    public function __construct(private readonly ProjectSignatureCandidateService $candidates) {}

    /**
     * Structural initialization only. Project creation owns its transaction and
     * authorization; maintenance backfill can add blank rows, never assignees.
     *
     * @return Collection<int, ProjectSignatureSlot> Only newly inserted rows.
     */
    public function initializeInTransaction(
        Project $project,
        ?User $actor,
        string $source = 'project_create',
        ?string $runId = null,
    ): Collection {
        if (DB::transactionLevel() < 1 || ! $project->exists
            || ! in_array($source, ['project_create', 'legacy_create', 'backfill'], true)
            || ($source === 'backfill' ? $actor !== null : ! $actor?->exists)) {
            throw new LogicException('Slot initialization requires a persisted project, transaction and matching actor/source.');
        }

        $missing = $this->missingCodes($project, lock: true);
        if ($source !== 'backfill' && count($missing) !== count(ProjectSignatureSlotCode::cases())) {
            throw new LogicException('New project initialization requires an empty slot set.');
        }

        $inserted = new Collection;
        $now = now('UTC');
        foreach ($missing as $code) {
            $inserted->push(ProjectSignatureSlot::query()->create([
                'project_id' => $project->getKey(),
                'slot_code' => $code,
                'slot_no' => $code->slotNo(),
                'assigned_user_id' => null,
                'assignment_revision' => 0,
                'assigned_by' => null,
                'assigned_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        if ($inserted->isNotEmpty()) {
            $newValues = [
                'project_id' => $project->getKey(),
                'actor_id' => $actor?->getKey(),
                'source' => $source,
                'slots' => $inserted->map(fn (ProjectSignatureSlot $slot): array => $this->assignmentSnapshot($slot))->all(),
            ];
            if ($source === 'backfill') {
                $newValues['run_id'] = $runId;
            }
            $this->audit(
                $source === 'backfill' ? 'project_signature_slots.backfilled' : 'project_signature_slots.initialized',
                $project,
                $actor,
                [],
                $newValues,
            );
        }

        return $inserted;
    }

    /**
     * Read raw values before enum/date casts so malformed historical rows fail
     * explicitly, instead of being normalized or silently repaired by backfill.
     *
     * @return array<int, ProjectSignatureSlotCode>
     */
    public function missingCodes(Project $project, bool $lock = false): array
    {
        $query = DB::table('project_signature_slots')->where('project_id', $project->getKey())->orderBy('slot_no');
        if ($lock) {
            $query->lockForUpdate();
        }
        $seen = [];
        foreach ($query->get() as $row) {
            $code = is_string($row->slot_code) ? ProjectSignatureSlotCode::tryFrom($row->slot_code) : null;
            $revision = filter_var($row->assignment_revision, FILTER_VALIDATE_INT);
            $number = filter_var($row->slot_no, FILTER_VALIDATE_INT);
            $blank = $row->assigned_user_id === null && $row->assigned_by === null && $row->assigned_at === null;
            $assigned = $row->assigned_user_id !== null && $row->assigned_by !== null
                && is_string($row->assigned_at) && trim($row->assigned_at) !== '' && $revision >= 1;

            if ($code === null || $number !== $code->slotNo() || isset($seen[$code->value])
                || $revision === false || $revision < 0 || $revision > self::MAX_REVISION
                || (! $blank && ! $assigned)
                || ! is_string($row->created_at) || trim($row->created_at) === ''
                || ! is_string($row->updated_at) || trim($row->updated_at) === '') {
                throw new ApiProblemException('The project signature slot data is inconsistent.', 'signature_slots_inconsistent', 409);
            }
            $seen[$code->value] = true;
        }

        return array_values(array_filter(ProjectSignatureSlotCode::cases(), fn ($code): bool => ! isset($seen[$code->value])));
    }

    /** @return Collection<int, ProjectSignatureSlot> */
    public function slotsFor(Project $project): Collection
    {
        $this->assertComplete($project);

        return ProjectSignatureSlot::query()->where('project_id', $project->getKey())
            ->with(['assignee.role', 'assignee.department'])->orderBy('slot_no')->get();
    }

    public function updateAssignment(
        User $actor,
        Project $project,
        ProjectSignatureSlotCode $code,
        ?int $assignedUserId,
        int $expectedRevision,
    ): ProjectSignatureSlot {
        abort_unless(Gate::forUser($actor)->allows('viewSignatures', $project), 404);
        Gate::forUser($actor)->authorize('manageSignatures', $project);

        if ($expectedRevision < 0 || $expectedRevision > self::MAX_REVISION || ($assignedUserId !== null && $assignedUserId < 1)) {
            throw ApiProblemException::validation(['assignment' => ['Invalid assignment identifier or revision.']]);
        }

        // Retrying a database deadlock retains the client's original revision.
        // It must never turn a stale request into a write of a newer revision.
        return DB::transaction(function () use ($actor, $project, $code, $assignedUserId, $expectedRevision): ProjectSignatureSlot {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->getKey());
            $userIds = array_values(array_unique(array_filter([$actor->getKey(), $assignedUserId])));
            $users = User::withTrashed()->whereKey($userIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $freshActor = $users->get($actor->getKey());
            abort_unless($freshActor instanceof User && ! $freshActor->trashed() && $freshActor->is_active === true, 403);
            $freshActor->load('role.permissions');
            abort_unless(Gate::forUser($freshActor)->allows('viewSignatures', $lockedProject), 404);
            Gate::forUser($freshActor)->authorize('manageSignatures', $lockedProject);
            $this->assertComplete($lockedProject, lock: true);
            $slot = ProjectSignatureSlot::query()->where('project_id', $lockedProject->getKey())
                ->where('slot_code', $code->value)->lockForUpdate()->firstOrFail();

            if ($slot->assignment_revision !== $expectedRevision) {
                throw $this->revisionConflict();
            }

            // An unchanged assignment is a read, including an empty clear.
            // Preserve its revision, provenance and audit history even when the
            // existing assignee is no longer eligible for a new assignment.
            if ($slot->assigned_user_id === $assignedUserId) {
                return $slot->load(['assignee.role', 'assignee.department']);
            }

            if ($assignedUserId !== null) {
                $candidate = $users->get($assignedUserId);
                if (! $candidate instanceof User || ! $this->candidates->isEligible($candidate, $code)) {
                    throw new ApiProblemException('The selected user is not eligible for this slot.', 'signature_candidate_ineligible', 422);
                }
            }

            if ($expectedRevision === self::MAX_REVISION) {
                throw new ApiProblemException('The assignment revision cannot be incremented.', 'assignment_revision_exhausted', 409);
            }

            $before = $this->assignmentSnapshot($slot);
            $now = now('UTC')->format('Y-m-d H:i:s.u');
            $changed = DB::table('project_signature_slots')->where('id', $slot->getKey())
                ->where('project_id', $lockedProject->getKey())->where('slot_code', $code->value)
                ->where('assignment_revision', $expectedRevision)->update([
                    'assigned_user_id' => $assignedUserId,
                    'assignment_revision' => $expectedRevision + 1,
                    'assigned_by' => $assignedUserId === null ? null : $freshActor->getKey(),
                    'assigned_at' => $assignedUserId === null ? null : $now,
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) {
                throw $this->revisionConflict();
            }

            $action = $assignedUserId === null ? 'unassigned' : ($before['assigned_user_id'] === null ? 'assigned' : 'reassigned');
            $slot->refresh();
            $this->audit('project_signature_slot.'.$action, $slot, $freshActor, $before, [
                ...$this->assignmentSnapshot($slot),
                'actor_id' => $freshActor->getKey(),
                'source' => 'assignment_api',
            ]);

            return $slot->load(['assignee.role', 'assignee.department']);
        }, 3);
    }

    private function assertComplete(Project $project, bool $lock = false): void
    {
        if ($this->missingCodes($project, $lock) !== []) {
            throw new ApiProblemException('The project signature slots require backfill.', 'signature_slots_not_initialized', 409);
        }
    }

    /** @return array<string, mixed> */
    private function assignmentSnapshot(ProjectSignatureSlot $slot): array
    {
        return [
            'id' => $slot->getKey(),
            'project_id' => $slot->project_id,
            'slot_code' => $slot->slot_code->value,
            'slot_no' => $slot->slot_no,
            'assigned_user_id' => $slot->assigned_user_id,
            'assignment_revision' => $slot->assignment_revision,
            'assigned_by' => $slot->assigned_by,
            'assigned_at' => $slot->getRawOriginal('assigned_at'),
        ];
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, Model $subject, ?User $actor, array $before, array $after): void
    {
        $http = ! app()->runningInConsole();
        AuditLog::query()->create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'old_values' => $before ?: null,
            'new_values' => $after,
            'ip_address' => $http ? request()->ip() : null,
            'user_agent' => $http ? request()->userAgent() : null,
        ]);
    }

    private function revisionConflict(): ApiProblemException
    {
        return new ApiProblemException('The assignment has changed. Read the current slots before trying again.', 'assignment_revision_conflict', 409);
    }
}
