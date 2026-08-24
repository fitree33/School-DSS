import { apiClient } from '@/api/client';
import type {
    ApiEnvelope,
    BudgetManagementData,
    DepartmentBudgetPayload,
    DepartmentBudgetRecord,
    SchoolBudgetPayload,
    SchoolBudgetRecord,
} from '@/api/contracts';

export const budgetManagementKeys = {
    all: ['budget-management'] as const,
    detail: (fiscalYearId?: string) => ['budget-management', fiscalYearId ?? 'current'] as const,
};

export const fetchBudgetManagement = async (fiscalYearId?: string): Promise<BudgetManagementData> => {
    const response = await apiClient.get<ApiEnvelope<BudgetManagementData>>('/api/v2/budget-management', {
        params: fiscalYearId ? { fiscal_year_id: fiscalYearId } : undefined,
    });

    return response.data.data;
};

export const updateSchoolBudget = async (
    fiscalYearId: number,
    payload: SchoolBudgetPayload,
): Promise<SchoolBudgetRecord> => {
    const response = await apiClient.put<ApiEnvelope<SchoolBudgetRecord>>(
        `/api/v2/fiscal-years/${fiscalYearId}/school-budget`,
        payload,
    );

    return response.data.data;
};

export const updateDepartmentBudget = async (
    fiscalYearId: number,
    departmentId: number,
    payload: DepartmentBudgetPayload,
): Promise<DepartmentBudgetRecord> => {
    const response = await apiClient.put<ApiEnvelope<DepartmentBudgetRecord>>(
        `/api/v2/fiscal-years/${fiscalYearId}/department-budgets/${departmentId}`,
        payload,
    );

    return response.data.data;
};
