<?php

namespace App\Policies;

use App\Models\SignatureAsset;
use App\Models\User;

final class SignatureAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists && $user->is_active === true && ! $user->trashed();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, SignatureAsset $asset): bool
    {
        return $this->viewAny($user) && (int) $user->id === (int) $asset->owner_id;
    }

    public function preview(User $user, SignatureAsset $asset): bool
    {
        return $this->view($user, $asset);
    }

    public function retire(User $user, SignatureAsset $asset): bool
    {
        // Already-retired requests are idempotent in the service.
        return $this->view($user, $asset);
    }
}
