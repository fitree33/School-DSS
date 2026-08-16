export const formatCurrency = (value: number | string | null | undefined): string => {
    const amount = Number(value ?? 0);
    return new Intl.NumberFormat('th-TH', {
        style: 'currency',
        currency: 'THB',
        maximumFractionDigits: 2,
    }).format(Number.isFinite(amount) ? amount : 0);
};

export const formatDate = (value: string | null | undefined): string => {
    if (!value) return '—';
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium' }).format(date);
};

export const yearLabel = (year: number | string | undefined): string => year === undefined ? '—' : String(year);

export interface BudgetUsage {
    remaining: number;
    usedPercentage: number | null;
}

export const calculateBudgetUsage = (budget: number, actualSpent: number): BudgetUsage => ({
    remaining: budget - actualSpent,
    usedPercentage: budget > 0 ? (actualSpent / budget) * 100 : actualSpent === 0 ? 0 : null,
});
