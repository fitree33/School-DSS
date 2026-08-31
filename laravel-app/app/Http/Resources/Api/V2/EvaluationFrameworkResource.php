<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationFrameworkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'version' => $this->version,
            'name' => $this->name,
            'description' => $this->description,
            'fiscal_year' => $this->whenLoaded('fiscalYear', fn () => $this->fiscalYear ? [
                'id' => $this->fiscalYear->id,
                'year' => $this->fiscalYear->year,
                'is_locked' => (bool) $this->fiscalYear->is_locked,
            ] : null),
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'is_used' => (bool) ($this->evaluations_exists ?? false),
            'criteria' => EvaluationCriterionResource::collection($this->whenLoaded('criteria')),
            'abilities' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'create_version' => $user?->can('createVersion', $this->resource) ?? false,
                'activate' => $user?->can('activate', $this->resource) ?? false,
                'deactivate' => $user?->can('deactivate', $this->resource) ?? false,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
