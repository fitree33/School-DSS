import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';

import { ApiError, isApiError } from '@/api/client';
import type {
    DocumentImport,
    ImportConfirmedProject,
    ImportPreviewRevision,
    ProjectImportPreviewPayload,
} from '@/api/contracts';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState, FieldError, LoadingBlock } from '@/components/Feedback';
import { ArrowLeftIcon, CheckCircleIcon, PlusIcon, TrashIcon, WarningIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import { dashboardKeys } from '@/features/dashboard/api';
import {
    confirmDocumentImport,
    createImportPreviewRevision,
    fetchDocumentImport,
    fetchDocumentImportOptions,
    fetchImportPreviewRevisions,
    importKeys,
} from '@/features/imports/api';
import { ImportStatusBadge } from '@/features/imports/ImportStatusBadge';
import {
    ConfidenceBadge,
    ImportWarnings,
    OriginalExtractionPanel,
    RevisionHistory,
} from '@/features/imports/ImportPanels';
import {
    buildConfirmImportRequest,
    buildPreviewRevisionRequest,
    canConfirmImportPreview,
    cloneImportPreviewPayload,
    hasFieldErrors,
    isStalePreviewRevision,
    makeIdempotencyKey,
    mergeImportValidationErrors,
    previewPayloadsEqual,
    validateImportPreview,
    validateImportPreviewReferences,
} from '@/features/imports/model';
import { projectKeys } from '@/features/projects/api';

const MAX_INDICATORS = 20;

export function ImportPreviewPage() {
    const { importId = '' } = useParams();
    return <ImportPreviewEditor importId={importId} key={importId} />;
}

function ImportPreviewEditor({ importId }: { importId: string }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const { user, hasPermission } = useAuth();
    const loadedRevisionId = useRef<number | null>(null);
    const saveIdempotencyKey = useRef(makeIdempotencyKey());
    const confirmIdempotencyKey = useRef(makeIdempotencyKey());
    const [draft, setDraft] = useState<ProjectImportPreviewPayload | null>(null);
    const [baseRevision, setBaseRevision] = useState<ImportPreviewRevision | null>(null);
    const [newerRevision, setNewerRevision] = useState<ImportPreviewRevision | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [confirmationValidationErrors, setConfirmationValidationErrors] = useState<Record<string, string[]>>({});
    const [saveError, setSaveError] = useState<string | null>(null);
    const [confirmError, setConfirmError] = useState<string | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [confirmedProject, setConfirmedProject] = useState<ImportConfirmedProject | null>(null);

    const saveMutation = useMutation({
        mutationFn: ({ revision, payload, idempotencyKey }: { revision: ImportPreviewRevision; payload: ProjectImportPreviewPayload; idempotencyKey: string }) => (
            createImportPreviewRevision(importId, buildPreviewRevisionRequest(revision, payload, idempotencyKey))
        ),
        onMutate: () => queryClient.cancelQueries({ queryKey: importKeys.detail(importId), exact: true }),
    });

    const confirmMutation = useMutation({
        mutationFn: ({ revision, idempotencyKey }: { revision: ImportPreviewRevision; idempotencyKey: string }) => (
            confirmDocumentImport(importId, buildConfirmImportRequest(revision, idempotencyKey))
        ),
        onMutate: () => queryClient.cancelQueries({ queryKey: importKeys.detail(importId), exact: true }),
    });

    const importQuery = useQuery({
        queryKey: importKeys.detail(importId),
        queryFn: () => fetchDocumentImport(importId),
        enabled: importId !== '',
        refetchInterval: (query) => query.state.data?.status === 'needs_review' && !saveMutation.isPending && !confirmMutation.isPending ? 5_000 : false,
    });
    const optionsQuery = useQuery({
        queryKey: importKeys.options,
        queryFn: fetchDocumentImportOptions,
        staleTime: 30_000,
    });
    const revisionsQuery = useQuery({
        queryKey: importKeys.revisions(importId),
        queryFn: () => fetchImportPreviewRevisions(importId),
        enabled: importId !== '' && Boolean(importQuery.data?.current_preview),
    });

    useEffect(() => {
        const latest = importQuery.data?.current_preview;
        if (!latest) return;

        if (loadedRevisionId.current === null) {
            loadedRevisionId.current = latest.id;
            setBaseRevision(latest);
            setDraft(cloneImportPreviewPayload(latest.payload));
            setFieldErrors(latest.validation_errors);
            setConfirmationValidationErrors({});
            return;
        }

        if (latest.id !== loadedRevisionId.current) {
            setNewerRevision(latest);
            void queryClient.invalidateQueries({ queryKey: importKeys.revisions(importId) });
        }
    }, [importId, importQuery.data?.current_preview, queryClient]);

    const availablePlans = useMemo(
        () => optionsQuery.data?.project_options.school_plans.filter((plan) => plan.fiscal_year_id === draft?.fiscal_year_id) ?? [],
        [draft?.fiscal_year_id, optionsQuery.data?.project_options.school_plans],
    );

    if ([importQuery.error, optionsQuery.error].some((error) => isApiError(error) && error.status === 403)) return <Navigate replace to="/forbidden" />;
    if (importQuery.isPending || optionsQuery.isPending) return <LoadingBlock label="กำลังเตรียม Preview สำหรับตรวจสอบ" />;
    if (importQuery.isError || optionsQuery.isError || !importQuery.data) {
        const error = importQuery.error ?? optionsQuery.error;
        return (
            <ErrorState
                action={<Link className="spa-button-secondary" to={importId ? `/imports/${importId}` : '/imports'}>กลับหน้ารายละเอียด</Link>}
                message={isApiError(error) ? error.message : 'ไม่สามารถเตรียม Preview ได้'}
                title="โหลด Preview ไม่สำเร็จ"
            />
        );
    }

    const documentImport = importQuery.data;
    const project = confirmedProject ?? documentImport.confirmed_project;

    if (documentImport.status === 'confirmed' && project) {
        return <ConfirmationSuccess importId={documentImport.public_id} project={project} />;
    }

    if (documentImport.status !== 'needs_review') return <Navigate replace to={`/imports/${documentImport.public_id}`} />;
    if (!documentImport.current_preview || !baseRevision || !draft) return <LoadingBlock label="กำลังโหลด Preview revision ล่าสุด" />;

    const options = optionsQuery.data.project_options;
    const isReadOnly = !documentImport.abilities.review;
    const allowedDepartmentId = hasPermission('projects.edit_all') ? undefined : user?.department?.id ?? null;
    const referenceValidationErrors = validateImportPreviewReferences(draft, options, allowedDepartmentId);
    const selectedFiscalYear = options.fiscal_years.find((year) => year.id === draft.fiscal_year_id);
    const localValidationErrors = validateImportPreview(draft);
    const visibleFieldErrors = mergeImportValidationErrors(fieldErrors, localValidationErrors, referenceValidationErrors);
    const savedValidationErrors = mergeImportValidationErrors(
        baseRevision.validation_errors,
        confirmationValidationErrors,
        referenceValidationErrors,
    );
    const hasUnsavedChanges = !previewPayloadsEqual(draft, baseRevision.raw_payload ?? baseRevision.payload);
    const stale = isStalePreviewRevision(baseRevision.id, documentImport.current_preview.id) || newerRevision !== null;
    const hasVisibleValidationErrors = hasFieldErrors(visibleFieldErrors);
    const hasSavedValidationErrors = hasFieldErrors(savedValidationErrors);
    const canConfirm = canConfirmImportPreview({
        hasConfirmAbility: documentImport.abilities.confirm,
        hasReviewAbility: documentImport.abilities.review,
        draft,
        savedRevision: baseRevision,
        currentRevisionId: documentImport.current_preview.id,
        confirmationValidationErrors,
        referenceValidationErrors,
        isSaving: saveMutation.isPending,
        isConfirming: confirmMutation.isPending,
    }) && !stale;

    const resetLogicalKeys = () => {
        saveIdempotencyKey.current = makeIdempotencyKey();
        confirmIdempotencyKey.current = makeIdempotencyKey();
    };

    const clearFieldErrors = (field: string) => {
        setFieldErrors((current) => Object.fromEntries(
            Object.entries(current).filter(([key]) => key !== field && !key.startsWith(`${field}.`)),
        ));
    };

    const updateField = <Key extends keyof ProjectImportPreviewPayload,>(field: Key, value: ProjectImportPreviewPayload[Key]) => {
        setDraft((current) => current ? { ...current, [field]: value } : current);
        clearFieldErrors(String(field));
        setSaveError(null);
        setConfirmError(null);
        resetLogicalKeys();
    };

    const updateIndicator = (index: number, field: 'name' | 'target_value' | 'unit', value: string) => {
        setDraft((current) => {
            if (!current) return current;
            const indicators = current.indicators.map((indicator, indicatorIndex) => indicatorIndex === index
                ? { ...indicator, [field]: field === 'name' ? value : value || null }
                : indicator);
            return { ...current, indicators };
        });
        clearFieldErrors(`indicators.${index}`);
        setSaveError(null);
        setConfirmError(null);
        resetLogicalKeys();
    };

    const addIndicator = () => {
        if (draft.indicators.length >= MAX_INDICATORS) return;
        updateField('indicators', [...draft.indicators, { name: '', target_value: null, unit: null }]);
    };

    const removeIndicator = (index: number) => {
        updateField('indicators', draft.indicators.filter((_, indicatorIndex) => indicatorIndex !== index));
        setFieldErrors({});
    };

    const acceptSavedRevision = (revision: ImportPreviewRevision) => {
        loadedRevisionId.current = revision.id;
        setBaseRevision(revision);
        setDraft(cloneImportPreviewPayload(revision.payload));
        setFieldErrors(revision.validation_errors);
        setConfirmationValidationErrors({});
        setNewerRevision(null);
        setSaveError(null);
        setConfirmError(null);
        resetLogicalKeys();
        queryClient.setQueryData<DocumentImport>(importKeys.detail(importId), (current) => current ? {
            ...current,
            current_preview: revision,
            updated_at: revision.created_at,
        } : current);
    };

    const refreshAfterConflict = async () => {
        const [refreshedImport] = await Promise.all([
            importQuery.refetch(),
            revisionsQuery.refetch(),
            optionsQuery.refetch(),
        ]);
        const latest = refreshedImport.data?.current_preview ?? null;
        if (latest && latest.id !== loadedRevisionId.current) setNewerRevision(latest);
    };

    const handleSave = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (isReadOnly || saveMutation.isPending || confirmMutation.isPending) return;

        const validationErrors = validateImportPreview(draft);
        setFieldErrors((current) => mergeImportValidationErrors(current, validationErrors));
        setSaveError(null);
        setConfirmError(null);

        if (stale) {
            setSaveError('มี Preview revision ใหม่กว่า กรุณาเลือกวิธีจัดการความเปลี่ยนแปลงก่อนบันทึก');
            return;
        }

        try {
            const revision = await saveMutation.mutateAsync({
                revision: baseRevision,
                payload: cloneImportPreviewPayload(draft),
                idempotencyKey: saveIdempotencyKey.current,
            });
            acceptSavedRevision(revision);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: importKeys.revisions(importId) }),
                queryClient.invalidateQueries({ queryKey: importKeys.lists() }),
            ]);
        } catch (unknownError) {
            if (unknownError instanceof ApiError) {
                if (unknownError.status === 403) {
                    navigate('/forbidden', { replace: true });
                    return;
                }
                setFieldErrors((current) => mergeImportValidationErrors(current, unknownError.errors));
                setSaveError(unknownError.status === 409
                    ? 'Preview นี้ไม่ใช่ revision ล่าสุด ระบบเก็บข้อมูลในฟอร์มไว้แล้ว กรุณาโหลดฐานล่าสุดก่อนลองใหม่'
                    : unknownError.message);
                if (unknownError.status === 409) await refreshAfterConflict();
            } else {
                setSaveError('ไม่สามารถบันทึก Preview revision ได้ กรุณาลองใหม่ด้วยข้อมูลเดิม');
            }
        }
    };

    const handleConfirm = async () => {
        if (!canConfirm || confirmMutation.isPending) return;
        setConfirmError(null);

        try {
            const confirmation = await confirmMutation.mutateAsync({
                revision: baseRevision,
                idempotencyKey: confirmIdempotencyKey.current,
            });
            setConfirmedProject(confirmation.project);
            setConfirmOpen(false);
            queryClient.setQueryData(importKeys.detail(importId), confirmation.document_import);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: importKeys.lists() }),
                queryClient.invalidateQueries({ queryKey: projectKeys.all }),
                queryClient.invalidateQueries({ queryKey: dashboardKeys.all }),
            ]);
        } catch (unknownError) {
            if (unknownError instanceof ApiError) {
                if (unknownError.status === 403) {
                    navigate('/forbidden', { replace: true });
                    return;
                }
                setFieldErrors((current) => mergeImportValidationErrors(current, unknownError.errors));
                if (unknownError.status === 422) {
                    setConfirmationValidationErrors(unknownError.errors);
                    await optionsQuery.refetch();
                }
                setConfirmError(unknownError.status === 409
                    ? 'มีการยืนยันหรือแก้ไข Preview จากคำขออื่น กรุณาตรวจสถานะและ revision ล่าสุดก่อนยืนยันอีกครั้ง'
                    : unknownError.message);
                if (unknownError.status === 409) {
                    setConfirmOpen(false);
                    await refreshAfterConflict();
                }
            } else {
                setConfirmError('ยืนยันการนำเข้าไม่สำเร็จ กรุณาลองใหม่ด้วยคำขอเดิม');
            }
        }
    };

    const rebaseDraft = () => {
        if (!newerRevision) return;
        loadedRevisionId.current = newerRevision.id;
        setBaseRevision(newerRevision);
        setFieldErrors(mergeImportValidationErrors(newerRevision.validation_errors, validateImportPreview(draft)));
        setConfirmationValidationErrors({});
        setNewerRevision(null);
        setSaveError(null);
        resetLogicalKeys();
    };

    const loadLatest = () => {
        if (!newerRevision) return;
        acceptSavedRevision(newerRevision);
    };

    const confidence = baseRevision.confidence;

    return (
        <div className="space-y-7">
            <Link className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700" to={`/imports/${documentImport.public_id}`}><ArrowLeftIcon className="size-4" />กลับหน้ารายละเอียด</Link>

            <PageHeader
                description="ตรวจค่าที่ AI อ่านได้ แก้ไขเป็น preview revision ใหม่ แล้วจึงยืนยันเพื่อสร้างโครงการเพียงครั้งเดียว"
                eyebrow={`Preview revision ${baseRevision.revision_no}`}
                title="ตรวจสอบข้อมูลก่อนสร้างโครงการ"
            />

            <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4">
                <ImportStatusBadge status={documentImport.status} />
                <p className="text-sm text-slate-600">กำลังแก้จาก Revision {baseRevision.revision_no}</p>
                {hasUnsavedChanges && <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-800">มีข้อมูลยังไม่บันทึก</span>}
                {!hasUnsavedChanges && <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">ตรงกับฉบับที่บันทึก</span>}
            </div>

            {newerRevision && (
                <section className="rounded-2xl border border-amber-300 bg-amber-50 p-5" role="alert">
                    <div className="flex items-start gap-3">
                        <WarningIcon className="mt-0.5 size-5 shrink-0 text-amber-700" />
                        <div className="flex-1">
                            <h2 className="font-bold text-amber-950">มี Preview revision ใหม่กว่า</h2>
                            <p className="mt-1 text-sm leading-6 text-amber-900">Revision {newerRevision.revision_no} ถูกสร้างหลังจากเปิดฟอร์มนี้ ระบบยังไม่ทับข้อมูลที่กำลังแก้</p>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <button className="spa-button-primary" disabled={saveMutation.isPending || confirmMutation.isPending} onClick={rebaseDraft} type="button">ใช้ Revision {newerRevision.revision_no} เป็นฐานและเก็บข้อมูลในฟอร์ม</button>
                                <button className="spa-button-secondary" disabled={saveMutation.isPending || confirmMutation.isPending} onClick={loadLatest} type="button">ทิ้งข้อมูลในฟอร์มและโหลดฉบับล่าสุด</button>
                            </div>
                        </div>
                    </div>
                </section>
            )}

            {saveError && <ErrorState message={saveError} title="บันทึก Preview ไม่สำเร็จ" />}
            {confirmError && <ErrorState message={confirmError} title="ยืนยันการนำเข้าไม่สำเร็จ" />}
            <ImportWarnings warnings={baseRevision.warnings} />

            {selectedFiscalYear?.is_locked && (
                <section className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-950" role="alert">
                    <p className="font-bold">ปีงบประมาณ {selectedFiscalYear.year} ปิดแล้ว</p>
                    <p className="mt-1 leading-6">ยังตรวจสอบและบันทึกฉบับร่างได้ กรุณาเลือกปีงบประมาณที่เปิดใช้งานและตรวจแผนโรงเรียนให้ตรงกันก่อนยืนยันสร้างโครงการ</p>
                </section>
            )}

            {isReadOnly && (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900" role="status">
                    <p className="font-bold">เปิดดูแบบอ่านอย่างเดียว</p>
                    <p className="mt-1 leading-6">บัญชีนี้ดู Preview ได้ แต่ไม่มีสิทธิ์สร้าง revision หรือยืนยันโครงการ</p>
                </div>
            )}

            <form className="space-y-5" noValidate onSubmit={handleSave}>
                <fieldset className="contents" disabled={isReadOnly || saveMutation.isPending || confirmMutation.isPending}>
                    <FormSection description="ตรวจข้อมูลอ้างอิงกับเอกสารต้นฉบับ โดยคะแนน AI เป็นเพียงตัวช่วยตัดสินใจ" title="ข้อมูลพื้นฐาน">
                        <div className="grid gap-5 md:grid-cols-2">
                            <PreviewTextField confidence={confidence.name} errors={visibleFieldErrors.name} label="ชื่อโครงการ" onChange={(value) => updateField('name', value)} required value={draft.name} />
                            <PreviewSelectField confidence={confidence.department_id} errors={visibleFieldErrors.department_id} label="ฝ่าย/กลุ่มงาน" onChange={(value) => updateField('department_id', value ? Number(value) : null)} required value={draft.department_id?.toString() ?? ''}>
                                <option value="">เลือกฝ่าย/กลุ่มงาน</option>
                                <UnavailableOption choices={options.departments} selectedId={draft.department_id} />
                                {options.departments.map((item) => <option disabled={allowedDepartmentId !== undefined && item.id !== allowedDepartmentId} key={item.id} value={item.id}>{item.name}</option>)}
                            </PreviewSelectField>
                            <PreviewSelectField confidence={confidence.project_category_id} errors={visibleFieldErrors.project_category_id} label="ประเภทโครงการ" onChange={(value) => updateField('project_category_id', value ? Number(value) : null)} required value={draft.project_category_id?.toString() ?? ''}>
                                <option value="">เลือกประเภทโครงการ</option>
                                <UnavailableOption choices={options.project_categories} selectedId={draft.project_category_id} />
                                {options.project_categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                            </PreviewSelectField>
                            <PreviewSelectField confidence={confidence.academic_year_id} errors={visibleFieldErrors.academic_year_id} label="ปีการศึกษา" onChange={(value) => updateField('academic_year_id', value ? Number(value) : null)} required value={draft.academic_year_id?.toString() ?? ''}>
                                <option value="">เลือกปีการศึกษา</option>
                                <UnavailableOption choices={options.academic_years} selectedId={draft.academic_year_id} />
                                {options.academic_years.map((item) => <option key={item.id} value={item.id}>{item.year}{item.is_locked ? ' (ปิดแล้ว)' : ''}</option>)}
                            </PreviewSelectField>
                            <PreviewSelectField confidence={confidence.fiscal_year_id} errors={visibleFieldErrors.fiscal_year_id} label="ปีงบประมาณ" onChange={(value) => {
                                const fiscalYearId = value ? Number(value) : null;
                                updateField('fiscal_year_id', fiscalYearId);
                                if (draft.school_plan_id && !options.school_plans.some((plan) => plan.id === draft.school_plan_id && plan.fiscal_year_id === fiscalYearId)) updateField('school_plan_id', null);
                            }} required value={draft.fiscal_year_id?.toString() ?? ''}>
                                <option value="">เลือกปีงบประมาณ</option>
                                <UnavailableOption choices={options.fiscal_years} selectedId={draft.fiscal_year_id} />
                                {options.fiscal_years.map((item) => <option disabled={item.is_locked} key={item.id} value={item.id}>{item.year}{item.is_locked ? ' (ปิดแล้ว)' : ''}</option>)}
                            </PreviewSelectField>
                            <PreviewSelectField confidence={confidence.school_plan_id} errors={visibleFieldErrors.school_plan_id} label="แผนโรงเรียน" onChange={(value) => updateField('school_plan_id', value ? Number(value) : null)} value={draft.school_plan_id?.toString() ?? ''}>
                                <option value="">ไม่ระบุ</option>
                                <UnavailableOption choices={availablePlans} selectedId={draft.school_plan_id} />
                                {availablePlans.map((item) => <option key={item.id} value={item.id}>{item.code ? `${item.code} · ` : ''}{item.name}</option>)}
                            </PreviewSelectField>
                            <PreviewTextField confidence={confidence.responsible_person} errors={visibleFieldErrors.responsible_person} label="ผู้รับผิดชอบ" onChange={(value) => updateField('responsible_person', value || null)} value={draft.responsible_person ?? ''} />
                            <PreviewTextField confidence={confidence.monitor_person} errors={visibleFieldErrors.monitor_person} label="ผู้ติดตามโครงการ" onChange={(value) => updateField('monitor_person', value || null)} value={draft.monitor_person ?? ''} />
                        </div>
                    </FormSection>

                    <FormSection description="ข้อมูลนี้สร้างเฉพาะโครงการและตัวชี้วัด ไม่สร้าง Activity หรือ Sub-Activity" title="รายละเอียดโครงการ">
                        <div className="space-y-5">
                            <PreviewTextareaField confidence={confidence.objective} errors={visibleFieldErrors.objective} label="วัตถุประสงค์" onChange={(value) => updateField('objective', value)} required rows={5} value={draft.objective} />
                            <PreviewTextareaField confidence={confidence.key_points} errors={visibleFieldErrors.key_points} label="ประเด็นสำคัญ" onChange={(value) => updateField('key_points', value || null)} rows={4} value={draft.key_points ?? ''} />
                        </div>
                    </FormSection>

                    <FormSection description="งบประมาณนี้เป็นวงเงินระดับโครงการ ไม่แก้ไข School Budget หรือ Department Budget" title="งบประมาณและระยะเวลา">
                        <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                            <PreviewTextField confidence={confidence.budget} errors={visibleFieldErrors.budget} inputMode="decimal" label="งบประมาณ (บาท)" min="0" onChange={(value) => updateField('budget', value)} required step="0.01" type="number" value={draft.budget} />
                            <PreviewTextField confidence={confidence.start_date} errors={visibleFieldErrors.start_date} label="วันที่เริ่ม" onChange={(value) => updateField('start_date', value || null)} type="date" value={draft.start_date ?? ''} />
                            <PreviewTextField confidence={confidence.end_date} errors={visibleFieldErrors.end_date} label="วันที่สิ้นสุด" onChange={(value) => updateField('end_date', value || null)} type="date" value={draft.end_date ?? ''} />
                        </div>
                    </FormSection>

                    <FormSection description="เก็บข้อความประกอบโครงการเท่านั้น ไม่สร้างหรือ Finalize รายการประเมิน" title="แนวทางการประเมิน">
                        <div className="grid gap-5 lg:grid-cols-2">
                            <PreviewTextareaField confidence={confidence.evaluation_method} errors={visibleFieldErrors.evaluation_method} label="วิธีประเมิน" onChange={(value) => updateField('evaluation_method', value || null)} rows={4} value={draft.evaluation_method ?? ''} />
                            <PreviewTextareaField confidence={confidence.evaluation_tools} errors={visibleFieldErrors.evaluation_tools} label="เครื่องมือประเมิน" onChange={(value) => updateField('evaluation_tools', value || null)} rows={4} value={draft.evaluation_tools ?? ''} />
                        </div>
                    </FormSection>

                    <FormSection description={`เพิ่มได้สูงสุด ${MAX_INDICATORS} ตัว ระบบจะสร้าง KPI เมื่อยืนยันโครงการเท่านั้น`} title="ตัวชี้วัด (KPI)">
                        <div className="space-y-4">
                            <FieldError errors={visibleFieldErrors.indicators} />
                            {draft.indicators.map((indicator, index) => (
                                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4" key={index}>
                                    <div className="mb-4 flex items-center justify-between gap-3">
                                        <p className="text-sm font-bold text-slate-800">ตัวชี้วัด {index + 1}</p>
                                        <button aria-label={`ลบตัวชี้วัด ${index + 1}`} className="rounded-lg p-2 text-rose-700 hover:bg-rose-100" onClick={() => removeIndicator(index)} type="button"><TrashIcon className="size-4" /></button>
                                    </div>
                                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(140px,0.3fr)_minmax(140px,0.3fr)]">
                                        <PreviewTextField confidence={confidence[`indicators.${index}.name`]} errors={visibleFieldErrors[`indicators.${index}.name`]} label="ชื่อตัวชี้วัด" onChange={(value) => updateIndicator(index, 'name', value)} required value={indicator.name} />
                                        <PreviewTextField confidence={confidence[`indicators.${index}.target_value`]} errors={visibleFieldErrors[`indicators.${index}.target_value`]} inputMode="decimal" label="ค่าเป้าหมาย" min="0" onChange={(value) => updateIndicator(index, 'target_value', value)} step="0.01" type="number" value={indicator.target_value ?? ''} />
                                        <PreviewTextField confidence={confidence[`indicators.${index}.unit`]} errors={visibleFieldErrors[`indicators.${index}.unit`]} label="หน่วย" onChange={(value) => updateIndicator(index, 'unit', value)} value={indicator.unit ?? ''} />
                                    </div>
                                </div>
                            ))}

                            {draft.indicators.length === 0 && <p className="rounded-xl bg-slate-50 px-4 py-5 text-center text-sm text-slate-500">ยังไม่มีตัวชี้วัด สามารถบันทึกโครงการโดยไม่มี KPI ได้</p>}
                            <button className="spa-button-secondary" disabled={draft.indicators.length >= MAX_INDICATORS} onClick={addIndicator} type="button"><PlusIcon className="size-4" />เพิ่มตัวชี้วัด</button>
                        </div>
                    </FormSection>
                </fieldset>

                <div className="sticky bottom-4 z-20 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-xl shadow-slate-900/10 backdrop-blur">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs leading-5 text-slate-500">
                            {stale
                                ? 'โหลด revision ล่าสุดก่อนยืนยัน'
                                : hasUnsavedChanges
                                    ? hasVisibleValidationErrors
                                        ? 'บันทึก revision ได้ แต่ต้องแก้ข้อมูลที่ระบุก่อนยืนยัน'
                                        : 'บันทึก revision ใหม่ก่อนยืนยัน'
                                    : hasSavedValidationErrors
                                        ? 'Revision นี้บันทึกแล้ว แต่ยังมีข้อมูลที่ต้องแก้ไขก่อนยืนยัน'
                                        : 'ข้อมูลพร้อมสำหรับการยืนยัน'}
                        </p>
                        <div className="flex flex-col-reverse gap-2 sm:flex-row">
                            <Link className="spa-button-secondary" to={`/imports/${documentImport.public_id}`}>กลับหน้ารายละเอียด</Link>
                            {!isReadOnly && <button className="spa-button-secondary" disabled={(!hasUnsavedChanges && !hasSavedValidationErrors) || stale || saveMutation.isPending || confirmMutation.isPending} type="submit">{saveMutation.isPending ? 'กำลังบันทึก…' : 'บันทึกเป็น Revision ใหม่'}</button>}
                            {!isReadOnly && documentImport.abilities.confirm && <button className="spa-button-primary" disabled={!canConfirm} onClick={() => { setConfirmError(null); setConfirmOpen(true); }} type="button"><CheckCircleIcon className="size-4" />ยืนยันและสร้างโครงการ</button>}
                        </div>
                    </div>
                </div>
            </form>

            <OriginalExtractionPanel snapshot={documentImport.original_extraction} />

            {revisionsQuery.isError ? (
                <ErrorState action={<button className="spa-button-secondary" onClick={() => void revisionsQuery.refetch()} type="button">ลองใหม่</button>} message={isApiError(revisionsQuery.error) ? revisionsQuery.error.message : 'ไม่สามารถโหลดประวัติ revision ได้'} title="โหลดประวัติ Preview ไม่สำเร็จ" />
            ) : revisionsQuery.isPending ? (
                <LoadingBlock label="กำลังโหลดประวัติ Preview revisions" />
            ) : (
                <RevisionHistory currentRevisionId={documentImport.current_preview.id} revisions={revisionsQuery.data ?? []} />
            )}

            {confirmOpen && (
                <ImportConfirmDialog isPending={confirmMutation.isPending} onClose={() => setConfirmOpen(false)}>
                        <div className="mx-auto grid size-12 place-items-center rounded-full bg-amber-100 text-amber-800"><WarningIcon className="size-6" /></div>
                        <h2 className="mt-4 text-center text-lg font-bold text-slate-950" id="confirm-import-title">ยืนยันสร้างโครงการจาก Revision {baseRevision.revision_no}?</h2>
                        <p className="mt-3 text-center text-sm leading-6 text-slate-600">ระบบจะสร้างโครงการและตัวชี้วัดจากข้อมูลที่บันทึกแล้ว พร้อมเก็บเอกสารต้นฉบับและประวัติการตรวจสอบไว้เป็นหลักฐาน</p>
                        {confirmError && <p className="mt-4 text-sm text-rose-700" role="alert">{confirmError}</p>}
                        {!canConfirm && !confirmMutation.isPending && <p className="mt-4 text-sm text-amber-800" role="alert">ข้อมูลหรือสิทธิ์ในการยืนยันเปลี่ยนแปลง กรุณากลับไปตรวจสอบก่อนยืนยัน</p>}
                        <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button autoFocus className="spa-button-secondary" disabled={confirmMutation.isPending} onClick={() => setConfirmOpen(false)} type="button">กลับไปตรวจสอบ</button>
                            <button className="spa-button-primary" disabled={!canConfirm} onClick={() => void handleConfirm()} type="button">{confirmMutation.isPending ? 'กำลังยืนยัน…' : 'ยืนยันและสร้างโครงการ'}</button>
                        </div>
                </ImportConfirmDialog>
            )}
        </div>
    );
}

function ImportConfirmDialog({ children, isPending, onClose }: {
    children: React.ReactNode;
    isPending: boolean;
    onClose: () => void;
}) {
    const dialogRef = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const dialog = dialogRef.current;
        dialog?.showModal();
        return () => dialog?.close();
    }, []);

    return (
        <dialog
            aria-labelledby="confirm-import-title"
            aria-modal="true"
            className="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl backdrop:bg-slate-950/55"
            onCancel={(event) => {
                event.preventDefault();
                if (!isPending) onClose();
            }}
            ref={dialogRef}
        >
            {children}
        </dialog>
    );
}

function UnavailableOption({ choices, selectedId }: { choices: { id: number }[]; selectedId: number | null }) {
    if (selectedId === null || choices.some((choice) => choice.id === selectedId)) return null;
    return <option disabled value={selectedId}>ไม่พบรายการที่เลือก (รหัส {selectedId})</option>;
}

function ConfirmationSuccess({ importId, project }: { importId: string; project: ImportConfirmedProject }) {
    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <section className="spa-card p-7 text-center sm:p-10" role="status">
                <div className="mx-auto grid size-16 place-items-center rounded-full bg-emerald-100 text-emerald-700"><CheckCircleIcon className="size-9" /></div>
                <p className="mt-5 text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Import confirmed</p>
                <h1 className="mt-2 text-2xl font-bold text-slate-950">สร้างโครงการเรียบร้อยแล้ว</h1>
                <p className="mt-3 text-sm leading-6 text-slate-600">{project.name}{project.project_code ? ` · ${project.project_code}` : ''}</p>
                <div className="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                    <Link className="spa-button-primary" to={`/projects/${project.id}`}>เปิดโครงการ</Link>
                    <Link className="spa-button-secondary" to={`/imports/${importId}`}>ดูหลักฐานการนำเข้า</Link>
                </div>
            </section>
        </div>
    );
}

function FormSection({ title, description, children }: { title: string; description: string; children: React.ReactNode }) {
    return (
        <section className="spa-card p-5 sm:p-7">
            <div className="mb-6 border-b border-slate-100 pb-4">
                <h2 className="text-lg font-bold text-slate-900">{title}</h2>
                <p className="mt-1 text-sm leading-6 text-slate-500">{description}</p>
            </div>
            {children}
        </section>
    );
}

type PreviewInputProps = {
    label: string;
    value: string;
    onChange: (value: string) => void;
    errors?: string[];
    confidence?: number | null;
} & Omit<React.InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'className'>;

function PreviewTextField({ label, value, onChange, errors, confidence, ...props }: PreviewInputProps) {
    return (
        <label>
            <span className="spa-label flex flex-wrap items-center gap-2">{label}{props.required && <span className="text-rose-600">*</span>}<ConfidenceBadge value={confidence} /></span>
            <input {...props} className="spa-input" onChange={(event) => onChange(event.target.value)} value={value} />
            <FieldError errors={errors} />
        </label>
    );
}

type PreviewTextareaProps = {
    label: string;
    value: string;
    onChange: (value: string) => void;
    errors?: string[];
    confidence?: number | null;
} & Omit<React.TextareaHTMLAttributes<HTMLTextAreaElement>, 'value' | 'onChange' | 'className'>;

function PreviewTextareaField({ label, value, onChange, errors, confidence, ...props }: PreviewTextareaProps) {
    return (
        <label>
            <span className="spa-label flex flex-wrap items-center gap-2">{label}{props.required && <span className="text-rose-600">*</span>}<ConfidenceBadge value={confidence} /></span>
            <textarea {...props} className="spa-input min-h-24 resize-y" onChange={(event) => onChange(event.target.value)} value={value} />
            <FieldError errors={errors} />
        </label>
    );
}

type PreviewSelectProps = {
    label: string;
    value: string;
    onChange: (value: string) => void;
    errors?: string[];
    confidence?: number | null;
    children: React.ReactNode;
} & Omit<React.SelectHTMLAttributes<HTMLSelectElement>, 'value' | 'onChange' | 'className'>;

function PreviewSelectField({ label, value, onChange, errors, confidence, children, ...props }: PreviewSelectProps) {
    return (
        <label>
            <span className="spa-label flex flex-wrap items-center gap-2">{label}{props.required && <span className="text-rose-600">*</span>}<ConfidenceBadge value={confidence} /></span>
            <select {...props} className="spa-input" onChange={(event) => onChange(event.target.value)} value={value}>{children}</select>
            <FieldError errors={errors} />
        </label>
    );
}
