<?php

namespace App\Services\Budgets;

use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BudgetMetricsService
{
    private const PROJECT_EXECUTION_STATUS_CODES = [
        'not_started',
        'in_progress',
        'completed',
    ];

    private const EVALUATION_STATUS_CODES = [
        'pending',
        'passed',
        'failed',
    ];

    /**
     * Build school-wide, aggregate-only dashboard metrics.
     *
     * Amounts are returned as two-decimal strings. With a zero denominator,
     * percentages are zero when both values are zero and null when non-zero
     * spending makes the ratio undefined.
     *
     * @return array<string, mixed>
     */
    public function dashboard(User $user, ?int $fiscalYearId = null): array
    {
        return DB::transaction(
            fn (): array => $this->buildDashboard($user, $fiscalYearId),
            3,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboard(User $user, ?int $fiscalYearId): array
    {
        $fiscalYears = FiscalYear::query()
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->get();
        $fiscalYear = $this->selectFiscalYear($fiscalYears, $fiscalYearId);

        $projectExecutionStatusCounts = $this->emptyCounts(self::PROJECT_EXECUTION_STATUS_CODES);
        $evaluationStatusCounts = $this->emptyCounts(self::EVALUATION_STATUS_CODES);
        $departmentProjectMetrics = collect();
        $totalProjects = 0;
        $totalActualSpent = 0;
        $schoolBudget = null;

        if ($fiscalYear) {
            $projects = Project::query()->where('fiscal_year_id', $fiscalYear->id);
            $departmentProjectMetrics = $this->departmentProjectMetrics(clone $projects);
            $totalProjects = (clone $projects)->count();
            $totalActualSpent = Money::toCents((clone $projects)->sum('actual_spent'));
            $projectExecutionStatusCounts = $this->statusCounts(
                clone $projects,
                'project_execution_statuses',
                'project_execution_status_id',
                self::PROJECT_EXECUTION_STATUS_CODES,
            );
            $evaluationStatusCounts = $this->statusCounts(
                clone $projects,
                'evaluation_statuses',
                'evaluation_status_id',
                self::EVALUATION_STATUS_CODES,
            );
            $schoolBudget = $fiscalYear->schoolBudget()
                ->with('departmentBudgets')
                ->first();
        }

        $departmentBudgets = $schoolBudget?->departmentBudgets->keyBy('department_id') ?? collect();
        $totalBudget = Money::toCents($schoolBudget?->total_amount);
        $allocatedToDepartments = $departmentBudgets
            ->filter(fn ($budget) => $budget->is_allocated)
            ->sum(fn ($budget) => Money::toCents($budget->allocated_amount));

        return [
            'fiscal_year' => $this->fiscalYearData($fiscalYear),
            'fiscal_years' => $fiscalYears
                ->map(fn (FiscalYear $year) => $this->fiscalYearData($year))
                ->values()
                ->all(),
            'school_budget' => $this->schoolBudgetMetrics(
                $totalBudget,
                $allocatedToDepartments,
                $totalActualSpent,
            ),
            'departments' => Department::query()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(function (Department $department) use ($departmentBudgets, $departmentProjectMetrics) {
                    $allocation = $departmentBudgets->get($department->id);
                    $projectMetrics = $departmentProjectMetrics->get($department->id);
                    $allocatedBudget = $allocation?->is_allocated
                        ? Money::toCents($allocation->allocated_amount)
                        : 0;
                    $plannedProjectBudget = Money::toCents($projectMetrics?->planned_project_budget);
                    $actualSpent = Money::toCents($projectMetrics?->actual_spent);
                    $remaining = $allocatedBudget - $actualSpent;

                    return [
                        'department' => [
                            'id' => $department->id,
                            'name' => $department->name,
                        ],
                        'is_allocated' => (bool) ($allocation?->is_allocated ?? false),
                        'allocated_budget' => Money::fromCents($allocatedBudget),
                        'planned_project_budget' => Money::fromCents($plannedProjectBudget),
                        'actual_spent' => Money::fromCents($actualSpent),
                        'remaining' => Money::fromCents($remaining),
                        'used_percentage' => Money::percentage($actualSpent, $allocatedBudget),
                        'remaining_percentage' => Money::percentage($remaining, $allocatedBudget),
                        'overcommitted' => $plannedProjectBudget > $allocatedBudget,
                        'overspent' => $actualSpent > $allocatedBudget,
                    ];
                })
                ->values()
                ->all(),
            'project_execution_status_counts' => $projectExecutionStatusCounts,
            'evaluation_status_counts' => $evaluationStatusCounts,
            'total_projects' => $totalProjects,
            'can' => [
                'manage_budgets' => $user->hasPermission('budgets.manage'),
            ],
        ];
    }

    /**
     * @return array{budget: string, actual_spent: string, remaining: string, used_percentage: float|null}
     */
    public function project(int|float|string|null $budget, int|float|string|null $actualSpent): array
    {
        $budgetCents = Money::toCents($budget);
        $actualSpentCents = Money::toCents($actualSpent);

        return [
            'budget' => Money::fromCents($budgetCents),
            'actual_spent' => Money::fromCents($actualSpentCents),
            'remaining' => Money::fromCents($budgetCents - $actualSpentCents),
            'used_percentage' => Money::percentage($actualSpentCents, $budgetCents),
        ];
    }

    /**
     * @param  Collection<int, FiscalYear>  $fiscalYears
     */
    private function selectFiscalYear(Collection $fiscalYears, ?int $fiscalYearId): ?FiscalYear
    {
        if ($fiscalYearId !== null) {
            return $fiscalYears->firstWhere('id', $fiscalYearId);
        }

        return $fiscalYears->first(fn (FiscalYear $year) => $year->is_active)
            ?? $fiscalYears->first();
    }

    /**
     * @return Collection<int, object>
     */
    private function departmentProjectMetrics(Builder $projects): Collection
    {
        return $projects
            ->selectRaw('department_id, COALESCE(SUM(budget), 0) as planned_project_budget')
            ->selectRaw('COALESCE(SUM(actual_spent), 0) as actual_spent')
            ->groupBy('department_id')
            ->get()
            ->keyBy('department_id');
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, int>
     */
    private function statusCounts(
        Builder $projects,
        string $statusTable,
        string $foreignKey,
        array $codes,
    ): array {
        $counts = $this->emptyCounts($codes);

        $projects
            ->join($statusTable, "projects.{$foreignKey}", '=', "{$statusTable}.id")
            ->whereIn("{$statusTable}.code", $codes)
            ->selectRaw("{$statusTable}.code as status_code, COUNT(*) as aggregate")
            ->groupBy("{$statusTable}.code")
            ->get()
            ->each(function (object $row) use (&$counts): void {
                $counts[$row->status_code] = (int) $row->aggregate;
            });

        return $counts;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, int>
     */
    private function emptyCounts(array $codes): array
    {
        return array_fill_keys($codes, 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fiscalYearData(?FiscalYear $fiscalYear): ?array
    {
        if (! $fiscalYear) {
            return null;
        }

        return [
            'id' => $fiscalYear->id,
            'year' => $fiscalYear->year,
            'start_date' => $fiscalYear->start_date?->toDateString(),
            'end_date' => $fiscalYear->end_date?->toDateString(),
            'is_active' => $fiscalYear->is_active,
            'is_locked' => $fiscalYear->is_locked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schoolBudgetMetrics(
        int $totalBudget,
        int $allocatedToDepartments,
        int $totalActualSpent,
    ): array {
        $unallocated = $totalBudget - $allocatedToDepartments;
        $remaining = $totalBudget - $totalActualSpent;

        return [
            'total_budget' => Money::fromCents($totalBudget),
            'allocated_to_departments' => Money::fromCents($allocatedToDepartments),
            'unallocated' => Money::fromCents($unallocated),
            'total_actual_spent' => Money::fromCents($totalActualSpent),
            'remaining' => Money::fromCents($remaining),
            'used_percentage' => Money::percentage($totalActualSpent, $totalBudget),
            'remaining_percentage' => Money::percentage($remaining, $totalBudget),
            'overallocated' => $allocatedToDepartments > $totalBudget,
            'overspent' => $totalActualSpent > $totalBudget,
        ];
    }
}
