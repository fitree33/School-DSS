export type WeightConfiguration = 'none' | 'complete' | 'mixed';

export const weightConfiguration = (criteria: Array<{ weight: string | number | null; is_active: boolean }>): WeightConfiguration => {
    const active = criteria.filter((criterion) => criterion.is_active);
    const weighted = active.filter(({ weight }) => weight !== null && weight !== '' && Number(weight) > 0);
    if (!weighted.length) return 'none';
    return weighted.length === active.length ? 'complete' : 'mixed';
};
