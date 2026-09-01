import { describe, expect, it } from 'vitest';

import { calculateEvaluationTotals, formatEvaluationPercentage } from '@/features/evaluations/score';

const criterion = (id: number, maximum: number, weight: number | null) => ({
    id,
    max_score: maximum,
    weight,
    is_active: true,
});

describe('calculateEvaluationTotals', () => {
    it('calculates informational raw, maximum, percentage and complete weighted totals', () => {
        const totals = calculateEvaluationTotals([
            { criterion: criterion(1, 10, 25), score: '8' },
            { criterion: criterion(2, 20, 75), score: '10' },
        ]);

        expect(totals).toMatchObject({ totalScore: 18, maximumScore: 30, percentage: 60, hasCompleteWeights: true });
        expect(totals?.weightedPercentage).toBeCloseTo(57.5);
    });

    it('does not calculate a weighted percentage when any active criterion has no positive weight', () => {
        const totals = calculateEvaluationTotals([
            { criterion: criterion(1, 10, 25), score: 8 },
            { criterion: criterion(2, 10, null), score: 9 },
        ]);

        expect(totals?.totalScore).toBe(17);
        expect(totals?.weightedPercentage).toBeNull();
        expect(totals?.hasCompleteWeights).toBe(false);
    });

    it('does not publish totals for incomplete or out-of-range scores', () => {
        expect(calculateEvaluationTotals([
            { criterion: criterion(1, 5, null), score: '' },
        ])).toBeNull();
        expect(calculateEvaluationTotals([
            { criterion: criterion(1, 5, null), score: 6 },
        ])).toBeNull();
    });

    it('formats informational percentages without inferring a decision', () => {
        expect(formatEvaluationPercentage(82.125)).toBe('82.13%');
        expect(formatEvaluationPercentage(null)).toBe('—');
    });
});
