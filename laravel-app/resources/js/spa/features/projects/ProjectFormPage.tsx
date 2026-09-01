import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';

import { ApiError, isApiError } from '@/api/client';
import type { Project, ProjectPayload } from '@/api/contracts';
import { ErrorState, FieldError, LoadingBlock } from '@/components/Feedback';
import { ArrowLeftIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import { dashboardKeys } from '@/features/dashboard/api';
import { createProject, fetchProject, fetchProjectOptions, projectKeys, updateProject } from '@/features/projects/api';
import { normalExecutionStatusCodes } from '@/features/projects/format';

type FormMode = 'create' | 'edit';

interface FormState {
    name: string;
    project_code: string;
    objective: string;
    description: string;
    rationale: string;
    target_group: string;
    strategy: string;
    key_points: string;
    budget: string;
    actual_spent: string;
    budget_source: string;
    responsible_person: string;
    monitor_person: string;
    evaluation_method: string;
    evaluation_tools: string;
    start_date: string;
    end_date: string;
    department_id: string;
    project_category_id: string;
    academic_year_id: string;
    fiscal_year_id: string;
    school_plan_id: string;
    execution_status: string;
}

export function ProjectFormPage({ mode }: { mode: FormMode }) {
    const { projectId = '' } = useParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<FormState>(emptyForm);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [submitError, setSubmitError] = useState<string | null>(null);

    const optionsQuery = useQuery({ queryKey: projectKeys.options, queryFn: fetchProjectOptions, staleTime: 5 * 60_000 });
    const projectQuery = useQuery({
        queryKey: projectKeys.detail(projectId),
        queryFn: () => fetchProject(projectId),
        enabled: mode === 'edit' && projectId !== '',
    });

    useEffect(() => {
        if (projectQuery.data) setForm(projectToForm(projectQuery.data));
    }, [projectQuery.data]);

    const mutation = useMutation({
        mutationFn: async (payload: ProjectPayload) => mode === 'create' ? createProject(payload) : updateProject(projectId, payload),
        onSuccess: async (project) => {
            queryClient.setQueryData(projectKeys.detail(project.id), project);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: projectKeys.all }),
                queryClient.invalidateQueries({ queryKey: dashboardKeys.all }),
            ]);
            navigate(`/projects/${project.id}`, { replace: true });
        },
    });

    const availablePlans = useMemo(
        () => optionsQuery.data?.school_plans.filter((plan) => !form.fiscal_year_id || plan.fiscal_year_id === Number(form.fiscal_year_id)) ?? [],
        [form.fiscal_year_id, optionsQuery.data?.school_plans],
    );

    const currentExecutionStatus = projectQuery.data?.execution_status?.code ?? '';
    const availableExecutionStatuses = useMemo(() => {
        const allowed = new Set(normalExecutionStatusCodes(currentExecutionStatus));

        return optionsQuery.data?.execution_statuses.filter((status) => allowed.has(status.code)) ?? [];
    }, [currentExecutionStatus, optionsQuery.data?.execution_statuses]);

    if (mode === 'edit' && isApiError(projectQuery.error) && projectQuery.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (optionsQuery.isPending || (mode === 'edit' && projectQuery.isPending)) return <LoadingBlock label={mode === 'create' ? 'กำลังเตรียมแบบฟอร์มโครงการ' : 'กำลังโหลดข้อมูลโครงการ'} />;
    if (optionsQuery.isError || (mode === 'edit' && projectQuery.isError)) {
        const error = optionsQuery.error ?? projectQuery.error;
        return <ErrorState action={<Link className="spa-button-secondary" to="/projects">กลับรายการโครงการ</Link>} message={error instanceof Error ? error.message : 'ไม่สามารถเตรียมแบบฟอร์มได้'} title="โหลดแบบฟอร์มไม่สำเร็จ" />;
    }

    const isReadOnly = mode === 'edit' && projectQuery.data?.abilities.update === false;

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (isReadOnly) return;
        setFieldErrors({});
        setSubmitError(null);

        try {
            await mutation.mutateAsync(buildPayload(form, mode));
        } catch (unknownError) {
            if (unknownError instanceof ApiError) {
                if (unknownError.status === 403) {
                    navigate('/forbidden', { replace: true });
                    return;
                }
                setSubmitError(unknownError.message);
                setFieldErrors(unknownError.errors);
            } else {
                setSubmitError('ไม่สามารถบันทึกโครงการได้ กรุณาลองใหม่');
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const update = (key: keyof FormState, value: string) => setForm((current) => ({ ...current, [key]: value }));
    const options = optionsQuery.data;

    return (
        <div className="space-y-7">
            <Link className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700" to={mode === 'edit' && projectId ? `/projects/${projectId}` : '/projects'}><ArrowLeftIcon className="size-4" />ยกเลิกและกลับ</Link>
            <PageHeader description={mode === 'create' ? 'บันทึกข้อมูลและงบประมาณในระดับโครงการ โดยไม่มีการจัดการกิจกรรมย่อย' : 'ปรับปรุงข้อมูลโครงการตามสิทธิ์ที่ API อนุญาต'} eyebrow="Project form" title={mode === 'create' ? 'สร้างโครงการใหม่' : 'แก้ไขโครงการ'} />

            {submitError && <ErrorState message={submitError} title="บันทึกไม่สำเร็จ" />}

            {isReadOnly && (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900" role="status">
                    <p className="font-bold">เปิดดูแบบอ่านอย่างเดียว</p>
                    <p className="mt-1 leading-6">โครงการนี้อยู่ในปีงบประมาณที่ล็อกแล้ว หรือบัญชีของคุณไม่มีสิทธิ์แก้ไข จึงไม่มีการส่งข้อมูลอัปเดต</p>
                </div>
            )}

            <form className="space-y-5" onSubmit={handleSubmit}>
                <fieldset className="contents" disabled={isReadOnly}>
                <FormSection description="ข้อมูลที่ใช้ค้นหาและอ้างอิงโครงการ" title="ข้อมูลพื้นฐาน">
                    <div className="grid gap-5 md:grid-cols-2">
                        <TextField errors={fieldErrors.name} label="ชื่อโครงการ" onChange={(value) => update('name', value)} required value={form.name} />
                        <TextField errors={fieldErrors.project_code} label="รหัสโครงการ" onChange={(value) => update('project_code', value)} placeholder="ถ้ามี" value={form.project_code} />
                        <SelectField errors={fieldErrors.department_id} label="ฝ่าย/กลุ่มงาน" onChange={(value) => update('department_id', value)} required value={form.department_id}><option value="">เลือกฝ่าย/กลุ่มงาน</option>{options?.departments.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</SelectField>
                        <SelectField errors={fieldErrors.project_category_id} label="ประเภทโครงการ" onChange={(value) => update('project_category_id', value)} required value={form.project_category_id}><option value="">เลือกประเภทโครงการ</option>{options?.project_categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</SelectField>
                        <SelectField errors={fieldErrors.academic_year_id} label="ปีการศึกษา" onChange={(value) => update('academic_year_id', value)} required value={form.academic_year_id}><option value="">เลือกปีการศึกษา</option>{options?.academic_years.map((item) => <option key={item.id} value={item.id}>{item.year}{item.is_locked ? ' (ปิดแล้ว)' : ''}</option>)}</SelectField>
                        <SelectField errors={fieldErrors.fiscal_year_id} label="ปีงบประมาณ" onChange={(value) => { update('fiscal_year_id', value); if (form.school_plan_id && !options?.school_plans.some((plan) => plan.id === Number(form.school_plan_id) && plan.fiscal_year_id === Number(value))) update('school_plan_id', ''); }} required value={form.fiscal_year_id}><option value="">เลือกปีงบประมาณ</option>{options?.fiscal_years.map((item) => <option disabled={item.is_locked} key={item.id} value={item.id}>{item.year}{item.is_locked ? ' (ปิดแล้ว)' : ''}</option>)}</SelectField>
                        <SelectField errors={fieldErrors.school_plan_id} label="แผนโรงเรียน" onChange={(value) => update('school_plan_id', value)} value={form.school_plan_id}><option value="">ไม่ระบุ</option>{availablePlans.map((item) => <option key={item.id} value={item.id}>{item.code ? `${item.code} · ` : ''}{item.name}</option>)}</SelectField>
                        <TextField errors={fieldErrors.responsible_person} label="ผู้รับผิดชอบ" onChange={(value) => update('responsible_person', value)} value={form.responsible_person} />
                    </div>
                </FormSection>

                <FormSection description="สรุปสาระสำคัญของโครงการโดยไม่แยกกิจกรรมย่อย" title="รายละเอียดโครงการ">
                    <div className="space-y-5">
                        <TextareaField errors={fieldErrors.objective} label="วัตถุประสงค์" onChange={(value) => update('objective', value)} required rows={4} value={form.objective} />
                        <div className="grid gap-5 lg:grid-cols-2"><TextareaField errors={fieldErrors.rationale} label="หลักการและเหตุผล" onChange={(value) => update('rationale', value)} rows={5} value={form.rationale} /><TextareaField errors={fieldErrors.description} label="รายละเอียด" onChange={(value) => update('description', value)} rows={5} value={form.description} /></div>
                        <TextareaField errors={fieldErrors.key_points} label="ประเด็นสำคัญ" onChange={(value) => update('key_points', value)} rows={4} value={form.key_points} />
                        <div className="grid gap-5 lg:grid-cols-2"><TextareaField errors={fieldErrors.target_group} label="กลุ่มเป้าหมาย" onChange={(value) => update('target_group', value)} rows={3} value={form.target_group} /><TextareaField errors={fieldErrors.strategy} label="กลยุทธ์" onChange={(value) => update('strategy', value)} rows={3} value={form.strategy} /></div>
                    </div>
                </FormSection>

                <FormSection description="ติดตามวงเงินและยอดใช้จริงที่ระดับโครงการเท่านั้น" title="งบประมาณและระยะเวลา">
                    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        <TextField errors={fieldErrors.budget} inputMode="decimal" label="งบประมาณ (บาท)" min="0" onChange={(value) => update('budget', value)} required step="0.01" type="number" value={form.budget} />
                        {mode === 'edit' && <TextField errors={fieldErrors.actual_spent} inputMode="decimal" label="ใช้ไปแล้ว (บาท)" min="0" onChange={(value) => update('actual_spent', value)} step="0.01" type="number" value={form.actual_spent} />}
                        <SelectField errors={fieldErrors.budget_source} label="แหล่งงบประมาณ" onChange={(value) => update('budget_source', value)} value={form.budget_source}><option value="">ไม่ระบุ</option>{options?.budget_sources.map((source) => <option key={source} value={source}>{source}</option>)}</SelectField>
                        <TextField errors={fieldErrors.start_date} label="วันที่เริ่ม" onChange={(value) => update('start_date', value)} type="date" value={form.start_date} />
                        <TextField errors={fieldErrors.end_date} label="วันที่สิ้นสุด" onChange={(value) => update('end_date', value)} type="date" value={form.end_date} />
                        <TextField errors={fieldErrors.monitor_person} label="ผู้ติดตามโครงการ" onChange={(value) => update('monitor_person', value)} value={form.monitor_person} />
                    </div>
                </FormSection>

                {mode === 'edit' && (
                    <FormSection description="ผลประเมินเปลี่ยนได้เฉพาะผ่านขั้นตอน Finalize ในระบบประเมินโครงการ" title="สถานะโครงการ">
                        <div className="grid gap-5 md:grid-cols-2">
                            <SelectField errors={fieldErrors.execution_status} label="สถานะการดำเนินงาน" onChange={(value) => update('execution_status', value)} required value={form.execution_status}><option value="">เลือกสถานะ</option>{availableExecutionStatuses.map((item) => <option key={item.id} value={item.code}>{item.name}</option>)}</SelectField>
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"><p className="text-sm font-semibold text-slate-700">ผลประเมิน</p><p className="mt-1 text-xs leading-5 text-slate-500">ดูและ Finalize ผลได้จากเมนู “ประเมินโครงการ” เท่านั้น</p></div>
                        </div>
                    </FormSection>
                )}

                <FormSection description="ข้อมูลสำหรับการติดตามและประเมินผลโครงการ" title="การประเมิน">
                    <div className="grid gap-5 lg:grid-cols-2"><TextareaField errors={fieldErrors.evaluation_method} label="วิธีประเมิน" onChange={(value) => update('evaluation_method', value)} rows={4} value={form.evaluation_method} /><TextareaField errors={fieldErrors.evaluation_tools} label="เครื่องมือประเมิน" onChange={(value) => update('evaluation_tools', value)} rows={4} value={form.evaluation_tools} /></div>
                </FormSection>
                </fieldset>

                <div className="sticky bottom-4 z-20 flex flex-col-reverse gap-3 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-xl shadow-slate-900/10 backdrop-blur sm:flex-row sm:justify-end">
                    <Link className="spa-button-secondary" to={mode === 'edit' && projectId ? `/projects/${projectId}` : '/projects'}>{isReadOnly ? 'กลับหน้ารายละเอียด' : 'ยกเลิก'}</Link>
                    {!isReadOnly && <button className="spa-button-primary" disabled={mutation.isPending} type="submit">{mutation.isPending ? 'กำลังบันทึก…' : mode === 'create' ? 'สร้างโครงการ' : 'บันทึกการแก้ไข'}</button>}
                </div>
            </form>
        </div>
    );
}

function FormSection({ title, description, children }: { title: string; description: string; children: React.ReactNode }) {
    return <section className="spa-card p-5 sm:p-7"><div className="mb-6 border-b border-slate-100 pb-4"><h2 className="text-lg font-bold text-slate-900">{title}</h2><p className="mt-1 text-sm text-slate-500">{description}</p></div>{children}</section>;
}

function TextField({ label, value, onChange, errors, ...props }: { label: string; value: string; onChange: (value: string) => void; errors?: string[] } & Omit<React.InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'className'>) {
    return <label><span className="spa-label">{label}{props.required && <span className="ml-1 text-rose-600">*</span>}</span><input {...props} className="spa-input" onChange={(event) => onChange(event.target.value)} value={value} /><FieldError errors={errors} /></label>;
}

function TextareaField({ label, value, onChange, errors, ...props }: { label: string; value: string; onChange: (value: string) => void; errors?: string[] } & Omit<React.TextareaHTMLAttributes<HTMLTextAreaElement>, 'value' | 'onChange' | 'className'>) {
    return <label><span className="spa-label">{label}{props.required && <span className="ml-1 text-rose-600">*</span>}</span><textarea {...props} className="spa-input min-h-24 resize-y" onChange={(event) => onChange(event.target.value)} value={value} /><FieldError errors={errors} /></label>;
}

function SelectField({ label, value, onChange, errors, children, ...props }: { label: string; value: string; onChange: (value: string) => void; errors?: string[]; children: React.ReactNode } & Omit<React.SelectHTMLAttributes<HTMLSelectElement>, 'value' | 'onChange' | 'className'>) {
    return <label><span className="spa-label">{label}{props.required && <span className="ml-1 text-rose-600">*</span>}</span><select {...props} className="spa-input" onChange={(event) => onChange(event.target.value)} value={value}>{children}</select><FieldError errors={errors} /></label>;
}

const emptyForm: FormState = {
    name: '', project_code: '', objective: '', description: '', rationale: '', target_group: '', strategy: '', key_points: '', budget: '', actual_spent: '0', budget_source: '', responsible_person: '', monitor_person: '', evaluation_method: '', evaluation_tools: '', start_date: '', end_date: '', department_id: '', project_category_id: '', academic_year_id: '', fiscal_year_id: '', school_plan_id: '', execution_status: '',
};

const projectToForm = (project: Project): FormState => ({
    name: project.name,
    project_code: project.project_code ?? '',
    objective: project.objective ?? '',
    description: project.description ?? '',
    rationale: project.rationale ?? '',
    target_group: project.target_group ?? '',
    strategy: project.strategy ?? '',
    key_points: project.key_points ?? '',
    budget: String(project.budget ?? ''),
    actual_spent: String(project.actual_spent ?? 0),
    budget_source: project.budget_source ?? '',
    responsible_person: project.responsible_person ?? '',
    monitor_person: project.monitor_person ?? '',
    evaluation_method: project.evaluation_method ?? '',
    evaluation_tools: project.evaluation_tools ?? '',
    start_date: project.start_date ?? '',
    end_date: project.end_date ?? '',
    department_id: String(project.department?.id ?? ''),
    project_category_id: String(project.category?.id ?? ''),
    academic_year_id: String(project.academic_year?.id ?? ''),
    fiscal_year_id: String(project.fiscal_year?.id ?? ''),
    school_plan_id: String(project.school_plan?.id ?? ''),
    execution_status: project.execution_status?.code ?? '',
});

const nullable = (value: string): string | null => value.trim() || null;

const buildPayload = (form: FormState, mode: FormMode): ProjectPayload => {
    const payload: ProjectPayload = {
        name: form.name.trim(),
        project_code: nullable(form.project_code),
        objective: form.objective.trim(),
        description: nullable(form.description),
        rationale: nullable(form.rationale),
        target_group: nullable(form.target_group),
        strategy: nullable(form.strategy),
        key_points: nullable(form.key_points),
        budget: form.budget,
        budget_source: nullable(form.budget_source),
        responsible_person: nullable(form.responsible_person),
        monitor_person: nullable(form.monitor_person),
        evaluation_method: nullable(form.evaluation_method),
        evaluation_tools: nullable(form.evaluation_tools),
        start_date: nullable(form.start_date),
        end_date: nullable(form.end_date),
        department_id: Number(form.department_id),
        project_category_id: Number(form.project_category_id),
        academic_year_id: Number(form.academic_year_id),
        fiscal_year_id: Number(form.fiscal_year_id),
        school_plan_id: form.school_plan_id ? Number(form.school_plan_id) : null,
    };

    if (mode === 'edit') {
        payload.actual_spent = form.actual_spent;
        payload.execution_status = form.execution_status;
    }

    return payload;
};
