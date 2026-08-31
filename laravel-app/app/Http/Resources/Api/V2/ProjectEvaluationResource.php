<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectEvaluationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'round' => (int) $this->round,
            'comment' => $this->comment,
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'total_score' => $this->total_score,
            'maximum_score' => $this->maximum_score,
            'percentage' => $this->percentage,
            'weighted_percentage' => $this->weighted_percentage,
            'is_finalized' => $this->finalized_at !== null,
            'finalized_at' => $this->finalized_at?->toISOString(),
            'evaluator' => $this->whenLoaded('evaluator', fn () => $this->evaluator ? [
                'id' => $this->evaluator->id,
                'name' => $this->evaluator->name,
            ] : null),
            'finalized_by' => $this->whenLoaded('finalizer', fn () => $this->finalizer ? [
                'id' => $this->finalizer->id,
                'name' => $this->finalizer->name,
            ] : null),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'project_code' => $this->project->project_code,
                'name' => $this->project->name,
                'department' => $this->project->relationLoaded('department') && $this->project->department ? [
                    'id' => $this->project->department->id,
                    'name' => $this->project->department->name,
                ] : null,
                'fiscal_year' => $this->project->relationLoaded('fiscalYear') && $this->project->fiscalYear ? [
                    'id' => $this->project->fiscalYear->id,
                    'year' => $this->project->fiscalYear->year,
                    'is_locked' => (bool) $this->project->fiscalYear->is_locked,
                ] : null,
                'evaluation_status' => $this->project->relationLoaded('evaluationStatus') && $this->project->evaluationStatus ? [
                    'id' => $this->project->evaluationStatus->id,
                    'code' => $this->project->evaluationStatus->code,
                    'name' => $this->project->evaluationStatus->name,
                    'color' => $this->project->evaluationStatus->color,
                ] : null,
            ]),
            'framework' => new EvaluationFrameworkResource($this->whenLoaded('framework')),
            'scores' => EvaluationScoreResource::collection($this->whenLoaded('scores')),
            'result' => new ProjectEvaluationResultResource($this->whenLoaded('result')),
            'abilities' => [
                'view' => $user?->can('view', $this->resource) ?? false,
                'update' => $user?->can('update', $this->resource) ?? false,
                'finalize' => $user?->can('finalize', $this->resource) ?? false,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
