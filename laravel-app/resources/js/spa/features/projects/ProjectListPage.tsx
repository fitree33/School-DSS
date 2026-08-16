import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useEffect, useState } from 'react';
import { Link, Navigate, useSearchParams } from 'react-router-dom';

import { isApiError } from '@/api/client';
import type { ProjectFilters } from '@/api/contracts';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, LoadingBlock } from '@/components/Feedback';
import { EditIcon, PlusIcon, SearchIcon, TrashIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import { Pagination } from '@/components/Pagination';
import { StatusBadge } from '@/components/StatusBadge';
import { deleteProject, fetchProjectOptions, fetchProjects, projectKeys } from '@/features/projects/api';
import { formatCurrency, yearLabel } from '@/features/projects/format';

interface FilterDraft {
    q: string;
    fiscal_year_id: string;
    department_id: string;
    execution_status: string;
    evaluation_status: string;
}

export function ProjectListPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const queryClient = useQueryClient();
    const { hasPermission } = useAuth();
    const filters = filtersFromParams(searchParams);
    const [draft, setDraft] = useState<FilterDraft>(() => draftFromFilters(filters));

    useEffect(() => setDraft(draftFromFilters(filters)), [searchParams.toString()]);

    const projectsQuery = useQuery({
        queryKey: projectKeys.list(filters),
        queryFn: () => fetchProjects(filters),
    });
    const optionsQuery = useQuery({
        queryKey: projectKeys.options,
        queryFn: fetchProjectOptions,
        staleTime: 5 * 60_000,
    });
    const deleteMutation = useMutation({
        mutationFn: deleteProject,
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: projectKeys.all });
        },
    });

    if (isApiError(projectsQuery.error) && projectsQuery.error.status === 403) {
        return <Navigate replace to="/forbidden" />;
    }

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setSearchParams(paramsFromDraft(draft));
    };

    const resetFilters = () => {
        setDraft(emptyDraft);
        setSearchParams({});
    };

    const changePage = (page: number) => {
        const next = new URLSearchParams(searchParams);
        if (page <= 1) next.delete('page');
        else next.set('page', String(page));
        setSearchParams(next);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const confirmDelete = async (id: number, name: string) => {
        if (!window.confirm(`ยืนยันการลบโครงการ “${name}” ใช่หรือไม่`)) return;
        try {
            await deleteMutation.mutateAsync(id);
        } catch {
            // The mutation error is rendered below so the user can retry safely.
        }
    };

    const hasFilters = Object.values(filters).some((value) => value !== undefined && value !== 1 && value !== 15);
    const data = projectsQuery.data;
    const options = optionsQuery.data;

    return (
        <div className="space-y-7">
            <PageHeader
                actions={hasPermission('projects.create') ? <Link className="spa-button-primary" to="/projects/new"><PlusIcon className="size-4" />สร้างโครงการ</Link> : undefined}
                description="ค้นหาและติดตามงบประมาณ สถานะดำเนินงาน และผลประเมินในระดับโครงการ"
                eyebrow="Projects"
                title="โครงการทั้งหมด"
            />

            <form className="spa-card p-4 sm:p-5" onSubmit={applyFilters}>
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <label className="relative md:col-span-2 xl:col-span-1">
                        <span className="spa-label">ค้นหา</span>
                        <SearchIcon className="pointer-events-none absolute bottom-3.5 left-3.5 size-4 text-slate-400" />
                        <input className="spa-input pl-10" onChange={(event) => setDraftValue(setDraft, 'q', event.target.value)} placeholder="ชื่อ รหัส หรือวัตถุประสงค์" value={draft.q} />
                    </label>
                    <FilterSelect label="ปีงบประมาณ" onChange={(value) => setDraftValue(setDraft, 'fiscal_year_id', value)} value={draft.fiscal_year_id}>
                        {options?.fiscal_years.map((year) => <option key={year.id} value={year.id}>{yearLabel(year.year)}</option>)}
                    </FilterSelect>
                    <FilterSelect label="ฝ่าย/กลุ่มงาน" onChange={(value) => setDraftValue(setDraft, 'department_id', value)} value={draft.department_id}>
                        {options?.departments.map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
                    </FilterSelect>
                    <FilterSelect label="สถานะโครงการ" onChange={(value) => setDraftValue(setDraft, 'execution_status', value)} value={draft.execution_status}>
                        {options?.execution_statuses.map((status) => <option key={status.id} value={status.code}>{status.name}</option>)}
                    </FilterSelect>
                    <FilterSelect label="ผลประเมิน" onChange={(value) => setDraftValue(setDraft, 'evaluation_status', value)} value={draft.evaluation_status}>
                        {options?.evaluation_statuses.map((status) => <option key={status.id} value={status.code}>{status.name}</option>)}
                    </FilterSelect>
                </div>
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <p className="text-xs text-slate-500">{optionsQuery.isError ? 'โหลดตัวเลือกตัวกรองไม่สำเร็จ แต่ยังค้นหาด้วยข้อความได้' : 'กดค้นหาเพื่อใช้ตัวกรองที่เลือก'}</p>
                    <div className="flex gap-2">
                        <button className="spa-button-secondary min-h-10" disabled={!hasFilters && Object.values(draft).every((value) => !value)} onClick={resetFilters} type="button">ล้างตัวกรอง</button>
                        <button className="spa-button-primary min-h-10" type="submit"><SearchIcon className="size-4" />ค้นหา</button>
                    </div>
                </div>
            </form>

            {deleteMutation.isError && (
                <ErrorState message={deleteMutation.error instanceof Error ? deleteMutation.error.message : 'ไม่สามารถลบโครงการได้'} title="ลบโครงการไม่สำเร็จ" />
            )}

            {projectsQuery.isPending ? (
                <LoadingBlock label="กำลังโหลดรายการโครงการ" />
            ) : projectsQuery.isError ? (
                <ErrorState
                    action={<button className="spa-button-primary" onClick={() => void projectsQuery.refetch()} type="button">ลองใหม่</button>}
                    message={projectsQuery.error instanceof Error ? projectsQuery.error.message : 'ไม่สามารถโหลดรายการโครงการได้'}
                />
            ) : !data || data.data.length === 0 ? (
                <EmptyState
                    action={hasFilters ? <button className="spa-button-secondary" onClick={resetFilters} type="button">ล้างตัวกรอง</button> : hasPermission('projects.create') ? <Link className="spa-button-primary" to="/projects/new">สร้างโครงการแรก</Link> : undefined}
                    description={hasFilters ? 'ลองเปลี่ยนคำค้นหาหรือล้างตัวกรองเพื่อดูผลลัพธ์เพิ่มเติม' : 'เมื่อสร้างโครงการแล้ว รายการและสถานะงบประมาณจะปรากฏที่หน้านี้'}
                    title={hasFilters ? 'ไม่พบโครงการที่ตรงกับตัวกรอง' : 'ยังไม่มีโครงการ'}
                />
            ) : (
                <section className="spa-card overflow-hidden">
                    <div className="hidden overflow-x-auto lg:block">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50/80">
                                <tr className="text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                                    <th className="px-6 py-4">โครงการ</th><th className="px-4 py-4">ฝ่าย</th><th className="px-4 py-4">งบประมาณ</th><th className="px-4 py-4">สถานะ</th><th className="px-4 py-4">ผลประเมิน</th><th className="px-6 py-4 text-right">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((project) => (
                                    <tr className="transition hover:bg-slate-50/70" key={project.id}>
                                        <td className="px-6 py-4">
                                            <Link className="font-semibold text-slate-900 hover:text-teal-700" to={`/projects/${project.id}`}>{project.name}</Link>
                                            <p className="mt-1 text-xs text-slate-500">{project.project_code || 'ไม่มีรหัส'} · ปีงบ {yearLabel(project.fiscal_year?.year)}</p>
                                        </td>
                                        <td className="px-4 py-4 text-sm text-slate-600">{project.department?.name ?? '—'}</td>
                                        <td className="px-4 py-4 text-sm font-semibold text-slate-800">{formatCurrency(project.budget)}</td>
                                        <td className="px-4 py-4"><StatusBadge code={project.execution_status?.code} label={project.execution_status?.name} /></td>
                                        <td className="px-4 py-4"><StatusBadge code={project.evaluation_status?.code} label={project.evaluation_status?.name} /></td>
                                        <td className="px-6 py-4">
                                            <div className="flex justify-end gap-1">
                                                {project.abilities.update && <Link aria-label={`แก้ไข ${project.name}`} className="rounded-lg p-2 text-slate-500 hover:bg-teal-50 hover:text-teal-700" to={`/projects/${project.id}/edit`}><EditIcon className="size-4" /></Link>}
                                                {project.abilities.delete && <button aria-label={`ลบ ${project.name}`} className="rounded-lg p-2 text-slate-500 hover:bg-rose-50 hover:text-rose-700" disabled={deleteMutation.isPending} onClick={() => void confirmDelete(project.id, project.name)} type="button"><TrashIcon className="size-4" /></button>}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="divide-y divide-slate-100 lg:hidden">
                        {data.data.map((project) => (
                            <article className="p-4 sm:p-5" key={project.id}>
                                <div className="flex items-start justify-between gap-3">
                                    <div><p className="text-xs font-medium text-teal-700">{project.project_code || 'ไม่มีรหัส'}</p><Link className="mt-1 block font-bold text-slate-900" to={`/projects/${project.id}`}>{project.name}</Link></div>
                                    <p className="whitespace-nowrap text-sm font-bold text-slate-800">{formatCurrency(project.budget)}</p>
                                </div>
                                <p className="mt-2 text-sm text-slate-500">{project.department?.name ?? 'ไม่ระบุฝ่าย'} · ปีงบ {yearLabel(project.fiscal_year?.year)}</p>
                                <div className="mt-4 flex flex-wrap gap-2"><StatusBadge code={project.execution_status?.code} label={project.execution_status?.name} /><StatusBadge code={project.evaluation_status?.code} label={project.evaluation_status?.name} /></div>
                                <div className="mt-4 flex gap-2">
                                    <Link className="spa-button-secondary min-h-10 flex-1" to={`/projects/${project.id}`}>ดูรายละเอียด</Link>
                                    {project.abilities.update && <Link aria-label="แก้ไขโครงการ" className="spa-button-secondary min-h-10 px-3" to={`/projects/${project.id}/edit`}><EditIcon className="size-4" /></Link>}
                                    {project.abilities.delete && <button aria-label="ลบโครงการ" className="spa-button-secondary min-h-10 px-3 text-rose-700" disabled={deleteMutation.isPending} onClick={() => void confirmDelete(project.id, project.name)} type="button"><TrashIcon className="size-4" /></button>}
                                </div>
                            </article>
                        ))}
                    </div>

                    <Pagination currentPage={data.meta.current_page} from={data.meta.from} lastPage={data.meta.last_page} onPageChange={changePage} to={data.meta.to} total={data.meta.total} />
                </section>
            )}
        </div>
    );
}

function FilterSelect({ label, value, onChange, children }: { label: string; value: string; onChange: (value: string) => void; children: React.ReactNode }) {
    return <label><span className="spa-label">{label}</span><select className="spa-input" onChange={(event) => onChange(event.target.value)} value={value}><option value="">ทั้งหมด</option>{children}</select></label>;
}

const emptyDraft: FilterDraft = { q: '', fiscal_year_id: '', department_id: '', execution_status: '', evaluation_status: '' };

const setDraftValue = (setter: React.Dispatch<React.SetStateAction<FilterDraft>>, key: keyof FilterDraft, value: string) => setter((current) => ({ ...current, [key]: value }));

const filtersFromParams = (params: URLSearchParams): ProjectFilters => ({
    q: params.get('q') || undefined,
    fiscal_year_id: params.get('fiscal_year_id') || undefined,
    department_id: params.get('department_id') || undefined,
    execution_status: params.get('execution_status') || undefined,
    evaluation_status: params.get('evaluation_status') || undefined,
    page: Math.max(Number(params.get('page')) || 1, 1),
    per_page: 15,
});

const draftFromFilters = (filters: ProjectFilters): FilterDraft => ({
    q: filters.q ?? '', fiscal_year_id: filters.fiscal_year_id ?? '', department_id: filters.department_id ?? '', execution_status: filters.execution_status ?? '', evaluation_status: filters.evaluation_status ?? '',
});

const paramsFromDraft = (draft: FilterDraft): URLSearchParams => {
    const params = new URLSearchParams();
    Object.entries(draft).forEach(([key, value]) => { if (value.trim()) params.set(key, value.trim()); });
    return params;
};
