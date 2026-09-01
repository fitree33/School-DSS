import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { Link, Navigate, useParams } from 'react-router-dom';

import { ApiError, isApiError } from '@/api/client';
import type { EvaluationStatusCode, FinalizeEvaluationPayload } from '@/api/contracts';
import { ErrorState, FieldError, LoadingBlock } from '@/components/Feedback';
import { ArrowLeftIcon, EditIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { dashboardKeys } from '@/features/dashboard/api';
import { evaluationKeys, fetchProjectEvaluation, finalizeProjectEvaluation } from '@/features/evaluations/api';
import { formatEvaluationPercentage, formatScore } from '@/features/evaluations/score';
import { projectKeys } from '@/features/projects/api';
import { formatDate } from '@/features/projects/format';

export function EvaluationDetailPage() {
    const { evaluationId = '' } = useParams();
    const queryClient = useQueryClient();
    const [status, setStatus] = useState<EvaluationStatusCode>('pending');
    const [decisionNote, setDecisionNote] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [submitError, setSubmitError] = useState<string | null>(null);
    const evaluationQuery = useQuery({ queryKey: evaluationKeys.detail(evaluationId), queryFn: () => fetchProjectEvaluation(evaluationId), enabled: evaluationId !== '' });
    const finalizeMutation = useMutation({
        mutationFn: (payload: FinalizeEvaluationPayload) => finalizeProjectEvaluation(evaluationId, payload),
        onSuccess: async (evaluation) => {
            queryClient.setQueryData(evaluationKeys.detail(evaluation.id), evaluation);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: evaluationKeys.all }),
                queryClient.invalidateQueries({ queryKey: projectKeys.detail(evaluation.project.id) }),
                queryClient.invalidateQueries({ queryKey: dashboardKeys.all }),
            ]);
        },
    });

    if (isApiError(evaluationQuery.error) && evaluationQuery.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (evaluationQuery.isPending) return <LoadingBlock label="กำลังโหลดรายละเอียดผลประเมิน" />;
    if (evaluationQuery.isError || !evaluationQuery.data) return <ErrorState action={<Link className="spa-button-secondary" to="/evaluations">กลับรายการประเมิน</Link>} message={isApiError(evaluationQuery.error) ? evaluationQuery.error.message : 'ไม่สามารถโหลดผลประเมินได้'} title="โหลดผลประเมินไม่สำเร็จ" />;

    const evaluation = evaluationQuery.data;
    const result = evaluation.result;
    const displayedTotal = result?.total_score ?? evaluation.total_score;
    const displayedMaximum = result?.maximum_score ?? evaluation.maximum_score;
    const displayedPercentage = result?.percentage ?? evaluation.percentage;
    const displayedWeighted = result?.weighted_percentage ?? evaluation.weighted_percentage;

    const handleFinalize = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setSubmitError(null);
        setFieldErrors({});
        if (!decisionNote.trim()) {
            setFieldErrors({ decision_note: ['กรุณาระบุเหตุผลประกอบการสรุปผล'] });
            return;
        }
        if (!window.confirm(`ยืนยัน Finalize ผลเป็น “${decisionLabel(status)}” ใช่หรือไม่ หลังยืนยันแล้วจะแก้ไขคะแนนไม่ได้`)) return;
        try {
            await finalizeMutation.mutateAsync({ status, decision_note: decisionNote.trim() });
        } catch (error) {
            if (error instanceof ApiError) {
                setSubmitError(error.message);
                setFieldErrors(error.errors);
            } else setSubmitError('ไม่สามารถสรุปผลประเมินได้ กรุณาลองใหม่');
        }
    };

    return (
        <div className="space-y-7">
            <Link className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700" to={`/evaluations/projects/${evaluation.project.id}`}><ArrowLeftIcon className="size-4" />กลับพื้นที่ประเมินโครงการ</Link>
            <PageHeader
                actions={evaluation.abilities.update ? <Link className="spa-button-secondary" to={`/evaluations/${evaluation.id}/edit`}><EditIcon className="size-4" />แก้ไขคะแนน</Link> : undefined}
                description={`${evaluation.framework.name} · ${evaluation.framework.code} v${evaluation.framework.version} · รอบที่ ${evaluation.round}`}
                eyebrow="Evaluation detail"
                title={evaluation.project.name}
            />

            {submitError && <ErrorState message={submitError} title="Finalize ไม่สำเร็จ" />}
            {evaluation.project.fiscal_year?.is_locked && <div className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900" role="status"><p className="font-bold">ปีงบประมาณ {evaluation.project.fiscal_year.year} ถูกล็อกแล้ว</p><p className="mt-1 leading-6">ผลประเมินและคะแนนเป็นแบบอ่านอย่างเดียว ไม่สามารถแก้ไขหรือ Finalize</p></div>}

            <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label="คะแนนรวม" value={`${formatScore(displayedTotal)} / ${formatScore(displayedMaximum)}`} />
                <MetricCard label="ร้อยละ" value={formatEvaluationPercentage(displayedPercentage)} />
                <MetricCard label="ร้อยละถ่วงน้ำหนัก" value={formatEvaluationPercentage(displayedWeighted)} />
                <div className="spa-card p-5"><p className="text-xs font-semibold text-slate-500">ผลประเมิน</p><div className="mt-3">{result ? <StatusBadge code={result.status.code} label={result.status.name} /> : <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">ยังไม่ Finalize</span>}</div></div>
            </section>

            <section className="spa-card p-5 sm:p-6">
                <dl className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    <Definition label="ผู้ประเมิน" value={evaluation.evaluator?.name} />
                    <Definition label="วันที่ประเมิน" value={formatDate(evaluation.evaluated_at)} />
                    <Definition label="สถานะข้อมูล" value={evaluation.is_finalized || result ? 'Finalized · อ่านอย่างเดียว' : 'ยังไม่ Finalize'} />
                    <Definition label="วันที่ Finalize" value={formatDate(result?.finalized_at ?? evaluation.finalized_at)} />
                </dl>
                <div className="mt-6 border-t border-slate-100 pt-5"><p className="text-xs font-semibold text-slate-500">ความเห็นภาพรวม</p><p className="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-700">{evaluation.comment || '—'}</p></div>
                {result && <div className="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-5"><p className="text-xs font-semibold text-slate-500">เหตุผลการสรุปผล</p><p className="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-800">{result.decision_note}</p><p className="mt-3 text-xs text-slate-500">Finalize โดย {result.finalized_by?.name ?? '—'} · {formatDate(result.finalized_at)}</p></div>}
            </section>

            <section className="space-y-4" aria-labelledby="evaluation-score-detail-heading">
                <div><h2 className="text-lg font-bold text-slate-950" id="evaluation-score-detail-heading">คะแนนรายตัวชี้วัด</h2><p className="mt-1 text-sm text-slate-500">คะแนนทั้งหมดเป็นข้อมูลประกอบการตัดสินใจ ไม่มีการกำหนดผ่าน/ไม่ผ่านอัตโนมัติ</p></div>
                {[...evaluation.scores].sort((left, right) => (left.criterion?.sort_order ?? 0) - (right.criterion?.sort_order ?? 0)).map((score, index) => (
                    <article className="spa-card p-5 sm:p-6" key={score.id ?? score.evaluation_criterion_id}>
                        <div className="flex items-start gap-4"><span className="grid size-9 shrink-0 place-items-center rounded-full bg-teal-100 text-sm font-black text-teal-800">{index + 1}</span><div className="min-w-0 flex-1"><div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="font-bold text-slate-950">{score.criterion?.name ?? 'ตัวชี้วัด'}</h3>{score.criterion?.description && <p className="mt-2 text-sm leading-6 text-slate-600">{score.criterion.description}</p>}</div><p className="shrink-0 text-lg font-black text-teal-800">{formatScore(score.score)} / {formatScore(score.criterion?.max_score)}</p></div>{score.comment && <p className="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700">{score.comment}</p>}</div></div>
                    </article>
                ))}
            </section>

            {evaluation.abilities.finalize && !result && (
                <section className="spa-card border-teal-200 p-5 sm:p-6" aria-labelledby="finalize-heading">
                    <h2 className="text-lg font-bold text-slate-950" id="finalize-heading">Finalize ผลประเมิน</h2>
                    <p className="mt-2 text-sm leading-6 text-slate-600">การดำเนินการนี้บันทึกผลอย่างชัดเจนและเปลี่ยนสถานะโครงการ หลังยืนยันแล้วการประเมินรอบนี้จะแก้ไขไม่ได้</p>
                    <form className="mt-5 space-y-5" onSubmit={(event) => void handleFinalize(event)}>
                        <label><span className="spa-label">ผลประเมิน <span className="text-rose-600">*</span></span><select className="spa-input sm:max-w-sm" onChange={(event) => setStatus(event.target.value as EvaluationStatusCode)} required value={status}><option value="pending">รอประเมิน (pending)</option><option value="passed">ผ่าน (passed)</option><option value="failed">ไม่ผ่าน (failed)</option></select><FieldError errors={fieldErrors.status} /></label>
                        <label><span className="spa-label">เหตุผลประกอบการตัดสินใจ <span className="text-rose-600">*</span></span><textarea className="spa-input min-h-28 resize-y" onChange={(event) => setDecisionNote(event.target.value)} required rows={4} value={decisionNote} /><FieldError errors={fieldErrors.decision_note} /></label>
                        <button className="spa-button-primary" disabled={finalizeMutation.isPending} type="submit">{finalizeMutation.isPending ? 'กำลัง Finalize…' : 'ยืนยัน Finalize ผลประเมิน'}</button>
                    </form>
                </section>
            )}
        </div>
    );
}

function MetricCard({ label, value }: { label: string; value: string }) {
    return <div className="spa-card p-5"><p className="text-xs font-semibold text-slate-500">{label}</p><p className="mt-2 text-xl font-bold text-slate-950">{value}</p></div>;
}

function Definition({ label, value }: { label: string; value?: string | null }) {
    return <div><dt className="text-xs font-semibold text-slate-500">{label}</dt><dd className="mt-1.5 text-sm font-medium text-slate-900">{value || '—'}</dd></div>;
}

const decisionLabel = (status: EvaluationStatusCode): string => ({ pending: 'รอประเมิน', passed: 'ผ่าน', failed: 'ไม่ผ่าน' })[status];
