import { describe, expect, it } from 'vitest';

import { weightConfiguration } from '@/features/evaluations/framework';

describe('framework weight configuration', () => {
    it('accepts a framework with no weights', () => {
        expect(weightConfiguration([{ weight: null, is_active: true }, { weight: '', is_active: true }])).toBe('none');
    });

    it('accepts weights only when every active criterion has a positive weight', () => {
        expect(weightConfiguration([{ weight: 40, is_active: true }, { weight: '60', is_active: true }])).toBe('complete');
        expect(weightConfiguration([{ weight: 40, is_active: true }, { weight: null, is_active: true }])).toBe('mixed');
    });

    it('ignores inactive criteria for informational weighted scoring', () => {
        expect(weightConfiguration([{ weight: 100, is_active: true }, { weight: null, is_active: false }])).toBe('complete');
    });
});
