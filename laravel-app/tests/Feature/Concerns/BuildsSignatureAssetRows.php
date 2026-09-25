<?php

namespace Tests\Feature\Concerns;

use App\Models\SignatureAsset;
use App\Models\User;
use Illuminate\Support\Str;

trait BuildsSignatureAssetRows
{
    /** Schema fixtures deliberately do not claim to be backed by image files. */
    protected function signatureAssetRow(?User $owner = null, array $overrides = []): array
    {
        $owner ??= User::factory()->create(['is_active' => true]);

        return array_replace([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'storage_key' => bin2hex(random_bytes(32)).'.png',
            'sha256' => str_repeat('a', 64),
            'size_bytes' => 123,
            'width' => 12,
            'height' => 8,
            'mime_type' => 'image/png',
            'normalization_version' => 'gd-png-v1',
            'status' => 'active',
            'retired_at' => null,
            'retired_by' => null,
            'retirement_reason' => null,
            'created_at' => '2026-09-14 01:02:03.000000',
            'updated_at' => '2026-09-14 01:02:03.000000',
        ], $overrides);
    }

    protected function persistedSignatureAsset(?User $owner = null, array $overrides = []): SignatureAsset
    {
        return SignatureAsset::query()->create($this->signatureAssetRow($owner, $overrides));
    }

    protected function retirementValues(SignatureAsset $asset, ?string $reason = null): array
    {
        return [
            'status' => 'retired',
            'retired_at' => '2026-09-14 02:03:04.000000',
            'retired_by' => $asset->owner_id,
            'retirement_reason' => $reason,
            'updated_at' => '2026-09-14 02:03:04.000000',
        ];
    }
}
