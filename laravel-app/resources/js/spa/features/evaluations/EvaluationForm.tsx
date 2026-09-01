import { type FormEvent, useEffect, useMemo, useState } from 'react';

import type {
    EvaluationFramework,
    EvaluationScorePayload,
    ProjectEvaluation,
    ProjectEvaluationPayload,
} from '@/api/contracts';
import { FieldError } from '@/components/Feedback';
import { calculateEvaluationTotals, formatEvaluationPercentage, formatScore } from '@/features/evaluations/score';

interface ScoreFormState {
    score: string;
    comment: string;
}

export function EvaluationForm({
    framework,
    initialEvaluation,
    isSubmitting,
    fieldErrors = {},
    onSubmit,
}: {
    framework: EvaluationFramework;
    initialEvaluation?: ProjectEvaluation;
    isSubmitting: boolean;
    fieldErrors?: Record<string, string[]>;
    onSubmit: (payload: ProjectEvaluationPayload) => Promise<void>;
}) {
    const criteria = useMemo(
        () => [...framework.criteria].filter((criterion) => criterion.is_active).sort((left, right) => left.sort_order - right.sort_order || left.id - right.id),
        [framework.criteria],
    );
    const [evaluatedAt, setEvaluatedAt] = useState(dateInputValue(initialEvaluation?.evaluated_at));
    const [comment, setComment] = useState(initialEvaluation?.comment ?? '');
    const [scores, setScores] = useState<Record<number, ScoreFormState>>(() => scoresFor(criteria, initialEvaluation));
    const [clientErrors, setClientErrors] = useState<Record<number, string>>({});

    useEffect(() => {
        setEvaluatedAt(dateInputValue(initialEvaluation?.evaluated_at));
        setComment(initialEvaluation?.comment ?? '');
        setScores(scoresFor(criteria, initialEvaluation));
        setClientErrors({});
    }, [criteria, initialEvaluation]);

    const totals = calculateEvaluationTotals(criteria.map((criterion) => ({ criterion, score: scores[criterion.id]?.score })));

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const errors: Record<number, string> = {};

        criteria.forEach((criterion) => {
            const value = scores[criterion.id]?.score ?? '';
            const parsed = Number(value);
            const maximum = Number(criterion.max_score);
            if (value === '' || !Number.isFinite(parsed)) errors[criterion.id] = 'กรุณากรอกคะแนน';
            else if (parsed < 0 || parsed > maximum) errors[criterion.id] = `คะแนนต้องอยู่ระหว่าง 0 ถึง ${formatScore(maximum)}`;
        });
        setClientErrors(errors);
        if (Object.keys(errors).length) return;

        await onSubmit({
            evaluation_framework_id: framework.id,
            evaluated_at: evaluatedAt,
            comment: nullable(comment),
            scores: criteria.map<EvaluationScorePayload>((criterion) => ({
                evaluation_criterion_id: criterion.id,
                score: scores[criterion.id]?.score ?? '',
                comment: nullable(scores[criterion.id]?.comment ?? ''),
            })),
        });
    };

    const updateScore = (criterionId: number, key: keyof ScoreFormState, value: string) => {
        setScores((current) => ({
            ...current,
            [criterionId]: { ...(current[criterionId] ?? { score: '', comment: '' }), [key]: value },
        }));
        if (key === 'score') setClientErrors((current) => ({ ...current, [criterionId]: '' }));
    };

    return (
        <form className="space-y-5" onSubmit={(event) => void handleSubmit(event)}>
            <section className="spa-card p-5 sm:p-6">
                <div className="grid gap-5 sm:grid-cols-2">
                    <div>
                        <p className="spa-label">ชุดเกณฑ์ที่ใช้</p>
                        <p className="mt-2 font-bold text-slate-900">{framework.name}</p>
                        <p className="mt-1 text-xs text-slate-500">{framework.code} · เวอร์ชัน {framework.version}</p>
                    </div>
                    <label><span className="spa-label">วันที่ประเมิน <span className="text-rose-600">*</span></span><input className="spa-input" onChange={(event) => setEvaluatedAt(event.target.value)} required type="date" value={evaluatedAt} /><FieldError errors={fieldErrors.evaluated_at} /></label>
                </div>
            </section>

            <section className="space-y-4" aria-labelledby="evaluation-criteria-heading">
                <div><h2 className="text-lg font-bold text-slate-950" id="evaluation-criteria-heading">ตัวชี้วัด</h2><p className="mt-1 text-sm text-slate-500">กรอกคะแนนและบันทึกประกอบทีละรายการ</p></div>
                {criteria.map((criterion, index) => {
                    const serverErrors = fieldErrors[`scores.${index}.score`] ?? fieldErrors[`scores.${index}.evaluation_criterion_id`];
                    return (
                        <article className="spa-card p-5 sm:p-6" key={criterion.id}>
                            <div className="flex items-start gap-4">
                                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-teal-100 text-sm font-black text-teal-800">{index + 1}</span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div><h3 className="font-bold text-slate-950">{criterion.name}</h3>{criterion.description && <p className="mt-2 text-sm leading-6 text-slate-600">{criterion.description}</p>}</div>
                                        <div className="flex shrink-0 flex-wrap gap-2 text-xs font-semibold"><span className="rounded-full bg-slate-100 px-3 py-1 text-slate-700">เต็ม {formatScore(criterion.max_score)}</span>{Number(criterion.weight) > 0 && <span className="rounded-full bg-cyan-50 px-3 py-1 text-cyan-800">น้ำหนัก {formatScore(criterion.weight)}</span>}</div>
                                    </div>
                                    {(criterion.evaluation_method || criterion.evaluation_tools) && <dl className="mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2"><Definition label="วิธีประเมิน" value={criterion.evaluation_method} /><Definition label="เครื่องมือประเมิน" value={criterion.evaluation_tools} /></dl>}
                                    <div className="mt-5 grid gap-4 lg:grid-cols-[180px_1fr]">
                                        <label><span className="spa-label">คะแนน <span className="text-rose-600">*</span></span><input className="spa-input" inputMode="decimal" max={String(criterion.max_score)} min="0" onChange={(event) => updateScore(criterion.id, 'score', event.target.value)} required step="0.01" type="number" value={scores[criterion.id]?.score ?? ''} />{clientErrors[criterion.id] ? <p className="mt-1.5 text-xs font-medium text-rose-700">{clientErrors[criterion.id]}</p> : <FieldError errors={serverErrors} />}</label>
                                        <label><span className="spa-label">ความเห็นรายตัวชี้วัด</span><textarea className="spa-input min-h-24 resize-y" onChange={(event) => updateScore(criterion.id, 'comment', event.target.value)} rows={3} value={scores[criterion.id]?.comment ?? ''} /><FieldError errors={fieldErrors[`scores.${index}.comment`]} /></label>
                                    </div>
                                </div>
                            </div>
                        </article>
                    );
                })}
            </section>

            <section className="spa-card p-5 sm:p-6">
                <label><span className="spa-label">ความเห็นภาพรวม</span><textarea className="spa-input min-h-28 resize-y" onChange={(event) => setComment(event.target.value)} rows={4} value={comment} /><FieldError errors={fieldErrors.comment} /></label>
                <div className="mt-5 rounded-2xl border border-cyan-200 bg-cyan-50/60 p-5">
                    <p className="text-sm font-bold text-cyan-950">สรุปคะแนนเพื่อข้อมูลประกอบเท่านั้น</p>
                    {totals ? (
                        <dl className="mt-4 grid gap-4 sm:grid-cols-3">
                            <ScoreDefinition label="คะแนนรวม" value={`${formatScore(totals.totalScore)} / ${formatScore(totals.maximumScore)}`} />
                            <ScoreDefinition label="ร้อยละ" value={formatEvaluationPercentage(totals.percentage)} />
                            <ScoreDefinition label="ร้อยละถ่วงน้ำหนัก" value={totals.hasCompleteWeights ? formatEvaluationPercentage(totals.weightedPercentage) : 'ไม่คำนวณ'} />
                        </dl>
                    ) : <p className="mt-2 text-sm text-cyan-900">กรอกคะแนนให้ครบและอยู่ในช่วงที่กำหนดเพื่อดูผลรวม</p>}
                    <p className="mt-4 text-xs leading-5 text-cyan-900">คะแนนไม่เปลี่ยนผลประเมินโดยอัตโนมัติ ผล pending / passed / failed ต้องสรุปโดยผู้มีสิทธิ์ผ่านขั้นตอน Finalize เท่านั้น</p>
                </div>
            </section>

            <div className="sticky bottom-4 z-20 flex justify-end rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-xl shadow-slate-900/10 backdrop-blur">
                <button className="spa-button-primary" disabled={isSubmitting || !criteria.length} type="submit">{isSubmitting ? 'กำลังบันทึก…' : initialEvaluation ? 'บันทึกการแก้ไขคะแนน' : 'บันทึกการประเมิน'}</button>
            </div>
        </form>
    );
}

function Definition({ label, value }: { label: string; value: string | null }) {
    return <div><dt className="text-xs font-semibold text-slate-500">{label}</dt><dd className="mt-1 whitespace-pre-wrap text-slate-800">{value || '—'}</dd></div>;
}

function ScoreDefinition({ label, value }: { label: string; value: string }) {
    return <div><dt className="text-xs font-semibold text-cyan-800">{label}</dt><dd className="mt-1 text-lg font-black text-cyan-950">{value}</dd></div>;
}

const scoresFor = (criteria: EvaluationFramework['criteria'], evaluation?: ProjectEvaluation): Record<number, ScoreFormState> =>
    Object.fromEntries(criteria.map((criterion) => {
        const saved = evaluation?.scores.find((score) => score.evaluation_criterion_id === criterion.id || score.criterion?.id === criterion.id);
        return [criterion.id, { score: saved === undefined ? '' : String(saved.score), comment: saved?.comment ?? '' }];
    }));

const nullable = (value: string): string | null => value.trim() || null;

const dateInputValue = (value?: string | null): string => value ? value.slice(0, 10) : today();

const today = (): string => {
    const now = new Date();
    const offset = now.getTimezoneOffset() * 60_000;
    return new Date(now.getTime() - offset).toISOString().slice(0, 10);
};
