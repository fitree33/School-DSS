<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationCriterionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluation_framework_id' => $this->evaluation_framework_id,
            'name' => $this->name,
            'description' => $this->description,
            'max_score' => $this->max_score,
            'weight' => $this->weight,
            'sort_order' => (int) $this->sort_order,
            'evaluation_method' => $this->evaluation_method,
            'evaluation_tools' => $this->evaluation_tools,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
