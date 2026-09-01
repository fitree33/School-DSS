import { describe, expect, it } from 'vitest';

import { compactEvaluationFilters, evaluationFiltersFromSearch } from '@/features/evaluations/filters';

describe('evaluation worklist filters', () => {
    it('keeps supported filters and normalizes an invalid page', () => {
        const filters = evaluationFiltersFromSearch(new URLSearchParams('fiscal_year_id=3&department_id=4&evaluation_status=passed&page=2'));

        expect(filters).toEqual({
            fiscal_year_id: '3',
            department_id: '4',
            evaluation_status: 'passed',
            page: 2,
            per_page: 15,
        });
    });

    it('does not send empty or unsupported status filters', () => {
        const filters = evaluationFiltersFromSearch(new URLSearchParams('evaluation_status=unknown&page=-1'));

        expect(compactEvaluationFilters(filters)).toEqual({ page: 1, per_page: 15 });
    });

    it('defaults the bare worklist to pending while allowing an explicit all view', () => {
        expect(evaluationFiltersFromSearch(new URLSearchParams()).evaluation_status).toBe('pending');
        expect(evaluationFiltersFromSearch(new URLSearchParams('evaluation_status=all')).evaluation_status).toBe('');
    });
});
