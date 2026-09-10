<?php

namespace App\Policies;

use App\Enums\DocumentImportStatus;
use App\Models\DocumentImport;
use App\Models\User;

final class DocumentImportPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active === false ? false : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('imports.create')
            || $user->hasPermission('imports.view_department')
            || $user->hasPermission('imports.view_all');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('imports.create');
    }

    public function view(User $user, DocumentImport $documentImport): bool
    {
        return (int) $documentImport->uploaded_by === (int) $user->id
            || $user->hasPermission('imports.view_all')
            || ($user->department_id !== null
                && (int) $documentImport->uploader_department_id === (int) $user->department_id
                && $user->hasPermission('imports.view_department'));
    }

    public function viewOriginal(User $user, DocumentImport $documentImport): bool
    {
        return $this->view($user, $documentImport);
    }

    public function review(User $user, DocumentImport $documentImport): bool
    {
        return $documentImport->status === DocumentImportStatus::NeedsReview
            && $this->canManage($user, $documentImport);
    }

    public function retry(User $user, DocumentImport $documentImport): bool
    {
        return $documentImport->status === DocumentImportStatus::Failed
            && $this->canManage($user, $documentImport);
    }

    public function confirm(User $user, DocumentImport $documentImport): bool
    {
        return in_array($documentImport->status, [
            DocumentImportStatus::NeedsReview,
            DocumentImportStatus::Confirmed,
        ], true)
            && $user->hasPermission('projects.create')
            && $this->canManage($user, $documentImport);
    }

    private function canManage(User $user, DocumentImport $documentImport): bool
    {
        return (int) $documentImport->uploaded_by === (int) $user->id
            || $user->hasPermission('imports.manage_all')
            || ($user->department_id !== null
                && (int) $documentImport->uploader_department_id === (int) $user->department_id
                && $user->hasPermission('imports.manage_department'));
    }
}
