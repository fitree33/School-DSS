<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\EvaluationCriterion;
use App\Models\Project;
use App\Services\DssPortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DecisionSupportController extends Controller
{
    public function __invoke(Request $request, DssPortfolioService $service)
    {
        Gate::authorize('viewDss');

        $criteria = EvaluationCriterion::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $years = AcademicYear::query()->orderByDesc('year')->get();
        $selectedYearId = $request->integer('year_id')
            ?: (int) ($years->firstWhere('is_active', true)?->id ?? $years->first()?->id);

        $validated = $request->validate([
            'year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'budget_limit' => ['nullable', 'numeric', 'min:0'],
            'weights' => ['nullable', 'array'],
            'weights.*' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        $requestedWeights = $validated['weights'] ?? [];
        $weights = $service->normalizeWeights($criteria, $requestedWeights);

        $projects = Project::query()
            ->visibleTo($request->user())
            ->when($selectedYearId, fn ($query) => $query->where('academic_year_id', $selectedYearId))
            ->with([
                'status',
                'department',
                'owner',
                'evaluations.scores',
            ])
            ->get();

        $rankedProjects = $service->rank($projects, $criteria, $weights);
        $budgetLimit = (float) ($validated['budget_limit'] ?? 0);
        $portfolio = $service->optimizeBudget($rankedProjects, $budgetLimit);

        if ($request->hasAny(['weights', 'budget_limit'])) {
            AuditLog::record('dss.scenario_analyzed', $request->user(), [], [
                'academic_year_id' => $selectedYearId,
                'weights' => $weights,
                'budget_limit' => $budgetLimit,
                'selected_project_ids' => $portfolio['ids'],
            ]);
        }

        return view('dss.index', [
            'criteria' => $criteria,
            'weights' => $weights,
            'years' => $years,
            'selectedYearId' => $selectedYearId,
            'rankedProjects' => $rankedProjects,
            'budgetLimit' => $budgetLimit,
            'portfolio' => $portfolio,
            'method' => DssPortfolioService::METHOD,
        ]);
    }
}
