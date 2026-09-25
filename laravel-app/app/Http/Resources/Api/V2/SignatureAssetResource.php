<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SignatureAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'mime_type' => $this->mime_type,
            'normalization_version' => $this->normalization_version,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'retired_at' => $this->retired_at?->toIso8601String(),
            'eligible_for_signing' => $this->resource->isEligibleForSigning(),
        ];
    }
}
