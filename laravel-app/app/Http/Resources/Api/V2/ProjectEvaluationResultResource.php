<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectEvaluationResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->whenLoaded('status', fn () => $this->status ? [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'name' => $this->status->name,
                'color' => $this->status->color,
                'is_terminal' => (bool) $this->status->is_terminal,
            ] : null),
            'total_score' => $this->total_score,
            'maximum_score' => $this->maximum_score,
            'percentage' => $this->percentage,
            'weighted_percentage' => $this->weighted_percentage,
            'scores_snapshot' => $this->scores_snapshot,
            'decision_note' => $this->decision_note,
            'finalized_by' => $this->whenLoaded('finalizer', fn () => $this->finalizer ? [
                'id' => $this->finalizer->id,
                'name' => $this->finalizer->name,
            ] : null),
            'finalized_at' => $this->finalized_at?->toISOString(),
        ];
    }
}
