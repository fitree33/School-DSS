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
    const date = new Date(value.includes('T') ? value : `${value}T00:00:00`);
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

export interface ProjectBudgetView extends BudgetUsage {
    budget: number;
    actualSpent: number;
}

export const projectBudgetView = (project: {
    budget: number | string;
    actual_spent: number | string;
    budget_metrics?: {
        budget: string;
        actual_spent: string;
        remaining: string;
        used_percentage: number | null;
    };
}): ProjectBudgetView => {
    if (project.budget_metrics) {
        return {
            budget: finiteNumber(project.budget_metrics.budget),
            actualSpent: finiteNumber(project.budget_metrics.actual_spent),
            remaining: finiteNumber(project.budget_metrics.remaining),
            usedPercentage: project.budget_metrics.used_percentage,
        };
    }

    const budget = finiteNumber(project.budget);
    const actualSpent = finiteNumber(project.actual_spent);
    return { budget, actualSpent, ...calculateBudgetUsage(budget, actualSpent) };
};

const finiteNumber = (value: number | string): number => {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
};

export const normalExecutionStatusCodes = (currentCode: string | null | undefined): string[] => {
    const current = currentCode ?? '';
    const nextStatus: Record<string, string | undefined> = {
        '': 'not_started',
        not_started: 'in_progress',
        in_progress: 'completed',
        completed: undefined,
    };

    return [current, nextStatus[current]].filter((code): code is string => Boolean(code));
};
