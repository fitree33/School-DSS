<?php

namespace App\Services\Signatures;

use App\Exceptions\ApiProblemException;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectSignatureSlot;
use App\Models\SignatureAsset;
use App\Models\SignaturePlacement;
use App\Models\User;
use App\Services\Documents\DocumentPdfPageCounter;
use App\Services\Projects\ProjectSignatureSlotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SignaturePlacementService
{
    public function __construct(
        private readonly ProjectSignatureSlotService $slots,
        private readonly DocumentPdfPageCounter $pages,
    ) {}

    public function context(User $actor, Project $project, ProjectDocument $document, DocumentVersion $version): array
    {
        $this->authorizeContext($actor, $project, $document, $version);
        $slots = $this->slots->slotsFor($project);

        return [
            'page_count' => $this->pages->count($version),
            'slots' => $slots,
            // Another signer's asset identifier is never included, even after reassignment.
            'placements' => SignaturePlacement::query()->where('document_version_id', $version->id)
                ->where('updated_by', $actor->id)
                ->whereHas('asset', fn ($query) => $query->where('owner_id', $actor->id))
                ->with(['asset', 'slot'])->get()->keyBy('project_signature_slot_id'),
            'assets' => SignatureAsset::query()->where('owner_id', $actor->id)->where('status', 'active')
                ->orderByDesc('created_at')->orderByDesc('id')->get(),
        ];
    }

    public function save(
        User $actor,
        Project $project,
        ProjectDocument $document,
        DocumentVersion $version,
        ProjectSignatureSlot $slot,
        array $attributes,
    ): SignaturePlacement {
        $this->authorizeSlot($actor, $project, $document, $version, $slot);
        $this->validateAttributes($attributes);
        $this->assertRevision($slot, $attributes['assignment_revision']);
        $this->eligibleAsset($actor, $attributes['signature_asset_id']);
        // Version bytes/identity are immutable; slow PDF inspection precedes row locks.
        $pageCount = $this->pages->count($version);
        if ($attributes['page'] > $pageCount) {
            throw ApiProblemException::validation(['page' => ['The page exceeds the PDF page count.']]);
        }

        return DB::transaction(function () use ($actor, $project, $document, $version, $slot, $attributes): SignaturePlacement {
            [$freshActor, $freshProject, $freshSlot] = $this->lockedContext($actor, $project, $document, $version, $slot);
            $this->assertRevision($freshSlot, $attributes['assignment_revision']);
            $asset = $this->eligibleAsset($freshActor, $attributes['signature_asset_id'], lock: true);
            Gate::forUser($freshActor)->authorize('place', [SignaturePlacement::class, $freshProject, $document, $version, $freshSlot, $asset]);

            $placement = SignaturePlacement::query()->where('document_version_id', $version->id)
                ->where('project_signature_slot_id', $freshSlot->id)->lockForUpdate()->first();
            $placement ??= new SignaturePlacement([
                'document_version_id' => $version->id,
                'project_signature_slot_id' => $freshSlot->id,
                'created_by' => $freshActor->id,
            ]);
            $rectangle = [];
            foreach (['x', 'y', 'width', 'height'] as $coordinate) {
                $rectangle[$coordinate] = round($attributes[$coordinate], 8);
            }
            $placement->fill([
                ...$rectangle,
                'signature_asset_id' => $asset->id,
                'assignment_revision' => $freshSlot->assignment_revision,
                'page' => $attributes['page'],
                'updated_by' => $freshActor->id,
            ])->save();

            return $placement->refresh()->load(['asset', 'slot']);
        }, 3);
    }

    public function reset(User $actor, Project $project, ProjectDocument $document, DocumentVersion $version, ProjectSignatureSlot $slot): void
    {
        $this->authorizeSlot($actor, $project, $document, $version, $slot);
        DB::transaction(function () use ($actor, $project, $document, $version, $slot): void {
            [$freshActor, , $freshSlot] = $this->lockedContext($actor, $project, $document, $version, $slot);
            // A newly assigned signer cannot delete a predecessor's private draft.
            SignaturePlacement::query()->where('document_version_id', $version->id)
                ->where('project_signature_slot_id', $freshSlot->id)->where('updated_by', $freshActor->id)->delete();
        }, 3);
    }

    private function lockedContext(User $actor, Project $project, ProjectDocument $document, DocumentVersion $version, ProjectSignatureSlot $slot): array
    {
        // Matches assignment's project -> user -> slot order and retirement's user -> asset order.
        $freshProject = Project::query()->lockForUpdate()->findOrFail($project->id);
        $freshActor = User::withTrashed()->lockForUpdate()->findOrFail($actor->id);
        $freshActor->load('role.permissions');
        $freshSlot = ProjectSignatureSlot::query()->lockForUpdate()->findOrFail($slot->id);
        $this->authorizeSlot($freshActor, $freshProject, $document, $version, $freshSlot);
        $this->slots->slotsFor($freshProject);

        return [$freshActor, $freshProject, $freshSlot];
    }

    private function authorizeContext(User $actor, Project $project, ProjectDocument $document, DocumentVersion $version): void
    {
        abort_unless((int) $document->project_id === (int) $project->id
            && (int) $version->project_document_id === (int) $document->id, 404);
        abort_unless(Gate::forUser($actor)->allows('viewAny', [SignaturePlacement::class, $project, $document, $version]), 404);
    }

    private function authorizeSlot(User $actor, Project $project, ProjectDocument $document, DocumentVersion $version, ProjectSignatureSlot $slot): void
    {
        $this->authorizeContext($actor, $project, $document, $version);
        abort_unless((int) $slot->project_id === (int) $project->id, 404);
        Gate::forUser($actor)->authorize('place', [SignaturePlacement::class, $project, $document, $version, $slot]);
    }

    private function eligibleAsset(User $actor, string $publicId, bool $lock = false): SignatureAsset
    {
        $query = SignatureAsset::query()->where('owner_id', $actor->id)->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $asset = $query->firstOrFail();
        if (! $asset->isEligibleForSigning()) {
            throw ApiProblemException::validation(['signature_asset_id' => ['Select an active signature asset.']]);
        }

        return $asset;
    }

    private function assertRevision(ProjectSignatureSlot $slot, int $revision): void
    {
        if ($revision !== $slot->assignment_revision) {
            throw new ApiProblemException('The signature assignment changed. Reload the placement screen.', 'signature_assignment_changed', 409);
        }
    }

    private function validateAttributes(array $attributes): void
    {
        $required = ['signature_asset_id', 'assignment_revision', 'page', 'x', 'y', 'width', 'height'];
        if (array_diff($required, array_keys($attributes)) !== [] || array_diff(array_keys($attributes), $required) !== []
            || ! is_string($attributes['signature_asset_id'])
            || ! is_int($attributes['page']) || $attributes['page'] < 1 || $attributes['page'] > 4294967295
            || ! is_int($attributes['assignment_revision']) || $attributes['assignment_revision'] < 1 || $attributes['assignment_revision'] > 4294967295) {
            throw ApiProblemException::validation(['placement' => ['Invalid placement fields.']]);
        }
        $units = [];
        foreach (['x', 'y', 'width', 'height'] as $coordinate) {
            $value = $attributes[$coordinate];
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 1) {
                throw ApiProblemException::validation([$coordinate => ['A normalized number between zero and one is required.']]);
            }
            $units[$coordinate] = (int) round($value * 100000000);
        }
        if ($units['width'] < 1 || $units['height'] < 1 || $units['x'] + $units['width'] > 100000000 || $units['y'] + $units['height'] > 100000000) {
            throw ApiProblemException::validation(['placement' => ['A positive signature rectangle must fit inside the page.']]);
        }
    }
}
