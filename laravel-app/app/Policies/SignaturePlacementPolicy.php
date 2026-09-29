<?php

namespace App\Policies;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectSignatureSlot;
use App\Models\SignatureAsset;
use App\Models\User;
use App\Services\Projects\ProjectSignatureCandidateService;
use Illuminate\Support\Facades\Gate;

final class SignaturePlacementPolicy
{
    public function __construct(private readonly ProjectSignatureCandidateService $candidates) {}

    public function viewAny(User $user, Project $project, ProjectDocument $document, DocumentVersion $version): bool
    {
        return Gate::forUser($user)->allows('viewSignatures', $project)
            && Gate::forUser($user)->allows('download', [$version, $document, $project]);
    }

    public function place(
        User $user,
        Project $project,
        ProjectDocument $document,
        DocumentVersion $version,
        ProjectSignatureSlot $slot,
        ?SignatureAsset $asset = null,
    ): bool {
        return $this->viewAny($user, $project, $document, $version)
            && (int) $slot->project_id === (int) $project->id
            && $slot->assigned_user_id === (int) $user->id
            && $this->candidates->isEligible($user, $slot->slot_code)
            && ($asset === null || ((int) $asset->owner_id === (int) $user->id && $asset->isEligibleForSigning()));
    }
}
