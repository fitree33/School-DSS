import { describe, expect, it } from 'vitest';

import { calculateBudgetUsage } from '@/features/projects/format';

describe('project budget usage', () => {
    it('keeps overspending visible without clamping the percentage', () => {
        expect(calculateBudgetUsage(100, 125)).toEqual({
            remaining: -25,
            usedPercentage: 125,
        });
    });

    it('does not report zero percent when spending exists without a budget', () => {
        expect(calculateBudgetUsage(0, 25)).toEqual({
            remaining: -25,
            usedPercentage: null,
        });
    });

    it('reports zero percent only when both budget and spending are zero', () => {
        expect(calculateBudgetUsage(0, 0)).toEqual({
            remaining: 0,
            usedPercentage: 0,
        });
    });
});
