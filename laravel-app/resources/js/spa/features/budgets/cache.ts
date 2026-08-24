import type {
    BudgetManagementData,
    DepartmentBudgetRecord,
    SchoolBudgetRecord,
} from '@/api/contracts';

export const withUpdatedSchoolBudget = (
    current: BudgetManagementData,
    schoolBudget: SchoolBudgetRecord,
): BudgetManagementData => ({
    ...current,
    school_budget: schoolBudget,
});

export const withUpdatedDepartmentBudget = (
    current: BudgetManagementData,
    departmentBudget: DepartmentBudgetRecord,
): BudgetManagementData => ({
    ...current,
    department_budgets: current.department_budgets.map((record) =>
        record.department.id === departmentBudget.department.id ? departmentBudget : record),
});
