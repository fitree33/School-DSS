<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\FinalizeProjectEvaluationRequest;
use App\Http\Requests\Api\V2\StoreProjectEvaluationRequest;
use App\Http\Requests\Api\V2\UpdateProjectEvaluationRequest;
use App\Http\Resources\Api\V2\ProjectEvaluationResource;
use App\Models\Project;
use App\Models\ProjectEvaluation;
use App\Services\Evaluations\ProjectEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ProjectEvaluationController extends Controller
{
    public function __construct(private readonly ProjectEvaluationService $evaluations) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProjectEvaluation::class);
        $this->authorize('view', $project);

        $evaluations = ProjectEvaluation::query()
            ->where('project_id', $project->id)
            ->whereNotNull('evaluation_framework_id')
            ->with($this->relations())
            ->orderByDesc('round')
            ->orderByDesc('evaluated_at')
            ->orderByDesc('id')
            ->get();

        return ProjectEvaluationResource::collection($evaluations);
    }

    public function store(
        StoreProjectEvaluationRequest $request,
        Project $project,
    ): JsonResponse {
        $evaluation = $this->evaluations->create(
            $request->user(),
            $project,
            $request->validated(),
        );

        return (new ProjectEvaluationResource($evaluation))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', route('api.v2.project-evaluations.show', $evaluation));
    }

    public function show(ProjectEvaluation $evaluation): ProjectEvaluationResource
    {
        $this->authorize('view', $evaluation);

        return new ProjectEvaluationResource($evaluation->load($this->relations()));
    }

    public function update(
        UpdateProjectEvaluationRequest $request,
        ProjectEvaluation $evaluation,
    ): ProjectEvaluationResource {
        return new ProjectEvaluationResource(
            $this->evaluations->update($request->user(), $evaluation, $request->validated()),
        );
    }

    public function finalize(
        FinalizeProjectEvaluationRequest $request,
        ProjectEvaluation $evaluation,
    ): ProjectEvaluationResource {
        return new ProjectEvaluationResource(
            $this->evaluations->finalize($request->user(), $evaluation, $request->validated()),
        );
    }

    /** @return array<string, \Closure|string> */
    private function relations(): array
    {
        return [
            'project.department:id,name',
            'project.fiscalYear:id,year,is_locked',
            'project.evaluationStatus:id,code,name,color,is_terminal',
            'evaluator:id,name',
            'finalizer:id,name',
            'framework' => fn ($query) => $query->withExists('evaluations'),
            'framework.fiscalYear:id,year,is_locked',
            'framework.criteria',
            'scores.criterion',
            'result.status:id,code,name,color,is_terminal',
            'result.finalizer:id,name',
        ];
    }
}
