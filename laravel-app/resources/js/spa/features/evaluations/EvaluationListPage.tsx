import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';

import { isApiError } from '@/api/client';
import type { EvaluationProject } from '@/api/contracts';
import { EmptyState, ErrorState, LoadingBlock } from '@/components/Feedback';
import { PlusIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import { Pagination } from '@/components/Pagination';
import { StatusBadge } from '@/components/StatusBadge';
import { evaluationKeys, fetchEvaluationOptions, fetchEvaluationProjects } from '@/features/evaluations/api';
import { evaluationFiltersFromSearch } from '@/features/evaluations/filters';
import { formatEvaluationPercentage, formatScore } from '@/features/evaluations/score';
import { formatDate, yearLabel } from '@/features/projects/format';

export function EvaluationListPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const filters = evaluationFiltersFromSearch(searchParams);
    const optionsQuery = useQuery({
        queryKey: evaluationKeys.options,
        queryFn: fetchEvaluationOptions,
        staleTime: 5 * 60_000,
    });
    const projectsQuery = useQuery({
        queryKey: evaluationKeys.projects(filters),
        queryFn: () => fetchEvaluationProjects(filters),
    });

    if (optionsQuery.isPending || projectsQuery.isPending) return <LoadingBlock label="กำลังโหลดโครงการสำหรับการประเมิน" />;
    if (optionsQuery.isError || projectsQuery.isError) {
        const error = optionsQuery.error ?? projectsQuery.error;
        return (
            <ErrorState
                action={<button className="spa-button-primary" onClick={() => void Promise.all([optionsQuery.refetch(), projectsQuery.refetch()])} type="button">ลองใหม่</button>}
                message={isApiError(error) ? error.message : 'ไม่สามารถโหลดรายการประเมินได้'}
                title="โหลดระบบประเมินไม่สำเร็จ"
            />
        );
    }

    const updateFilter = (name: string, value: string) => {
        const next = new URLSearchParams(searchParams);
        if (value) next.set(name, value);
        else next.delete(name);
        if (name !== 'page') next.delete('page');
        setSearchParams(next, { replace: true });
    };
    const options = optionsQuery.data;
    const projects = projectsQuery.data;
    const selectedYear = options.fiscal_years.find((year) => String(year.id) === filters.fiscal_year_id);

    return (
        <div className="space-y-7">
            <PageHeader
                actions={options.can.manage_frameworks ? <Link className="spa-button-secondary" to="/evaluations/frameworks">จัดการชุดเกณฑ์</Link> : undefined}
                description="เลือกโครงการ บันทึกคะแนนตามตัวชี้วัด และติดตามผลประเมินย้อนหลัง"
                eyebrow="Project evaluation"
                title="ระบบประเมินโครงการ"
            />

            <section aria-label="ตัวกรองรายการประเมิน" className="spa-card grid gap-4 p-4 sm:grid-cols-3 sm:p-5">
                <FilterSelect label="ปีงบประมาณ" onChange={(value) => updateFilter('fiscal_year_id', value)} value={filters.fiscal_year_id ?? ''}>
                    <option value="">ทุกปีงบประมาณ</option>
                    {options.fiscal_years.map((year) => <option key={year.id} value={year.id}>{yearLabel(year.year)}{year.is_locked ? ' · ล็อกแล้ว' : ''}</option>)}
                </FilterSelect>
                <FilterSelect label="ฝ่าย/กลุ่มงาน" onChange={(value) => updateFilter('department_id', value)} value={filters.department_id ?? ''}>
                    <option value="">ทุกฝ่าย/กลุ่มงาน</option>
                    {options.departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
                </FilterSelect>
                <FilterSelect label="ผลประเมินล่าสุด" onChange={(value) => updateFilter('evaluation_status', value)} value={filters.evaluation_status || 'all'}>
                    <option value="all">ทุกสถานะ</option>
                    {options.evaluation_statuses.map((status) => <option key={status.id} value={status.code}>{status.name}</option>)}
                </FilterSelect>
            </section>

            {selectedYear?.is_locked && (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900" role="status">
                    <p className="font-bold">ปีงบประมาณ {yearLabel(selectedYear.year)} ถูกล็อกแล้ว</p>
                    <p className="mt-1 leading-6">เปิดดูรายการและประวัติได้ แต่ไม่สามารถสร้าง แก้ไข หรือสรุปผลประเมิน</p>
                </div>
            )}

            {!projects.data.length ? (
                <EmptyState description="ลองเปลี่ยนปีงบประมาณ ฝ่าย หรือสถานะที่เลือก" title="ไม่พบโครงการตามตัวกรอง" />
            ) : (
                <section className="spa-card overflow-hidden" aria-label="รายการโครงการสำหรับการประเมิน">
                    <div className="hidden overflow-x-auto md:block">
                        <table className="min-w-full divide-y divide-slate-200 text-left">
                            <thead className="bg-slate-50 text-xs font-bold text-slate-600">
                                <tr><th className="px-5 py-3">โครงการ</th><th className="px-5 py-3">ปี / ฝ่าย</th><th className="px-5 py-3">ผลล่าสุด</th><th className="px-5 py-3">คะแนนล่าสุด</th><th className="px-5 py-3 text-right">ดำเนินการ</th></tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {projects.data.map((project) => <ProjectRow key={project.id} project={project} />)}
                            </tbody>
                        </table>
                    </div>
                    <div className="divide-y divide-slate-100 md:hidden">
                        {projects.data.map((project) => <ProjectCard key={project.id} project={project} />)}
                    </div>
                    <Pagination
                        currentPage={projects.meta.current_page}
                        from={projects.meta.from}
                        lastPage={projects.meta.last_page}
                        onPageChange={(page) => updateFilter('page', String(page))}
                        to={projects.meta.to}
                        total={projects.meta.total}
                    />
                </section>
            )}
        </div>
    );
}

function ProjectRow({ project }: { project: EvaluationProject }) {
    return (
        <tr className="hover:bg-slate-50/70">
            <td className="px-5 py-4"><ProjectIdentity project={project} /></td>
            <td className="px-5 py-4 text-sm text-slate-600"><p>{yearLabel(project.fiscal_year?.year)}</p><p className="mt-1 text-xs text-slate-500">{project.department?.name ?? 'ไม่ระบุฝ่าย'}</p></td>
            <td className="px-5 py-4"><StatusBadge code={project.evaluation_status?.code} label={project.evaluation_status?.name} /></td>
            <td className="px-5 py-4"><LatestScore project={project} /></td>
            <td className="px-5 py-4 text-right"><ProjectAction project={project} /></td>
        </tr>
    );
}

function ProjectCard({ project }: { project: EvaluationProject }) {
    return (
        <article className="p-5">
            <div className="flex items-start justify-between gap-3"><ProjectIdentity project={project} /><StatusBadge code={project.evaluation_status?.code} label={project.evaluation_status?.name} /></div>
            <dl className="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt className="text-xs text-slate-500">ปีงบประมาณ / ฝ่าย</dt><dd className="mt-1 font-medium text-slate-800">{yearLabel(project.fiscal_year?.year)} · {project.department?.name ?? '—'}</dd></div><div><dt className="text-xs text-slate-500">คะแนนล่าสุด</dt><dd className="mt-1"><LatestScore project={project} /></dd></div></dl>
            <div className="mt-4"><ProjectAction project={project} wide /></div>
        </article>
    );
}

function ProjectIdentity({ project }: { project: EvaluationProject }) {
    return <div><p className="font-semibold text-slate-900">{project.name}</p><p className="mt-1 text-xs text-slate-500">{project.project_code || 'ไม่มีรหัส'} · ประเมินแล้ว {project.evaluation_count ?? 0} รอบ</p></div>;
}

function LatestScore({ project }: { project: EvaluationProject }) {
    const latest = project.latest_evaluation;
    if (!latest) return <span className="text-sm text-slate-400">ยังไม่มีคะแนน</span>;
    return <div className="text-sm"><p className="font-bold text-slate-900">{formatScore(latest.total_score)} / {formatScore(latest.maximum_score)}</p><p className="mt-1 text-xs text-slate-500">{formatEvaluationPercentage(latest.percentage)} · {formatDate(latest.evaluated_at)}</p></div>;
}

function ProjectAction({ project, wide = false }: { project: EvaluationProject; wide?: boolean }) {
    if (!project.abilities.view_evaluations) return <span className="text-xs text-slate-400">ไม่มีสิทธิ์เปิดดู</span>;
    return (
        <Link className={`${project.abilities.create_evaluation ? 'spa-button-primary' : 'spa-button-secondary'} ${wide ? 'w-full' : ''}`} to={`/evaluations/projects/${project.id}`}>
            {project.abilities.create_evaluation && <PlusIcon className="size-4" />}{project.abilities.create_evaluation ? 'ประเมินโครงการ' : 'ดูประวัติ'}
        </Link>
    );
}

function FilterSelect({ label, value, onChange, children }: { label: string; value: string; onChange: (value: string) => void; children: React.ReactNode }) {
    return <label><span className="spa-label">{label}</span><select className="spa-input" onChange={(event) => onChange(event.target.value)} value={value}>{children}</select></label>;
}
