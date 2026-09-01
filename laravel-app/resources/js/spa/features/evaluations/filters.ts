import type { EvaluationFilters } from '@/api/contracts';

export const evaluationFiltersFromSearch = (search: URLSearchParams): EvaluationFilters => ({
    fiscal_year_id: search.get('fiscal_year_id') ?? '',
    department_id: search.get('department_id') ?? '',
    evaluation_status: asStatus(search.get('evaluation_status')),
    page: positiveInteger(search.get('page')),
    per_page: 15,
});

export const compactEvaluationFilters = (filters: EvaluationFilters): Record<string, string | number> =>
    Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== undefined && value !== ''),
    ) as Record<string, string | number>;

const positiveInteger = (value: string | null): number => {
    const parsed = Number(value);
    return Number.isInteger(parsed) && parsed > 0 ? parsed : 1;
};

const asStatus = (value: string | null): EvaluationFilters['evaluation_status'] =>
    value === null ? 'pending' : value === 'pending' || value === 'passed' || value === 'failed' ? value : '';
