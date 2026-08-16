<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectCompletionReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $visibleProjects = Project::query()->visibleTo($request->user());
        $visibleProjectIds = (clone $visibleProjects)->pluck('projects.id');

        $countByStatus = function (array $codes) use ($visibleProjects): int {
            return (clone $visibleProjects)
                ->whereHas('status', fn ($query) => $query->whereIn('code', $codes))
                ->count();
        };

        $totalBudget = (float) (clone $visibleProjects)->sum('budget');
        $approvedBudget = (float) (clone $visibleProjects)
            ->whereHas('status', fn ($query) => $query->whereIn('code', ['approved', 'in_progress', 'completed']))
            ->sum('budget');
        $actualSpent = (float) (clone $visibleProjects)->sum('actual_spent');

        $stats = [
            'total' => (clone $visibleProjects)->count(),
            'waiting' => $countByStatus(['pending_deputy', 'pending_director']),
            'approved' => $countByStatus(['approved', 'in_progress']),
            'completed' => $countByStatus(['completed']),
            'returned' => $countByStatus(['returned', 'rejected']),
            'total_budget' => $totalBudget,
            'approved_budget' => $approvedBudget,
            'actual_spent' => $actualSpent,
            'budget_utilization' => $approvedBudget > 0 ? round(($actualSpent / $approvedBudget) * 100, 1) : 0,
            'average_success' => $visibleProjectIds->isNotEmpty()
                ? round((float) ProjectCompletionReport::whereIn('project_id', $visibleProjectIds)->avg('success_percent'), 1)
                : 0,
        ];

        $statusDistribution = (clone $visibleProjects)
            ->join('project_statuses', 'projects.project_status_id', '=', 'project_statuses.id')
            ->select('project_statuses.code', 'project_statuses.name', DB::raw('COUNT(*) as total'))
            ->groupBy('project_statuses.code', 'project_statuses.name', 'project_statuses.sort_order')
            ->orderBy('project_statuses.sort_order')
            ->get();

        $budgetBySource = (clone $visibleProjects)
            ->select('budget_source', DB::raw('SUM(budget) as total'))
            ->groupBy('budget_source')
            ->orderByDesc('total')
            ->get();

        $recentProjects = (clone $visibleProjects)
            ->with(['status', 'owner', 'department'])
            ->latest('projects.updated_at')
            ->limit(6)
            ->get();

        $waitingProjects = (clone $visibleProjects)
            ->with(['status', 'owner', 'department'])
            ->whereHas('status', fn ($query) => $query->whereIn('code', ['pending_deputy', 'pending_director']))
            ->oldest('projects.updated_at')
            ->limit(5)
            ->get();

        $topProjects = (clone $visibleProjects)
            ->with(['latestDssResult', 'status', 'department'])
            ->get()
            ->filter(fn (Project $project) => $project->latestDssResult)
            ->sortByDesc(fn (Project $project) => (float) $project->latestDssResult->total_score)
            ->take(5)
            ->values();

        return view('dashboard', compact(
            'stats',
            'statusDistribution',
            'budgetBySource',
            'recentProjects',
            'waitingProjects',
            'topProjects'
        ));
    }
}
