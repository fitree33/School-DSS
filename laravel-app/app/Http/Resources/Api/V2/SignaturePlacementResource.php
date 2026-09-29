<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SignaturePlacementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signature_slot_id' => $this->project_signature_slot_id,
            'signature_asset_id' => $this->asset->public_id,
            'assignment_revision' => $this->assignment_revision,
            'page' => $this->page,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'updated_at' => $this->updated_at?->toISOString(),
            'stale' => $this->assignment_revision !== $this->slot->assignment_revision
                || $this->updated_by !== $this->slot->assigned_user_id
                || ! $this->asset->isEligibleForSigning(),
        ];
    }
}
