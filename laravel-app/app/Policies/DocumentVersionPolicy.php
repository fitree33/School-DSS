<?php

namespace App\Policies;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DocumentVersionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active === false ? false : null;
    }

    public function download(
        User $user,
        DocumentVersion $version,
        ?ProjectDocument $document = null,
        ?Project $project = null,
    ): bool {
        $document ??= $version->document;
        $project ??= $document?->project;

        if ($document === null || $project === null || $project->trashed()
            || (int) $version->project_document_id !== (int) $document->id
            || (int) $document->project_id !== (int) $project->id
            || ! Gate::forUser($user)->allows('view', $project)) {
            return false;
        }

        if ($document->source_import_id !== null) {
            $sourceImport = $document->sourceImport;

            return $sourceImport !== null
                && Gate::forUser($user)->allows('viewOriginal', $sourceImport);
        }

        return true;
    }
}
