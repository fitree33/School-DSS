<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\EvaluationFrameworkResource;
use App\Models\Department;
use App\Models\EvaluationFramework;
use App\Models\EvaluationStatus;
use App\Models\FiscalYear;
use App\Models\ProjectEvaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluationOptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProjectEvaluation::class);

        $frameworks = EvaluationFramework::query()
            ->where('is_active', true)
            ->with(['fiscalYear:id,year,is_locked', 'criteria'])
            ->withExists('evaluations')
            ->orderBy('code')
            ->orderBy('version')
            ->get();

        return response()->json([
            'data' => [
                'fiscal_years' => FiscalYear::query()
                    ->orderByDesc('year')
                    ->get()
                    ->map(fn (FiscalYear $year): array => [
                        'id' => $year->id,
                        'year' => $year->year,
                        'is_active' => (bool) $year->is_active,
                        'is_locked' => (bool) $year->is_locked,
                    ]),
                'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
                'evaluation_statuses' => EvaluationStatus::query()
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (EvaluationStatus $status): array => [
                        'id' => $status->id,
                        'code' => $status->code,
                        'name' => $status->name,
                        'color' => $status->color,
                        'is_terminal' => (bool) $status->is_terminal,
                    ]),
                'frameworks' => EvaluationFrameworkResource::collection($frameworks),
                'can' => [
                    'manage_frameworks' => $request->user()?->can(
                        'create',
                        EvaluationFramework::class,
                    ) ?? false,
                ],
            ],
        ]);
    }
}
