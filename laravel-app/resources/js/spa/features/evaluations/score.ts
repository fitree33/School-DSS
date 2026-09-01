import type { EvaluationCriterion } from '@/api/contracts';

export interface CriterionScoreInput {
    criterion: Pick<EvaluationCriterion, 'id' | 'max_score' | 'weight' | 'is_active'>;
    score: string | number | null | undefined;
}

export interface EvaluationTotals {
    totalScore: number;
    maximumScore: number;
    percentage: number | null;
    weightedPercentage: number | null;
    hasCompleteWeights: boolean;
}

export const calculateEvaluationTotals = (inputs: CriterionScoreInput[]): EvaluationTotals | null => {
    const activeInputs = inputs.filter(({ criterion }) => criterion.is_active);
    if (!activeInputs.length) return null;

    const parsed = activeInputs.map(({ criterion, score }) => ({
        score: finiteNumber(score),
        maximum: finiteNumber(criterion.max_score),
        weight: finiteNumber(criterion.weight),
    }));

    if (parsed.some(({ score, maximum }) => score === null || maximum === null || maximum <= 0 || score < 0 || score > maximum)) {
        return null;
    }

    const totalScore = roundTwo(parsed.reduce((total, item) => total + (item.score ?? 0), 0));
    const maximumScore = roundTwo(parsed.reduce((total, item) => total + (item.maximum ?? 0), 0));
    const percentage = maximumScore > 0 ? roundTwo((totalScore / maximumScore) * 100) : null;
    const hasCompleteWeights = parsed.every(({ weight }) => weight !== null && weight > 0);
    const totalWeight = hasCompleteWeights
        ? parsed.reduce((total, item) => total + (item.weight ?? 0), 0)
        : 0;
    const weightedPercentage = hasCompleteWeights && totalWeight > 0
        ? roundTwo(parsed.reduce(
            (total, item) => total + (((item.score ?? 0) / (item.maximum ?? 1)) * (item.weight ?? 0)),
            0,
        ) / totalWeight * 100)
        : null;

    return { totalScore, maximumScore, percentage, weightedPercentage, hasCompleteWeights };
};

export const formatScore = (value: string | number | null | undefined): string => {
    const parsed = finiteNumber(value);
    return parsed === null ? '—' : parsed.toLocaleString('th-TH', { maximumFractionDigits: 2 });
};

export const formatEvaluationPercentage = (value: string | number | null | undefined): string => {
    const parsed = finiteNumber(value);
    return parsed === null ? '—' : `${parsed.toLocaleString('th-TH', { maximumFractionDigits: 2 })}%`;
};

const finiteNumber = (value: string | number | null | undefined): number | null => {
    if (value === null || value === undefined || value === '') return null;
    const parsed = typeof value === 'number' ? value : Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const roundTwo = (value: number): number => Math.round((value + Number.EPSILON) * 100) / 100;
