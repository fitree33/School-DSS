<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ProjectIndexRequest;
use App\Http\Requests\Api\V2\StoreProjectRequest;
use App\Http\Requests\Api\V2\UpdateProjectRequest;
use App\Http\Resources\Api\V2\ProjectResource;
use App\Models\Project;
use App\Services\Projects\ProjectService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(ProjectIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Project::query()
            ->visibleTo($request->user())
            ->with($this->relations($request->user()->id));

        $keyword = $filters['q'] ?? null;

        if ($keyword !== null) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword);
            $like = "%{$escaped}%";

            $query->where(function (Builder $search) use ($like): void {
                $search->whereRaw("projects.name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("projects.project_code LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("projects.objective LIKE ? ESCAPE '!'", [$like]);
            });
        }

        $query
            ->when(
                $filters['fiscal_year_id'] ?? null,
                fn (Builder $builder, $id) => $builder->where('fiscal_year_id', $id),
            )
            ->when(
                $filters['department_id'] ?? null,
                fn (Builder $builder, $id) => $builder->where('department_id', $id),
            )
            ->when(
                $filters['execution_status'] ?? null,
                fn (Builder $builder, $code) => $builder->whereHas(
                    'executionStatus',
                    fn (Builder $status) => $status->where('code', $code),
                ),
            )
            ->when(
                $filters['evaluation_status'] ?? null,
                fn (Builder $builder, $code) => $builder->whereHas(
                    'evaluationStatus',
                    fn (Builder $status) => $status->where('code', $code),
                ),
            );

        return ProjectResource::collection(
            $query->orderByDesc('projects.created_at')
                ->orderByDesc('projects.id')
                ->paginate($filters['per_page'] ?? 15)
                ->withQueryString()
        );
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->projects->create($request->user(), $request->validated());
        $project->load($this->relations($request->user()->id));

        return (new ProjectResource($project))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', route('api.v2.projects.show', $project));
    }

    public function show(Project $project): ProjectResource
    {
        $this->authorize('view', $project);

        return new ProjectResource(
            $project->load($this->relations(request()->user()->id))
        );
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $project = $this->projects->update($request->user(), $project, $request->validated());

        return new ProjectResource(
            $project->load($this->relations($request->user()->id))
        );
    }

    public function destroy(Request $request, Project $project): Response
    {
        $this->authorize('delete', $project);
        $this->projects->delete($request->user(), $project);

        return response()->noContent();
    }

    /**
     * @return array<string, \Closure|string>
     */
    private function relations(int $userId): array
    {
        return [
            'owner:id,name',
            'department:id,name',
            'category:id,name',
            'academicYear:id,year',
            'fiscalYear:id,year,is_active,is_locked',
            'schoolPlan:id,fiscal_year_id,code,name',
            'status:id,code,name,color,is_terminal',
            'executionStatus:id,code,name,color,is_terminal',
            'evaluationStatus:id,code,name,color,is_terminal',
            'latestEvaluationResult.status:id,code,name,color,is_terminal',
            'latestEvaluationResult.finalizer:id,name',
            'latestEvaluationResult.evaluation.evaluator:id,name',
            'latestEvaluationResult.evaluation.finalizer:id,name',
            'latestEvaluationResult.evaluation.framework' => fn ($query) => $query->withExists('evaluations'),
            'latestEvaluationResult.evaluation.framework.fiscalYear:id,year,is_locked',
            'latestEvaluationResult.evaluation.framework.criteria',
            'latestEvaluationResult.evaluation.scores.criterion',
            'accessEntries' => fn ($query) => $query->where('user_id', $userId),
        ];
    }
}
