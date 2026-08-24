import { describe, expect, it } from 'vitest';

import { calculateBudgetUsage, normalExecutionStatusCodes, projectBudgetView } from '@/features/projects/format';

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

describe('project budget API metrics', () => {
    it('prefers the server metric without recomputing or clamping it', () => {
        expect(projectBudgetView({
            budget: '100.00',
            actual_spent: '120.00',
            budget_metrics: {
                budget: '100.00',
                actual_spent: '120.00',
                remaining: '-20.00',
                used_percentage: 120,
            },
        })).toEqual({ budget: 100, actualSpent: 120, remaining: -20, usedPercentage: 120 });
    });
});

describe('normal execution status transitions', () => {
    it('offers only the current state and its next forward state', () => {
        expect(normalExecutionStatusCodes('not_started')).toEqual(['not_started', 'in_progress']);
        expect(normalExecutionStatusCodes('in_progress')).toEqual(['in_progress', 'completed']);
        expect(normalExecutionStatusCodes('completed')).toEqual(['completed']);
    });
});
