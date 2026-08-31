<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationScoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluation_criterion_id' => $this->criteria_id,
            'score' => $this->score,
            'comment' => $this->comment,
            'criterion' => new EvaluationCriterionResource($this->whenLoaded('criterion')),
        ];
    }
}
