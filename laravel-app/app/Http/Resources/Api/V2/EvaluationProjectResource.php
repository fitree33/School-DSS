<?php

namespace App\Http\Resources\Api\V2;

use App\Models\ProjectEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canViewEvaluations = $user?->can('viewAny', ProjectEvaluation::class) === true
            && ($user?->can('view', $this->resource) ?? false);
        $latestResult = $this->resource->relationLoaded('latestEvaluationResult')
            ? $this->latestEvaluationResult
            : null;
        $latestEvaluation = $latestResult?->relationLoaded('evaluation')
            ? $latestResult->evaluation
            : null;

        if ($canViewEvaluations && $latestEvaluation !== null) {
            $latestEvaluation->setRelation('project', $this->resource);
            $canViewEvaluations = $user?->can('view', $latestEvaluation) ?? false;
            $latestEvaluation->unsetRelation('project');
            $latestEvaluation->setRelation('result', $latestResult);
        }

        return [
            'id' => $this->id,
            'project_code' => $this->project_code,
            'name' => $this->name,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'fiscal_year' => $this->whenLoaded('fiscalYear', fn () => $this->fiscalYear ? [
                'id' => $this->fiscalYear->id,
                'year' => $this->fiscalYear->year,
                'is_locked' => (bool) $this->fiscalYear->is_locked,
            ] : null),
            'evaluation_status' => $this->whenLoaded('evaluationStatus', fn () => $this->evaluationStatus ? [
                'id' => $this->evaluationStatus->id,
                'code' => $this->evaluationStatus->code,
                'name' => $this->evaluationStatus->name,
                'color' => $this->evaluationStatus->color,
                'is_terminal' => (bool) $this->evaluationStatus->is_terminal,
            ] : null),
            'latest_evaluation' => $this->when(
                $canViewEvaluations && $latestEvaluation !== null,
                fn () => new ProjectEvaluationResource($latestEvaluation),
            ),
            'evaluation_count' => (int) ($this->evaluation_count ?? 0),
            'abilities' => [
                'view_evaluations' => $canViewEvaluations,
                'create_evaluation' => $user?->can(
                    'create',
                    [ProjectEvaluation::class, $this->resource],
                ) ?? false,
            ],
        ];
    }
}
