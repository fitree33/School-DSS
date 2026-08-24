import { apiClient } from '@/api/client';
import type { ApiEnvelope, DashboardSummary } from '@/api/contracts';

export const dashboardKeys = {
    all: ['dashboard'] as const,
    summary: (fiscalYearId?: string) => ['dashboard', 'summary', fiscalYearId ?? 'current'] as const,
};

export const fetchDashboard = async (fiscalYearId?: string): Promise<DashboardSummary> => {
    const response = await apiClient.get<ApiEnvelope<DashboardSummary>>('/api/v2/dashboard', {
        params: fiscalYearId ? { fiscal_year_id: fiscalYearId } : undefined,
    });

    return response.data.data;
};
