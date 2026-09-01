import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';

import { ApiError, isApiError } from '@/api/client';
import type { ProjectEvaluationPayload } from '@/api/contracts';
import { ErrorState, LoadingBlock } from '@/components/Feedback';
import { ArrowLeftIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import { dashboardKeys } from '@/features/dashboard/api';
import { evaluationKeys, fetchProjectEvaluation, updateProjectEvaluation } from '@/features/evaluations/api';
import { EvaluationForm } from '@/features/evaluations/EvaluationForm';
import { projectKeys } from '@/features/projects/api';

export function EvaluationEditPage() {
    const { evaluationId = '' } = useParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [submitError, setSubmitError] = useState<string | null>(null);
    const evaluationQuery = useQuery({ queryKey: evaluationKeys.detail(evaluationId), queryFn: () => fetchProjectEvaluation(evaluationId), enabled: evaluationId !== '' });
    const mutation = useMutation({
        mutationFn: (payload: Omit<ProjectEvaluationPayload, 'evaluation_framework_id'>) => updateProjectEvaluation(evaluationId, payload),
        onSuccess: async (evaluation) => {
            queryClient.setQueryData(evaluationKeys.detail(evaluation.id), evaluation);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: evaluationKeys.all }),
                queryClient.invalidateQueries({ queryKey: projectKeys.detail(evaluation.project.id) }),
                queryClient.invalidateQueries({ queryKey: dashboardKeys.all }),
            ]);
            navigate(`/evaluations/${evaluation.id}`, { replace: true });
        },
    });

    if (isApiError(evaluationQuery.error) && evaluationQuery.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (evaluationQuery.isPending) return <LoadingBlock label="กำลังโหลดแบบประเมิน" />;
    if (evaluationQuery.isError || !evaluationQuery.data) return <ErrorState action={<Link className="spa-button-secondary" to="/evaluations">กลับรายการประเมิน</Link>} message={isApiError(evaluationQuery.error) ? evaluationQuery.error.message : 'ไม่สามารถโหลดแบบประเมินได้'} title="โหลดแบบประเมินไม่สำเร็จ" />;

    const evaluation = evaluationQuery.data;
    if (!evaluation.abilities.update) return <Navigate replace to={`/evaluations/${evaluation.id}`} />;

    const handleUpdate = async (payload: ProjectEvaluationPayload) => {
        setFieldErrors({});
        setSubmitError(null);
        try {
            const updatePayload = {
                evaluated_at: payload.evaluated_at,
                comment: payload.comment,
                scores: payload.scores,
            };
            await mutation.mutateAsync(updatePayload);
        } catch (error) {
            if (error instanceof ApiError) {
                setSubmitError(error.message);
                setFieldErrors(error.errors);
            } else setSubmitError('ไม่สามารถแก้ไขคะแนนได้ กรุณาลองใหม่');
        }
    };

    return (
        <div className="space-y-7">
            <Link className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700" to={`/evaluations/${evaluation.id}`}><ArrowLeftIcon className="size-4" />ยกเลิกและกลับ</Link>
            <PageHeader description={`${evaluation.framework.name} · รอบที่ ${evaluation.round}`} eyebrow="Edit evaluation" title={`แก้ไขคะแนน: ${evaluation.project.name}`} />
            {submitError && <ErrorState message={submitError} title="แก้ไขคะแนนไม่สำเร็จ" />}
            <EvaluationForm fieldErrors={fieldErrors} framework={evaluation.framework} initialEvaluation={evaluation} isSubmitting={mutation.isPending} onSubmit={handleUpdate} />
        </div>
    );
}
