import { Link } from 'react-router-dom';

import type { ProjectEvaluation } from '@/api/contracts';
import { EmptyState } from '@/components/Feedback';
import { EditIcon } from '@/components/Icons';
import { StatusBadge } from '@/components/StatusBadge';
import { formatEvaluationPercentage, formatScore } from '@/features/evaluations/score';
import { formatDate } from '@/features/projects/format';

export function EvaluationHistory({ evaluations }: { evaluations: ProjectEvaluation[] }) {
    if (!evaluations.length) return <EmptyState description="เมื่อบันทึกคะแนนครั้งแรก ประวัติจะปรากฏที่นี่" title="โครงการนี้ยังไม่มีประวัติการประเมิน" />;

    return (
        <section className="spa-card overflow-hidden" aria-labelledby="evaluation-history-heading">
            <div className="border-b border-slate-100 px-5 py-4 sm:px-6"><h2 className="font-bold text-slate-950" id="evaluation-history-heading">ประวัติการประเมิน</h2><p className="mt-1 text-xs text-slate-500">รายการที่ Finalize แล้วเป็นประวัติถาวรและแก้ไขไม่ได้</p></div>
            <div className="divide-y divide-slate-100">
                {evaluations.map((evaluation) => (
                    <article className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:px-6" key={evaluation.id}>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2"><p className="font-bold text-slate-900">รอบที่ {evaluation.round}</p>{evaluation.result ? <StatusBadge code={evaluation.result.status.code} label={evaluation.result.status.name} /> : <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">ยังไม่ Finalize</span>}</div>
                            <p className="mt-2 text-sm text-slate-600">{evaluation.framework.name} · {evaluation.framework.code} v{evaluation.framework.version}</p>
                            <p className="mt-1 text-xs text-slate-500">{formatDate(evaluation.evaluated_at)} · {evaluation.evaluator?.name ?? 'ไม่ระบุผู้ประเมิน'}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-3 sm:justify-end">
                            <div className="text-right"><p className="text-sm font-bold text-slate-900">{formatScore(evaluation.total_score)} / {formatScore(evaluation.maximum_score)}</p><p className="mt-1 text-xs text-slate-500">{formatEvaluationPercentage(evaluation.percentage)}</p></div>
                            {evaluation.abilities.update && <Link aria-label={`แก้ไขการประเมินรอบที่ ${evaluation.round}`} className="spa-button-secondary" to={`/evaluations/${evaluation.id}/edit`}><EditIcon className="size-4" />แก้ไข</Link>}
                            <Link className="spa-button-secondary" to={`/evaluations/${evaluation.id}`}>ดูรายละเอียด</Link>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}
