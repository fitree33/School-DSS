export const formatPercentage = (value: number | null): string => {
    if (value === null) return 'ไม่มีฐานงบประมาณ';

    return `${new Intl.NumberFormat('th-TH', {
        maximumFractionDigits: 1,
        minimumFractionDigits: 0,
    }).format(value)}%`;
};

export const amountValue = (value: number | string): number => {
    const amount = Number(value);
    return Number.isFinite(amount) ? amount : 0;
};

export interface ComparativeBarScale {
    allocated: number;
    planned: number;
    actual: number;
}

/**
 * Uses the largest amount as the visual scale. Values are never capped against
 * the allocation, so planned/actual overspend remains visible past its marker.
 */
export const comparativeBarScale = (
    allocatedValue: number | string,
    plannedValue: number | string,
    actualValue: number | string,
): ComparativeBarScale => {
    const allocated = Math.max(0, amountValue(allocatedValue));
    const planned = Math.max(0, amountValue(plannedValue));
    const actual = Math.max(0, amountValue(actualValue));
    const scale = Math.max(allocated, planned, actual);

    if (scale === 0) return { allocated: 0, planned: 0, actual: 0 };

    return {
        allocated: (allocated / scale) * 100,
        planned: (planned / scale) * 100,
        actual: (actual / scale) * 100,
    };
};

export const budgetVisualRatio = (budgetValue: number | string, spentValue: number | string): number => {
    const budget = Math.max(0, amountValue(budgetValue));
    const spent = Math.max(0, amountValue(spentValue));
    const scale = Math.max(budget, spent);
    return scale === 0 ? 0 : (spent / scale) * 100;
};
