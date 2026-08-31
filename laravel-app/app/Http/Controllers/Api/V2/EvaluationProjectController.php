<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\EvaluationProjectIndexRequest;
use App\Http\Resources\Api\V2\EvaluationProjectResource;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EvaluationProjectController extends Controller
{
    public function index(EvaluationProjectIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Project::query()
            ->visibleTo($request->user())
            ->with([
                'department:id,name',
                'fiscalYear:id,year,is_locked',
                'evaluationStatus:id,code,name,color,is_terminal',
                'latestEvaluationResult.status:id,code,name,color,is_terminal',
                'latestEvaluationResult.finalizer:id,name',
                'latestEvaluationResult.evaluation.evaluator:id,name',
                'latestEvaluationResult.evaluation.finalizer:id,name',
                'latestEvaluationResult.evaluation.framework' => fn ($query) => $query->withExists('evaluations'),
                'latestEvaluationResult.evaluation.framework.fiscalYear:id,year,is_locked',
                'latestEvaluationResult.evaluation.framework.criteria',
                'latestEvaluationResult.evaluation.scores.criterion',
            ])
            ->withCount([
                'evaluations as evaluation_count' => fn (Builder $evaluation) => $evaluation
                    ->whereNotNull('evaluation_framework_id'),
            ])
            ->when(
                $filters['fiscal_year_id'] ?? null,
                fn (Builder $builder, $id) => $builder->where('fiscal_year_id', $id),
            )
            ->when(
                $filters['department_id'] ?? null,
                fn (Builder $builder, $id) => $builder->where('department_id', $id),
            )
            ->when(
                $filters['evaluation_status'] ?? null,
                fn (Builder $builder, $code) => $builder->whereHas(
                    'evaluationStatus',
                    fn (Builder $status) => $status->where('code', $code),
                ),
            );

        return EvaluationProjectResource::collection(
            $query->orderByDesc('projects.created_at')
                ->orderByDesc('projects.id')
                ->paginate($filters['per_page'] ?? 15)
                ->withQueryString(),
        );
    }
}
