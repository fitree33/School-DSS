<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\BudgetManagementIndexRequest;
use App\Http\Requests\Api\V2\UpdateDepartmentBudgetRequest;
use App\Http\Requests\Api\V2\UpdateSchoolBudgetRequest;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\FiscalYear;
use App\Models\SchoolBudget;
use App\Services\Budgets\BudgetManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BudgetManagementController extends Controller
{
    public function __construct(private readonly BudgetManagementService $budgets) {}

    public function index(BudgetManagementIndexRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $fiscalYear = $this->selectedFiscalYear($request->validated('fiscal_year_id'));
            $schoolBudget = $fiscalYear?->schoolBudget()->first();
            $departmentBudgets = $schoolBudget
                ? $schoolBudget->departmentBudgets()->get()->keyBy('department_id')
                : collect();

            return response()->json([
                'data' => [
                    'fiscal_year' => $fiscalYear ? $this->fiscalYearData($fiscalYear) : null,
                    'fiscal_years' => FiscalYear::query()
                        ->orderByDesc('year')
                        ->get()
                        ->map(fn (FiscalYear $year): array => $this->fiscalYearData($year)),
                    'school_budget' => $schoolBudget ? $this->schoolBudgetData($schoolBudget) : null,
                    'department_budgets' => Department::query()
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(function (Department $department) use ($departmentBudgets): array {
                            /** @var DepartmentBudget|null $budget */
                            $budget = $departmentBudgets->get($department->id);

                            return $this->departmentBudgetData($department, $budget);
                        }),
                    'can' => ['manage_budgets' => true],
                ],
            ]);
        }, 3);
    }

    public function updateSchoolBudget(
        UpdateSchoolBudgetRequest $request,
        FiscalYear $fiscalYear,
    ): JsonResponse {
        $budget = $this->budgets->updateSchoolBudget(
            $request->user(),
            $fiscalYear,
            $request->validated(),
        );

        return response()->json(['data' => $this->schoolBudgetData($budget)]);
    }

    public function updateDepartmentBudget(
        UpdateDepartmentBudgetRequest $request,
        FiscalYear $fiscalYear,
        Department $department,
    ): JsonResponse {
        $budget = $this->budgets->updateDepartmentBudget(
            $request->user(),
            $fiscalYear,
            $department,
            $request->validated(),
        );

        return response()->json([
            'data' => $this->departmentBudgetData($department, $budget),
        ]);
    }

    private function selectedFiscalYear(mixed $requestedId): ?FiscalYear
    {
        if ($requestedId !== null) {
            return FiscalYear::query()->findOrFail((int) $requestedId);
        }

        return FiscalYear::query()
            ->where('is_active', true)
            ->orderByDesc('year')
            ->first()
            ?? FiscalYear::query()->orderByDesc('year')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function fiscalYearData(FiscalYear $fiscalYear): array
    {
        return [
            'id' => $fiscalYear->id,
            'year' => $fiscalYear->year,
            'is_active' => $fiscalYear->is_active,
            'is_locked' => $fiscalYear->is_locked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schoolBudgetData(SchoolBudget $budget): array
    {
        return [
            'id' => $budget->id,
            'fiscal_year_id' => $budget->fiscal_year_id,
            'total_amount' => $budget->total_amount,
            'notes' => $budget->notes,
            'updated_at' => $budget->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function departmentBudgetData(Department $department, ?DepartmentBudget $budget): array
    {
        return [
            'id' => $budget?->id,
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
            ],
            'allocated_amount' => $budget?->allocated_amount ?? '0.00',
            'is_allocated' => $budget?->is_allocated ?? false,
            'allocated_at' => $budget?->allocated_at?->toISOString(),
            'allocated_by' => $budget?->allocated_by,
            'notes' => $budget?->notes,
            'updated_at' => $budget?->updated_at?->toISOString(),
        ];
    }
}
