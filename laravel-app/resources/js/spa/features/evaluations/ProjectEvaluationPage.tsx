import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';

import { ApiError, isApiError } from '@/api/client';
import type { ProjectEvaluationPayload } from '@/api/contracts';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, LoadingBlock } from '@/components/Feedback';
import { ArrowLeftIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import { dashboardKeys } from '@/features/dashboard/api';
import {
    createProjectEvaluation,
    evaluationKeys,
    fetchEvaluationOptions,
    fetchProjectEvaluations,
} from '@/features/evaluations/api';
import { EvaluationForm } from '@/features/evaluations/EvaluationForm';
import { EvaluationHistory } from '@/features/evaluations/EvaluationHistory';
import { fetchProject, projectKeys } from '@/features/projects/api';
import { yearLabel } from '@/features/projects/format';

export function ProjectEvaluationPage() {
    const { projectId = '' } = useParams();
    const { hasPermission } = useAuth();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [selectedFrameworkId, setSelectedFrameworkId] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [submitError, setSubmitError] = useState<string | null>(null);

    const projectQuery = useQuery({ queryKey: projectKeys.detail(projectId), queryFn: () => fetchProject(projectId), enabled: projectId !== '' });
    const optionsQuery = useQuery({ queryKey: evaluationKeys.options, queryFn: fetchEvaluationOptions, staleTime: 5 * 60_000 });
    const historyQuery = useQuery({ queryKey: evaluationKeys.projectHistory(projectId), queryFn: () => fetchProjectEvaluations(projectId), enabled: projectId !== '' });

    const applicableFrameworks = useMemo(() => {
        const fiscalYearId = projectQuery.data?.fiscal_year?.id;
        return (optionsQuery.data?.frameworks ?? []).filter((framework) =>
            framework.is_active && (!framework.fiscal_year || framework.fiscal_year.id === fiscalYearId),
        );
    }, [optionsQuery.data?.frameworks, projectQuery.data?.fiscal_year?.id]);

    useEffect(() => {
        if (selectedFrameworkId && applicableFrameworks.some((framework) => String(framework.id) === selectedFrameworkId)) return;
        setSelectedFrameworkId(applicableFrameworks.length === 1 ? String(applicableFrameworks[0].id) : '');
    }, [applicableFrameworks, selectedFrameworkId]);

    const createMutation = useMutation({
        mutationFn: (payload: ProjectEvaluationPayload) => createProjectEvaluation(projectId, payload),
        onSuccess: async (evaluation) => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: evaluationKeys.all }),
                queryClient.invalidateQueries({ queryKey: projectKeys.detail(projectId) }),
                queryClient.invalidateQueries({ queryKey: dashboardKeys.all }),
            ]);
            navigate(`/evaluations/${evaluation.id}`, { replace: true });
        },
    });

    if (isApiError(projectQuery.error) && projectQuery.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (projectQuery.isPending || optionsQuery.isPending || historyQuery.isPending) return <LoadingBlock label="กำลังเตรียมพื้นที่ประเมินโครงการ" />;
    if (projectQuery.isError || optionsQuery.isError || historyQuery.isError || !projectQuery.data) {
        const error = projectQuery.error ?? optionsQuery.error ?? historyQuery.error;
        return <ErrorState action={<Link className="spa-button-secondary" to="/evaluations">กลับรายการประเมิน</Link>} message={isApiError(error) ? error.message : 'ไม่สามารถโหลดข้อมูลสำหรับการประเมินได้'} title="เปิดพื้นที่ประเมินไม่สำเร็จ" />;
    }

    const project = projectQuery.data;
    const isLocked = project.fiscal_year?.is_locked === true;
    const canCreate = (project.abilities.create_evaluation ?? hasPermission('evaluations.create')) && !isLocked;
    const selectedFramework = applicableFrameworks.find((framework) => String(framework.id) === selectedFrameworkId);

    const handleCreate = async (payload: ProjectEvaluationPayload) => {
        setFieldErrors({});
        setSubmitError(null);
        try {
            await createMutation.mutateAsync(payload);
        } catch (error) {
            if (error instanceof ApiError) {
                setSubmitError(error.message);
                setFieldErrors(error.errors);
            } else setSubmitError('ไม่สามารถบันทึกการประเมินได้ กรุณาลองใหม่');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    return (
        <div className="space-y-7">
            <Link className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700" to="/evaluations"><ArrowLeftIcon className="size-4" />กลับรายการประเมิน</Link>
            <PageHeader description={`${project.project_code || 'ไม่มีรหัส'} · ${project.department?.name ?? 'ไม่ระบุฝ่าย'} · ปีงบประมาณ ${yearLabel(project.fiscal_year?.year)}`} eyebrow="Evaluation workspace" title={project.name} />

            {isLocked && <ReadOnlyNotice year={yearLabel(project.fiscal_year?.year)} />}
            {submitError && <ErrorState message={submitError} title="บันทึกการประเมินไม่สำเร็จ" />}

            {canCreate && (
                <section className="space-y-5" aria-labelledby="new-evaluation-heading">
                    <div><h2 className="text-xl font-bold text-slate-950" id="new-evaluation-heading">ประเมินโครงการรอบใหม่</h2><p className="mt-1 text-sm text-slate-500">การบันทึกครั้งนี้สร้างประวัติรอบใหม่ และไม่เปลี่ยนผลประเมินของโครงการ</p></div>
                    {!applicableFrameworks.length ? (
                        <EmptyState action={optionsQuery.data.can.manage_frameworks ? <Link className="spa-button-primary" to="/evaluations/frameworks/new">สร้างชุดเกณฑ์</Link> : undefined} description="ต้องมีชุดเกณฑ์ active ที่ใช้ได้กับปีงบประมาณของโครงการก่อน" title="ยังไม่มีชุดเกณฑ์ที่พร้อมใช้งาน" />
                    ) : (
                        <>
                            <label className="spa-card block p-5"><span className="spa-label">เลือกชุดเกณฑ์ <span className="text-rose-600">*</span></span><select className="spa-input sm:max-w-xl" onChange={(event) => setSelectedFrameworkId(event.target.value)} value={selectedFrameworkId}><option value="">เลือกชุดเกณฑ์</option>{applicableFrameworks.map((framework) => <option key={framework.id} value={framework.id}>{framework.name} · {framework.code} v{framework.version}</option>)}</select></label>
                            {selectedFramework && <EvaluationForm fieldErrors={fieldErrors} framework={selectedFramework} isSubmitting={createMutation.isPending} onSubmit={handleCreate} />}
                        </>
                    )}
                </section>
            )}

            {!canCreate && !isLocked && <div className="rounded-2xl border border-slate-200 bg-slate-50 px-5 py-4 text-sm text-slate-700">บัญชีนี้เปิดดูประวัติได้ แต่ไม่มีสิทธิ์สร้างการประเมินรอบใหม่</div>}
            <EvaluationHistory evaluations={historyQuery.data} />
        </div>
    );
}

function ReadOnlyNotice({ year }: { year: string }) {
    return <div className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900" role="status"><p className="font-bold">ปีงบประมาณ {year} ถูกล็อกแล้ว</p><p className="mt-1 leading-6">เปิดดูประวัติได้เท่านั้น ไม่สามารถสร้าง แก้ไข หรือ Finalize การประเมิน</p></div>;
}
