<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\EvaluationFrameworkIndexRequest;
use App\Http\Requests\Api\V2\StoreEvaluationFrameworkRequest;
use App\Http\Requests\Api\V2\StoreEvaluationFrameworkVersionRequest;
use App\Http\Requests\Api\V2\UpdateEvaluationFrameworkRequest;
use App\Http\Resources\Api\V2\EvaluationFrameworkResource;
use App\Models\EvaluationFramework;
use App\Services\Evaluations\EvaluationFrameworkService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class EvaluationFrameworkController extends Controller
{
    public function __construct(private readonly EvaluationFrameworkService $frameworks) {}

    public function index(EvaluationFrameworkIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = EvaluationFramework::query()
            ->with(['fiscalYear:id,year,is_locked', 'criteria'])
            ->withExists('evaluations')
            ->when(
                $filters['q'] ?? null,
                function (Builder $builder, string $keyword): void {
                    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword);
                    $like = "%{$escaped}%";
                    $builder->where(function (Builder $search) use ($like): void {
                        $search->whereRaw("code LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("version LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("name LIKE ? ESCAPE '!'", [$like]);
                    });
                },
            )
            ->when(
                $filters['fiscal_year_id'] ?? null,
                fn (Builder $builder, $id) => $builder->where('fiscal_year_id', $id),
            );

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return EvaluationFrameworkResource::collection(
            $query->orderBy('code')
                ->orderByDesc('created_at')
                ->paginate($filters['per_page'] ?? 15)
                ->withQueryString(),
        );
    }

    public function store(StoreEvaluationFrameworkRequest $request): JsonResponse
    {
        $framework = $this->frameworks->create($request->user(), $request->validated());

        return (new EvaluationFrameworkResource($framework))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', route('api.v2.evaluation-frameworks.show', $framework));
    }

    public function show(EvaluationFramework $framework): EvaluationFrameworkResource
    {
        $this->authorize('view', $framework);

        return new EvaluationFrameworkResource($this->load($framework));
    }

    public function update(
        UpdateEvaluationFrameworkRequest $request,
        EvaluationFramework $framework,
    ): EvaluationFrameworkResource {
        return new EvaluationFrameworkResource(
            $this->frameworks->update($request->user(), $framework, $request->validated()),
        );
    }

    public function storeVersion(
        StoreEvaluationFrameworkVersionRequest $request,
        EvaluationFramework $framework,
    ): JsonResponse {
        $version = $this->frameworks->createVersion(
            $request->user(),
            $framework,
            $request->validated(),
        );

        return (new EvaluationFrameworkResource($version))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', route('api.v2.evaluation-frameworks.show', $version));
    }

    public function activate(Request $request, EvaluationFramework $framework): EvaluationFrameworkResource
    {
        $this->authorize('activate', $framework);

        return new EvaluationFrameworkResource(
            $this->frameworks->activate($request->user(), $framework),
        );
    }

    public function deactivate(Request $request, EvaluationFramework $framework): EvaluationFrameworkResource
    {
        $this->authorize('deactivate', $framework);

        return new EvaluationFrameworkResource(
            $this->frameworks->deactivate($request->user(), $framework),
        );
    }

    private function load(EvaluationFramework $framework): EvaluationFramework
    {
        return $framework->load(['fiscalYear:id,year,is_locked', 'criteria'])
            ->loadExists('evaluations');
    }
}
