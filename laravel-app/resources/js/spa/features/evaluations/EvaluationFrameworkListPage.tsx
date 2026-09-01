import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, Navigate, useSearchParams } from 'react-router-dom';

import { isApiError } from '@/api/client';
import type { EvaluationFramework } from '@/api/contracts';
import { EmptyState, ErrorState, LoadingBlock } from '@/components/Feedback';
import { EditIcon, PlusIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import { Pagination } from '@/components/Pagination';
import { evaluationKeys, fetchEvaluationFrameworks, setEvaluationFrameworkActive } from '@/features/evaluations/api';
import { formatDate, yearLabel } from '@/features/projects/format';

export function EvaluationFrameworkListPage() {
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] = useSearchParams();
    const requestedPage = Number.parseInt(searchParams.get('page') ?? '1', 10);
    const page = Number.isFinite(requestedPage) && requestedPage > 0 ? requestedPage : 1;
    const frameworksQuery = useQuery({
        queryKey: evaluationKeys.frameworkList(page),
        queryFn: () => fetchEvaluationFrameworks(page),
    });
    const toggleMutation = useMutation({
        mutationFn: ({ framework, active }: { framework: EvaluationFramework; active: boolean }) => setEvaluationFrameworkActive(framework.id, active),
        onSuccess: async (framework) => {
            queryClient.setQueryData(evaluationKeys.framework(framework.id), framework);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: evaluationKeys.frameworks }),
                queryClient.invalidateQueries({ queryKey: evaluationKeys.options }),
            ]);
        },
    });

    if (isApiError(frameworksQuery.error) && frameworksQuery.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (frameworksQuery.isPending) return <LoadingBlock label="กำลังโหลดชุดเกณฑ์ประเมิน" />;
    if (frameworksQuery.isError) return <ErrorState action={<button className="spa-button-primary" onClick={() => void frameworksQuery.refetch()} type="button">ลองใหม่</button>} message={isApiError(frameworksQuery.error) ? frameworksQuery.error.message : 'ไม่สามารถโหลดชุดเกณฑ์ได้'} title="โหลดชุดเกณฑ์ไม่สำเร็จ" />;

    return (
        <div className="space-y-7">
            <PageHeader actions={<Link className="spa-button-primary" to="/evaluations/frameworks/new"><PlusIcon className="size-4" />สร้างชุดเกณฑ์</Link>} description="จัดการชื่อ ช่วงเวลา เวอร์ชัน ตัวชี้วัด คะแนนเต็ม น้ำหนัก วิธีและเครื่องมือประเมิน" eyebrow="Evaluation frameworks" title="ชุดเกณฑ์ประเมินของโรงเรียน" />

            {toggleMutation.isError && <ErrorState message={isApiError(toggleMutation.error) ? toggleMutation.error.message : 'ไม่สามารถเปลี่ยนสถานะชุดเกณฑ์ได้'} title="เปลี่ยนสถานะไม่สำเร็จ" />}

            {!frameworksQuery.data.data.length ? (
                <EmptyState action={<Link className="spa-button-primary" to="/evaluations/frameworks/new">สร้างชุดเกณฑ์แรก</Link>} description="เริ่มจากสร้าง framework และตัวชี้วัด จากนั้นเปิดใช้งานเมื่อพร้อม" title="ยังไม่มีชุดเกณฑ์ Phase 4" />
            ) : (
                <>
                    <div className="grid gap-5 xl:grid-cols-2">
                        {frameworksQuery.data.data.map((framework) => (
                        <article className={`spa-card overflow-hidden ${framework.is_active ? 'border-teal-200' : ''}`} key={framework.id}>
                            <div className="border-b border-slate-100 p-5 sm:p-6">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div><div className="flex flex-wrap items-center gap-2"><h2 className="font-bold text-slate-950">{framework.name}</h2><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${framework.is_active ? 'bg-teal-100 text-teal-800' : 'bg-slate-100 text-slate-700'}`}>{framework.is_active ? 'Active' : 'Inactive'}</span>{framework.is_used && <span className="rounded-full bg-cyan-50 px-2.5 py-1 text-xs font-semibold text-cyan-800">ถูกใช้แล้ว</span>}</div><p className="mt-2 text-sm font-semibold text-slate-600">{framework.code} · เวอร์ชัน {framework.version}</p></div>
                                    <span className="rounded-xl bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">{framework.criteria.length.toLocaleString('th-TH')} ตัวชี้วัด</span>
                                </div>
                                {framework.description && <p className="mt-4 text-sm leading-6 text-slate-600">{framework.description}</p>}
                                <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2"><Definition label="ปีงบประมาณ" value={framework.fiscal_year ? yearLabel(framework.fiscal_year.year) : 'ใช้ได้ทุกปี'} /><Definition label="ช่วงเวลาที่ใช้" value={`${formatDate(framework.effective_from)} – ${formatDate(framework.effective_to)}`} /></dl>
                            </div>
                            <div className="flex flex-wrap gap-2 bg-slate-50/60 px-5 py-4 sm:px-6">
                                {framework.abilities?.update && <Link className="spa-button-secondary" to={`/evaluations/frameworks/${framework.id}/edit`}><EditIcon className="size-4" />แก้ไข</Link>}
                                {framework.abilities?.create_version && <Link className="spa-button-secondary" to={`/evaluations/frameworks/new?source_id=${framework.id}`}><PlusIcon className="size-4" />สร้างเวอร์ชันใหม่</Link>}
                                {framework.is_active && framework.abilities?.deactivate && <button className="spa-button-secondary" disabled={toggleMutation.isPending} onClick={() => toggleMutation.mutate({ framework, active: false })} type="button">ปิดใช้งาน</button>}
                                {!framework.is_active && framework.abilities?.activate && <button className="spa-button-primary" disabled={toggleMutation.isPending} onClick={() => toggleMutation.mutate({ framework, active: true })} type="button">เปิดใช้งาน</button>}
                            </div>
                        </article>
                        ))}
                    </div>
                    <div className="spa-card overflow-hidden">
                        <Pagination
                            currentPage={frameworksQuery.data.meta.current_page}
                            from={frameworksQuery.data.meta.from}
                            lastPage={frameworksQuery.data.meta.last_page}
                            onPageChange={(nextPage) => {
                                const next = new URLSearchParams(searchParams);
                                if (nextPage > 1) next.set('page', String(nextPage));
                                else next.delete('page');
                                setSearchParams(next, { replace: true });
                            }}
                            to={frameworksQuery.data.meta.to}
                            total={frameworksQuery.data.meta.total}
                        />
                    </div>
                </>
            )}
        </div>
    );
}

function Definition({ label, value }: { label: string; value: string }) {
    return <div><dt className="text-xs font-semibold text-slate-500">{label}</dt><dd className="mt-1.5 font-medium text-slate-800">{value}</dd></div>;
}
