import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';

import { isApiError } from '@/api/client';
import { ErrorState, LoadingBlock } from '@/components/Feedback';
import { ArrowLeftIcon, EditIcon, TrashIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { dashboardKeys } from '@/features/dashboard/api';
import { deleteProject, fetchProject, projectKeys } from '@/features/projects/api';
import { formatCurrency, formatDate, projectBudgetView, yearLabel } from '@/features/projects/format';

export function ProjectDetailPage() {
    const { projectId = '' } = useParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const projectQuery = useQuery({
        queryKey: projectKeys.detail(projectId),
        queryFn: () => fetchProject(projectId),
        enabled: projectId !== '',
    });
    const deleteMutation = useMutation({
        mutationFn: () => deleteProject(projectId),
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: projectKeys.all }),
                queryClient.invalidateQueries({ queryKey: dashboardKeys.all }),
            ]);
            navigate('/projects', { replace: true });
        },
    });

    if (isApiError(projectQuery.error) && projectQuery.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (projectQuery.isPending) return <LoadingBlock label="กำลังโหลดรายละเอียดโครงการ" />;
    if (projectQuery.isError || !projectQuery.data) {
        const notFound = isApiError(projectQuery.error) && projectQuery.error.status === 404;
        return <ErrorState action={<Link className="spa-button-secondary" to="/projects">กลับรายการโครงการ</Link>} message={notFound ? 'โครงการนี้อาจถูกลบหรือไม่มีอยู่ในระบบ' : projectQuery.error instanceof Error ? projectQuery.error.message : 'ไม่สามารถโหลดโครงการได้'} title={notFound ? 'ไม่พบโครงการ' : 'โหลดรายละเอียดไม่สำเร็จ'} />;
    }

    const project = projectQuery.data;
    const { budget, actualSpent: spent, remaining, usedPercentage: usedPercent } = projectBudgetView(project);
    const usedPercentLabel = usedPercent === null
        ? 'ไม่มีวงเงิน (มีการใช้จ่าย)'
        : `${usedPercent.toLocaleString('th-TH', { maximumFractionDigits: 1 })}%`;

    const handleDelete = async () => {
        if (!window.confirm(`ยืนยันการลบโครงการ “${project.name}” ใช่หรือไม่`)) return;
        try {
            await deleteMutation.mutateAsync();
        } catch {
            // Rendered below.
        }
    };

    return (
        <div className="space-y-7">
            <Link className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700" to="/projects"><ArrowLeftIcon className="size-4" />กลับรายการโครงการ</Link>
            <PageHeader
                actions={<>{project.abilities.update && <Link className="spa-button-secondary" to={`/projects/${project.id}/edit`}><EditIcon className="size-4" />แก้ไข</Link>}{project.abilities.delete && <button className="spa-button-secondary border-rose-200 text-rose-700 hover:bg-rose-50" disabled={deleteMutation.isPending} onClick={() => void handleDelete()} type="button"><TrashIcon className="size-4" />{deleteMutation.isPending ? 'กำลังลบ…' : 'ลบ'}</button>}</>}
                description={`${project.project_code || 'ไม่มีรหัสโครงการ'} · ${project.department?.name ?? 'ไม่ระบุฝ่าย'}`}
                eyebrow="Project detail"
                title={project.name}
            />

            {deleteMutation.isError && <ErrorState message={deleteMutation.error instanceof Error ? deleteMutation.error.message : 'ไม่สามารถลบโครงการได้'} title="ลบโครงการไม่สำเร็จ" />}

            <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label="งบประมาณโครงการ" value={formatCurrency(budget)} />
                <MetricCard label="ใช้ไปแล้ว" value={formatCurrency(spent)} />
                <MetricCard label="งบคงเหลือ" tone={remaining < 0 ? 'rose' : 'teal'} value={formatCurrency(remaining)} />
                <MetricCard label="สัดส่วนที่ใช้" tone={usedPercent === null ? 'rose' : 'slate'} value={usedPercentLabel} />
            </section>

            <section className="spa-card p-5 sm:p-7">
                <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 pb-5">
                    <div><p className="text-xs font-semibold text-slate-500">สถานะโครงการ</p><div className="mt-2"><StatusBadge code={project.execution_status?.code} label={project.execution_status?.name} /></div></div>
                    <div className="h-10 w-px bg-slate-200" />
                    <div><p className="text-xs font-semibold text-slate-500">ผลประเมิน</p><div className="mt-2"><StatusBadge code={project.evaluation_status?.code} label={project.evaluation_status?.name} /></div></div>
                    {project.approval_status && <><div className="h-10 w-px bg-slate-200" /><div><p className="text-xs font-semibold text-slate-500">สถานะอนุมัติ</p><div className="mt-2"><StatusBadge code={project.approval_status.code} label={project.approval_status.name} /></div></div></>}
                </div>
                <dl className="mt-6 grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">
                    <DetailItem label="ฝ่าย/กลุ่มงาน" value={project.department?.name} />
                    <DetailItem label="ประเภทโครงการ" value={project.category?.name} />
                    <DetailItem label="ผู้รับผิดชอบ" value={project.responsible_person ?? project.owner?.name} />
                    <DetailItem label="ปีการศึกษา" value={yearLabel(project.academic_year?.year)} />
                    <DetailItem label="ปีงบประมาณ" value={yearLabel(project.fiscal_year?.year)} />
                    <DetailItem label="แผนโรงเรียน" value={project.school_plan ? `${project.school_plan.code ?? ''} ${project.school_plan.name}`.trim() : undefined} />
                    <DetailItem label="ระยะเวลา" value={`${formatDate(project.start_date)} – ${formatDate(project.end_date)}`} />
                    <DetailItem label="แหล่งงบประมาณ" value={project.budget_source} />
                    <DetailItem label="ผู้ติดตาม" value={project.monitor_person} />
                </dl>
            </section>

            <section className="grid gap-5 xl:grid-cols-2">
                <TextSection label="วัตถุประสงค์" value={project.objective} />
                <TextSection label="ประเด็นสำคัญ" value={project.key_points} />
                <TextSection label="หลักการและเหตุผล" value={project.rationale} />
                <TextSection label="รายละเอียดโครงการ" value={project.description} />
                <TextSection label="กลุ่มเป้าหมาย" value={project.target_group} />
                <TextSection label="กลยุทธ์" value={project.strategy} />
                <TextSection label="วิธีประเมิน" value={project.evaluation_method} />
                <TextSection label="เครื่องมือประเมิน" value={project.evaluation_tools} />
            </section>
        </div>
    );
}

function MetricCard({ label, value, tone = 'slate' }: { label: string; value: string; tone?: 'slate' | 'teal' | 'rose' }) {
    const cardTone = tone === 'teal'
        ? 'border-teal-200 bg-teal-50/50'
        : tone === 'rose'
            ? 'border-rose-200 bg-rose-50/60'
            : '';
    const valueTone = tone === 'teal' ? 'text-teal-800' : tone === 'rose' ? 'text-rose-800' : 'text-slate-950';

    return <div className={`spa-card p-5 ${cardTone}`}><p className="text-xs font-semibold text-slate-500">{label}</p><p className={`mt-2 text-xl font-bold ${valueTone}`}>{value}</p></div>;
}

function DetailItem({ label, value }: { label: string; value?: string | null }) {
    return <div><dt className="text-xs font-semibold text-slate-500">{label}</dt><dd className="mt-1.5 text-sm font-medium text-slate-900">{value || '—'}</dd></div>;
}

function TextSection({ label, value }: { label: string; value?: string | null }) {
    return <article className="spa-card p-5 sm:p-6"><h2 className="text-sm font-bold text-slate-900">{label}</h2><p className="mt-3 whitespace-pre-wrap text-sm leading-7 text-slate-600">{value || '—'}</p></article>;
}
