<?php

namespace App\Services\Budgets;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Models\FiscalYear;
use App\Models\SchoolBudget;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetManagementService
{
    /**
     * @param  array{total_amount: int|float|string, notes?: string|null}  $attributes
     */
    public function updateSchoolBudget(User $actor, FiscalYear $fiscalYear, array $attributes): SchoolBudget
    {
        return DB::transaction(function () use ($fiscalYear, $attributes): SchoolBudget {
            $lockedYear = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYear->id);
            $this->ensureUnlocked($lockedYear);

            $budget = SchoolBudget::query()
                ->where('fiscal_year_id', $lockedYear->id)
                ->lockForUpdate()
                ->first();

            $departmentBudgets = $budget
                ? DepartmentBudget::query()
                    ->where('school_budget_id', $budget->id)
                    ->lockForUpdate()
                    ->get()
                : collect();
            $allocatedCents = $departmentBudgets
                ->where('is_allocated', true)
                ->sum(fn (DepartmentBudget $departmentBudget): int => Money::toCents($departmentBudget->allocated_amount));
            $totalCents = Money::toCents($attributes['total_amount']);

            if ($allocatedCents > $totalCents) {
                throw ValidationException::withMessages([
                    'total_amount' => ['The school budget cannot be lower than the confirmed department allocations.'],
                ]);
            }

            $oldValues = $budget?->only(['fiscal_year_id', 'total_amount', 'notes']) ?? [];
            $budget ??= new SchoolBudget(['fiscal_year_id' => $lockedYear->id]);
            $budget->fill([
                'total_amount' => Money::fromCents($totalCents),
                'notes' => array_key_exists('notes', $attributes) ? $attributes['notes'] : $budget->notes,
            ])->save();

            AuditLog::record(
                'school_budget.updated',
                $budget,
                $oldValues,
                $budget->only(['fiscal_year_id', 'total_amount', 'notes']),
            );

            return $budget->refresh();
        }, 3);
    }

    /**
     * @param  array{allocated_amount: int|float|string, is_allocated: bool, notes?: string|null}  $attributes
     */
    public function updateDepartmentBudget(
        User $actor,
        FiscalYear $fiscalYear,
        Department $department,
        array $attributes,
    ): DepartmentBudget {
        return DB::transaction(function () use ($actor, $fiscalYear, $department, $attributes): DepartmentBudget {
            $lockedYear = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYear->id);
            $this->ensureUnlocked($lockedYear);

            $schoolBudget = SchoolBudget::query()
                ->where('fiscal_year_id', $lockedYear->id)
                ->lockForUpdate()
                ->first();

            if (! $schoolBudget) {
                throw ValidationException::withMessages([
                    'school_budget' => ['Set the school budget before allocating a department budget.'],
                ]);
            }

            $departmentBudgets = DepartmentBudget::query()
                ->where('school_budget_id', $schoolBudget->id)
                ->lockForUpdate()
                ->get();
            $budget = $departmentBudgets->firstWhere('department_id', $department->id);
            $amountCents = Money::toCents($attributes['allocated_amount']);
            $prospectiveAllocatedCents = $departmentBudgets
                ->reject(fn (DepartmentBudget $item): bool => (int) $item->department_id === (int) $department->id)
                ->where('is_allocated', true)
                ->sum(fn (DepartmentBudget $item): int => Money::toCents($item->allocated_amount));

            if ($attributes['is_allocated']) {
                $prospectiveAllocatedCents += $amountCents;
            }

            if ($prospectiveAllocatedCents > Money::toCents($schoolBudget->total_amount)) {
                throw ValidationException::withMessages([
                    'allocated_amount' => ['The confirmed department allocations cannot exceed the school budget.'],
                ]);
            }

            $oldValues = $budget?->only([
                'school_budget_id',
                'department_id',
                'allocated_amount',
                'is_allocated',
                'allocated_at',
                'allocated_by',
                'notes',
            ]) ?? [];
            $wasAllocated = $budget?->is_allocated === true;
            $budget ??= new DepartmentBudget([
                'school_budget_id' => $schoolBudget->id,
                'department_id' => $department->id,
            ]);
            $budget->fill([
                'allocated_amount' => Money::fromCents($amountCents),
                'is_allocated' => $attributes['is_allocated'],
                'allocated_at' => $attributes['is_allocated']
                    ? ($wasAllocated ? $budget->allocated_at : now())
                    : null,
                'allocated_by' => $attributes['is_allocated'] ? $actor->id : null,
                'notes' => array_key_exists('notes', $attributes) ? $attributes['notes'] : $budget->notes,
            ])->save();

            AuditLog::record(
                'department_budget.updated',
                $budget,
                $oldValues,
                $budget->only([
                    'school_budget_id',
                    'department_id',
                    'allocated_amount',
                    'is_allocated',
                    'allocated_at',
                    'allocated_by',
                    'notes',
                ]),
            );

            return $budget->refresh();
        }, 3);
    }

    private function ensureUnlocked(FiscalYear $fiscalYear): void
    {
        if ($fiscalYear->is_locked) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => ['The selected fiscal year is locked and read-only.'],
            ]);
        }
    }
}
