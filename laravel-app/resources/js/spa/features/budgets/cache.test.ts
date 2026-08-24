import { describe, expect, it } from 'vitest';

import type {
    BudgetManagementData,
    DepartmentBudgetRecord,
    SchoolBudgetRecord,
} from '@/api/contracts';
import {
    withUpdatedDepartmentBudget,
    withUpdatedSchoolBudget,
} from '@/features/budgets/cache';

const schoolBudget: SchoolBudgetRecord = {
    id: 1,
    fiscal_year_id: 10,
    total_amount: '1000.00',
    notes: null,
    updated_at: null,
};

const departmentBudget = (id: number, amount: string): DepartmentBudgetRecord => ({
    id,
    department: { id, name: `Department ${id}` },
    allocated_amount: amount,
    is_allocated: true,
    allocated_at: null,
    allocated_by: null,
    notes: null,
    updated_at: null,
});

const managementData = (): BudgetManagementData => ({
    fiscal_year: { id: 10, year: 2570, is_active: true, is_locked: false },
    fiscal_years: [{ id: 10, year: 2570, is_active: true, is_locked: false }],
    school_budget: schoolBudget,
    department_budgets: [departmentBudget(1, '400.00'), departmentBudget(2, '600.00')],
    can: { manage_budgets: true },
});

describe('budget-management cache updates', () => {
    it('replaces only the saved department and preserves every other row reference', () => {
        const current = managementData();
        const untouchedRow = current.department_budgets[1];
        const updatedRow = departmentBudget(1, '450.00');
        const updated = withUpdatedDepartmentBudget(current, updatedRow);

        expect(updated.department_budgets[0]).toBe(updatedRow);
        expect(updated.department_budgets[1]).toBe(untouchedRow);
        expect(updated.school_budget).toBe(current.school_budget);
    });

    it('replaces only the school budget and preserves the department collection reference', () => {
        const current = managementData();
        const updatedSchoolBudget = { ...schoolBudget, total_amount: '1200.00' };
        const updated = withUpdatedSchoolBudget(current, updatedSchoolBudget);

        expect(updated.school_budget).toBe(updatedSchoolBudget);
        expect(updated.department_budgets).toBe(current.department_budgets);
    });
});
