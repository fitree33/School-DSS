import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useEffect, useState } from 'react';
import { Link, Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom';

import { ApiError, isApiError } from '@/api/client';
import type { EvaluationCriterionPayload, EvaluationFramework, EvaluationFrameworkPayload } from '@/api/contracts';
import { EmptyState, ErrorState, FieldError, LoadingBlock } from '@/components/Feedback';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import {
    createEvaluationFramework,
    createEvaluationFrameworkVersion,
    evaluationKeys,
    fetchEvaluationFramework,
    fetchEvaluationOptions,
    updateEvaluationFramework,
} from '@/features/evaluations/api';
import { weightConfiguration } from '@/features/evaluations/framework';
import { yearLabel } from '@/features/projects/format';

type FrameworkFormMode = 'create' | 'edit';

interface FrameworkFormState {
    code: string;
    version: string;
    name: string;
    description: string;
    fiscal_year_id: string;
    effective_from: string;
    effective_to: string;
    criteria: CriterionFormState[];
}

interface CriterionFormState {
    key: number;
    name: string;
    description: string;
    max_score: string;
    weight: string;
    evaluation_method: string;
    evaluation_tools: string;
    is_active: boolean;
}

let criterionKey = 1;

export function EvaluationFrameworkFormPage({ mode }: { mode: FrameworkFormMode }) {
    const { frameworkId = '' } = useParams();
    const [searchParams] = useSearchParams();
    const sourceId = mode === 'create' ? searchParams.get('source_id') ?? '' : '';
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<FrameworkFormState>(emptyFramework());
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [clientError, setClientError] = useState<string | null>(null);

    const optionsQuery = useQuery({ queryKey: evaluationKeys.options, queryFn: fetchEvaluationOptions, staleTime: 5 * 60_000 });
    const frameworkQuery = useQuery({
        queryKey: evaluationKeys.framework(mode === 'edit' ? frameworkId : sourceId),
        queryFn: () => fetchEvaluationFramework(mode === 'edit' ? frameworkId : sourceId),
        enabled: (mode === 'edit' ? frameworkId : sourceId) !== '',
    });

    useEffect(() => {
        if (!frameworkQuery.data) return;
        setForm(frameworkToForm(frameworkQuery.data, sourceId !== ''));
    }, [frameworkQuery.data, sourceId]);

    const mutation = useMutation({
        mutationFn: async (payload: EvaluationFrameworkPayload) => {
            if (mode === 'edit') {
                const updatePayload = frameworkQuery.data?.is_used ? {
                    name: payload.name,
                    description: payload.description,
                } : {
                    name: payload.name,
                    description: payload.description,
                    fiscal_year_id: payload.fiscal_year_id,
                    effective_from: payload.effective_from,
                    effective_to: payload.effective_to,
                    criteria: payload.criteria,
                };
                return updateEvaluationFramework(frameworkId, updatePayload);
            }
            if (sourceId) {
                const versionPayload = {
                    version: payload.version,
                    name: payload.name,
                    description: payload.description,
                    fiscal_year_id: payload.fiscal_year_id,
                    effective_from: payload.effective_from,
                    effective_to: payload.effective_to,
                    criteria: payload.criteria,
                };
                return createEvaluationFrameworkVersion(sourceId, versionPayload);
            }
            return createEvaluationFramework(payload);
        },
        onSuccess: async (framework) => {
            queryClient.setQueryData(evaluationKeys.framework(framework.id), framework);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: evaluationKeys.frameworks }),
                queryClient.invalidateQueries({ queryKey: evaluationKeys.options }),
            ]);
            navigate('/evaluations/frameworks', { replace: true });
        },
    });

    if (isApiError(optionsQuery.error) && optionsQuery.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (optionsQuery.isPending || ((mode === 'edit' ? frameworkId : sourceId) !== '' && frameworkQuery.isPending)) return <LoadingBlock label="กำลังเตรียมแบบฟอร์มชุดเกณฑ์" />;
    if (optionsQuery.isError || frameworkQuery.isError) {
        const error = optionsQuery.error ?? frameworkQuery.error;
        return <ErrorState action={<Link className="spa-button-secondary" to="/evaluations/frameworks">กลับรายการชุดเกณฑ์</Link>} message={isApiError(error) ? error.message : 'ไม่สามารถโหลดแบบฟอร์มชุดเกณฑ์ได้'} title="โหลดแบบฟอร์มไม่สำเร็จ" />;
    }

    const existing = frameworkQuery.data;
    if ((mode === 'edit' && existing?.abilities?.update !== true)
        || (sourceId !== '' && existing?.abilities?.create_version !== true)) {
        return <Navigate replace to="/evaluations/frameworks" />;
    }

    const structureLocked = mode === 'edit' && existing?.is_used === true;
    const weights = weightConfiguration(form.criteria);
    const title = mode === 'edit' ? 'แก้ไขชุดเกณฑ์' : sourceId ? 'สร้างเวอร์ชันใหม่' : 'สร้างชุดเกณฑ์ใหม่';

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setFieldErrors({});
        setSubmitError(null);
        setClientError(null);
        const activeCriteria = form.criteria.filter((criterion) => criterion.is_active);
        if (!form.criteria.length) {
            setClientError('กรุณาเพิ่มตัวชี้วัดอย่างน้อย 1 รายการ');
            return;
        }
        if (!activeCriteria.length) {
            setClientError('กรุณาเปิดใช้งานตัวชี้วัดอย่างน้อย 1 รายการ');
            return;
        }
        try {
            await mutation.mutateAsync(buildPayload(form));
        } catch (error) {
            if (error instanceof ApiError) {
                setSubmitError(error.message);
                setFieldErrors(error.errors);
            } else setSubmitError('ไม่สามารถบันทึกชุดเกณฑ์ได้ กรุณาลองใหม่');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const update = <K extends keyof FrameworkFormState>(key: K, value: FrameworkFormState[K]) => setForm((current) => ({ ...current, [key]: value }));
    const updateCriterion = <K extends keyof CriterionFormState>(key: number, field: K, value: CriterionFormState[K]) => update('criteria', form.criteria.map((criterion) => criterion.key === key ? { ...criterion, [field]: value } : criterion));

    return (
        <div className="space-y-7">
            <Link className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700" to="/evaluations/frameworks"><ArrowLeftIcon className="size-4" />ยกเลิกและกลับ</Link>
            <PageHeader description="Framework จะเริ่มเป็น Inactive และต้องเปิดใช้งานอย่างชัดเจนเมื่อพร้อม" eyebrow="Evaluation framework" title={title} />
            {submitError && <ErrorState message={submitError} title="บันทึกชุดเกณฑ์ไม่สำเร็จ" />}
            {clientError && <div className="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-800" role="alert">{clientError}</div>}
            {structureLocked && <div className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900" role="status"><p className="font-bold">โครงสร้างเวอร์ชันนี้ถูกล็อก</p><p className="mt-1 leading-6">ชุดเกณฑ์ถูกใช้ในการประเมินแล้ว จึงแก้ตัวชี้วัด คะแนนเต็ม น้ำหนัก วิธี หรือเครื่องมือไม่ได้ หากต้องเปลี่ยนให้สร้างเวอร์ชันใหม่</p></div>}

            <form className="space-y-5" onSubmit={(event) => void handleSubmit(event)}>
                <section className="spa-card p-5 sm:p-6">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <TextField disabled={mode === 'edit' || sourceId !== ''} errors={fieldErrors.code} label="รหัสชุดเกณฑ์" onChange={(value) => update('code', value)} required value={form.code} />
                        <TextField disabled={mode === 'edit'} errors={fieldErrors.version} label="เวอร์ชัน" onChange={(value) => update('version', value)} placeholder="เช่น 1.0" required value={form.version} />
                        <TextField errors={fieldErrors.name} label="ชื่อชุดเกณฑ์" onChange={(value) => update('name', value)} required value={form.name} />
                        <label><span className="spa-label">ปีงบประมาณที่ใช้</span><select className="spa-input" disabled={structureLocked} onChange={(event) => update('fiscal_year_id', event.target.value)} value={form.fiscal_year_id}><option value="">ใช้ได้ทุกปีงบประมาณ</option>{optionsQuery.data.fiscal_years.map((year) => <option key={year.id} value={year.id}>{yearLabel(year.year)}{year.is_locked ? ' · ล็อกแล้ว' : ''}</option>)}</select><FieldError errors={fieldErrors.fiscal_year_id} /></label>
                        <TextField disabled={structureLocked} errors={fieldErrors.effective_from} label="เริ่มใช้งาน" onChange={(value) => update('effective_from', value)} type="date" value={form.effective_from} />
                        <TextField disabled={structureLocked} errors={fieldErrors.effective_to} label="สิ้นสุดการใช้งาน" onChange={(value) => update('effective_to', value)} type="date" value={form.effective_to} />
                    </div>
                    <label className="mt-5 block"><span className="spa-label">คำอธิบายชุดเกณฑ์</span><textarea className="spa-input min-h-28 resize-y" onChange={(event) => update('description', event.target.value)} rows={4} value={form.description} /><FieldError errors={fieldErrors.description} /></label>
                </section>

                <section className="space-y-4">
                    <div className="flex flex-wrap items-end justify-between gap-3"><div><h2 className="text-lg font-bold text-slate-950">ตัวชี้วัด</h2><p className="mt-1 text-sm text-slate-500">ลำดับในรายการนี้เป็นลำดับแสดงผลจริง</p></div>{!structureLocked && <button className="spa-button-secondary" onClick={() => update('criteria', [...form.criteria, emptyCriterion()])} type="button"><PlusIcon className="size-4" />เพิ่มตัวชี้วัด</button>}</div>
                    {weights === 'mixed' && <div className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900" role="status">บันทึกเป็นฉบับร่างได้ แต่ต้องกำหนดน้ำหนักมากกว่า 0 ให้ครบทุกตัวชี้วัดที่ใช้งาน หรือเว้นว่างทั้งหมด ก่อนเปิดใช้ framework</div>}
                    {!form.criteria.length ? <EmptyState description="เพิ่มชื่อ คะแนนเต็ม และรายละเอียดของตัวชี้วัด" title="ยังไม่มีตัวชี้วัด" /> : form.criteria.map((criterion, index) => (
                        <article className="spa-card p-5 sm:p-6" key={criterion.key}>
                            <fieldset disabled={structureLocked}>
                                <div className="flex items-start gap-4"><span className="grid size-9 shrink-0 place-items-center rounded-full bg-teal-100 text-sm font-black text-teal-800">{index + 1}</span><div className="min-w-0 flex-1 space-y-5"><div className="grid gap-5 lg:grid-cols-[1fr_160px_160px_auto]"><TextField errors={fieldErrors[`criteria.${index}.name`]} label="ชื่อตัวชี้วัด" onChange={(value) => updateCriterion(criterion.key, 'name', value)} required value={criterion.name} /><TextField errors={fieldErrors[`criteria.${index}.max_score`]} label="คะแนนเต็ม" min="0.01" onChange={(value) => updateCriterion(criterion.key, 'max_score', value)} required step="0.01" type="number" value={criterion.max_score} /><TextField errors={fieldErrors[`criteria.${index}.weight`]} label="น้ำหนัก (ถ้ามี)" min="0.01" onChange={(value) => updateCriterion(criterion.key, 'weight', value)} step="0.01" type="number" value={criterion.weight} />{!structureLocked && <button aria-label={`ลบตัวชี้วัดที่ ${index + 1}`} className="mt-6 rounded-xl border border-rose-200 p-3 text-rose-700 hover:bg-rose-50" onClick={() => update('criteria', form.criteria.filter((item) => item.key !== criterion.key))} type="button"><TrashIcon className="size-5" /></button>}</div><label><span className="spa-label">คำอธิบาย</span><textarea className="spa-input min-h-20 resize-y" onChange={(event) => updateCriterion(criterion.key, 'description', event.target.value)} rows={3} value={criterion.description} /><FieldError errors={fieldErrors[`criteria.${index}.description`]} /></label><div className="grid gap-5 lg:grid-cols-2"><TextareaField errors={fieldErrors[`criteria.${index}.evaluation_method`]} label="วิธีประเมิน" onChange={(value) => updateCriterion(criterion.key, 'evaluation_method', value)} value={criterion.evaluation_method} /><TextareaField errors={fieldErrors[`criteria.${index}.evaluation_tools`]} label="เครื่องมือประเมิน" onChange={(value) => updateCriterion(criterion.key, 'evaluation_tools', value)} value={criterion.evaluation_tools} /></div><label className="flex items-center gap-3 text-sm font-medium text-slate-700"><input checked={criterion.is_active} className="rounded border-slate-300 text-teal-700 focus:ring-teal-600" onChange={(event) => updateCriterion(criterion.key, 'is_active', event.target.checked)} type="checkbox" />ใช้ตัวชี้วัดนี้</label></div></div>
                            </fieldset>
                        </article>
                    ))}
                </section>

                <div className="sticky bottom-4 z-20 flex flex-col-reverse gap-3 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-xl shadow-slate-900/10 backdrop-blur sm:flex-row sm:justify-end"><Link className="spa-button-secondary" to="/evaluations/frameworks">ยกเลิก</Link><button className="spa-button-primary" disabled={mutation.isPending} type="submit">{mutation.isPending ? 'กำลังบันทึก…' : 'บันทึกชุดเกณฑ์'}</button></div>
            </form>
        </div>
    );
}

function TextField({ label, value, onChange, errors, ...props }: { label: string; value: string; onChange: (value: string) => void; errors?: string[] } & Omit<React.InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'className'>) {
    return <label><span className="spa-label">{label}{props.required && <span className="ml-1 text-rose-600">*</span>}</span><input {...props} className="spa-input" onChange={(event) => onChange(event.target.value)} value={value} /><FieldError errors={errors} /></label>;
}

function TextareaField({ label, value, onChange, errors }: { label: string; value: string; onChange: (value: string) => void; errors?: string[] }) {
    return <label><span className="spa-label">{label}</span><textarea className="spa-input min-h-24 resize-y" onChange={(event) => onChange(event.target.value)} rows={3} value={value} /><FieldError errors={errors} /></label>;
}

const emptyCriterion = (): CriterionFormState => ({ key: criterionKey++, name: '', description: '', max_score: '5', weight: '', evaluation_method: '', evaluation_tools: '', is_active: true });
const emptyFramework = (): FrameworkFormState => ({ code: '', version: '1.0', name: '', description: '', fiscal_year_id: '', effective_from: '', effective_to: '', criteria: [emptyCriterion()] });

const frameworkToForm = (framework: EvaluationFramework, newVersion: boolean): FrameworkFormState => ({
    code: framework.code,
    version: newVersion ? '' : framework.version,
    name: framework.name,
    description: framework.description ?? '',
    fiscal_year_id: String(framework.fiscal_year?.id ?? ''),
    effective_from: framework.effective_from ?? '',
    effective_to: framework.effective_to ?? '',
    criteria: framework.criteria.map((criterion) => ({ key: criterionKey++, name: criterion.name, description: criterion.description ?? '', max_score: String(criterion.max_score), weight: Number(criterion.weight) > 0 ? String(criterion.weight) : '', evaluation_method: criterion.evaluation_method ?? '', evaluation_tools: criterion.evaluation_tools ?? '', is_active: criterion.is_active })),
});

const buildPayload = (form: FrameworkFormState): EvaluationFrameworkPayload => ({
    code: form.code.trim(),
    version: form.version.trim(),
    name: form.name.trim(),
    description: nullable(form.description),
    fiscal_year_id: form.fiscal_year_id ? Number(form.fiscal_year_id) : null,
    effective_from: nullable(form.effective_from),
    effective_to: nullable(form.effective_to),
    criteria: form.criteria.map<EvaluationCriterionPayload>((criterion, index) => ({
        name: criterion.name.trim(),
        description: nullable(criterion.description),
        max_score: criterion.max_score,
        weight: criterion.weight.trim() ? criterion.weight : null,
        sort_order: index + 1,
        evaluation_method: nullable(criterion.evaluation_method),
        evaluation_tools: nullable(criterion.evaluation_tools),
        is_active: criterion.is_active,
    })),
});

const nullable = (value: string): string | null => value.trim() || null;
