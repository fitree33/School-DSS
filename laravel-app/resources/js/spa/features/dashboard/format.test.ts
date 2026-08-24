import { describe, expect, it } from 'vitest';

import { budgetVisualRatio, comparativeBarScale, formatPercentage } from '@/features/dashboard/format';

describe('dashboard budget formatting', () => {
    it('keeps API percentages above 100 visible', () => {
        expect(formatPercentage(137.45)).toBe('137.5%');
        expect(formatPercentage(-25)).toBe('-25%');
    });

    it('distinguishes a missing denominator from zero percent', () => {
        expect(formatPercentage(null)).toBe('ไม่มีฐานงบประมาณ');
        expect(formatPercentage(0)).toBe('0%');
    });

    it('scales department bars against the largest value without hiding overcommitment', () => {
        expect(comparativeBarScale('1000.00', '1400.00', '1200.00')).toEqual({
            allocated: (1000 / 1400) * 100,
            planned: 100,
            actual: (1200 / 1400) * 100,
        });
    });

    it('uses spend as the visual scale when the school budget is overspent', () => {
        expect(budgetVisualRatio('100.00', '125.00')).toBe(100);
    });
});
