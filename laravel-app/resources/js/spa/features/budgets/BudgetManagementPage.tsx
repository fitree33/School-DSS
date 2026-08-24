import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useEffect, useState } from 'react';
import { Link, Navigate, useSearchParams } from 'react-router-dom';

import { ApiError, isApiError } from '@/api/client';
import type {
    BudgetManagementData,
    DepartmentBudgetPayload,
    DepartmentBudgetRecord,
    SchoolBudgetPayload,
    SchoolBudgetRecord,
} from '@/api/contracts';
import { EmptyState, ErrorState, FieldError, LoadingBlock } from '@/components/Feedback';
import { PageHeader } from '@/components/PageHeader';
import {
    budgetManagementKeys,
    fetchBudgetManagement,
    updateDepartmentBudget,
    updateSchoolBudget,
} from '@/features/budgets/api';
import {
    withUpdatedDepartmentBudget,
    withUpdatedSchoolBudget,
} from '@/features/budgets/cache';
import { dashboardKeys } from '@/features/dashboard/api';
import { formatCurrency } from '@/features/projects/format';

export function BudgetManagementPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const fiscalYearId = searchParams.get('fiscal_year_id') || undefined;
    const budgetQuery = useQuery({
        queryKey: budgetManagementKeys.detail(fiscalYearId),
        queryFn: () => fetchBudgetManagement(fiscalYearId),
    });
    const managementQueryKey = budgetManagementKeys.detail(fiscalYearId);

    if (isApiError(budgetQuery.error) && budgetQuery.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (budgetQuery.isPending) return <LoadingBlock label="กำลังโหลดข้อมูลงบประมาณ" />;
    if (budgetQuery.isError) {
        return (
            <ErrorState
                action={<button className="spa-button-primary" onClick={() => void budgetQuery.refetch()} type="button">ลองใหม่</button>}
                message={budgetQuery.error instanceof Error ? budgetQuery.error.message : 'ไม่สามารถโหลดข้อมูลงบประมาณได้'}
                title="โหลดหน้าจัดการงบไม่สำเร็จ"
            />
        );
    }

    const data = budgetQuery.data;
    const selectedFiscalYearId = fiscalYearId ?? (data.fiscal_year ? String(data.fiscal_year.id) : '');
    const isLocked = data.fiscal_year?.is_locked === true;

    return (
        <div className="space-y-7">
            <PageHeader
                actions={<Link className="spa-button-secondary" to={`/dashboard${selectedFiscalYearId ? `?fiscal_year_id=${selectedFiscalYearId}` : ''}`}>กลับแดชบอร์ด</Link>}
                description="กำหนดงบตั้งต้นของโรงเรียนและยืนยันวงเงินจัดสรรให้แต่ละฝ่าย"
                eyebrow="Budget management"
                title="จัดการงบประมาณ"
            />

            <section className="spa-card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5" aria-label="เลือกปีงบประมาณ">
                <div>
                    <p className="text-sm font-bold text-slate-900">ปีงบประมาณ</p>
                    <p className="mt-1 text-xs text-slate-500">เลือกปีเพื่อดูหรือแก้ไขงบประมาณ</p>
                </div>
                <select
                    aria-label="ปีงบประมาณ"
                    className="spa-input sm:w-56"
                    onChange={(event) => {
                        const next = new URLSearchParams(searchParams);
                        if (event.target.value) next.set('fiscal_year_id', event.target.value);
                        else next.delete('fiscal_year_id');
                        setSearchParams(next, { replace: true });
                    }}
                    value={selectedFiscalYearId}
                >
                    {!data.fiscal_years.length && <option value="">ไม่มีปีงบประมาณ</option>}
                    {data.fiscal_years.map((year) => (
                        <option key={year.id} value={year.id}>{year.year}{year.is_active ? ' · ปีปัจจุบัน' : ''}{year.is_locked ? ' · ล็อกแล้ว' : ''}</option>
                    ))}
                </select>
            </section>

            {!data.fiscal_year ? (
                <EmptyState description="ต้องเพิ่มปีงบประมาณก่อนจึงจะกำหนดวงเงินได้" title="ยังไม่มีปีงบประมาณ" />
            ) : (
                <>
                    {isLocked && (
                        <div className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900" role="status">
                            <p className="font-bold">ปีงบประมาณนี้ล็อกแล้ว</p>
                            <p className="mt-1 leading-6">ข้อมูลแสดงแบบอ่านอย่างเดียว ระบบจะไม่อนุญาตให้แก้ไขวงเงินหรือยืนยันการจัดสรร</p>
                        </div>
                    )}

                    <SchoolBudgetForm
                        fiscalYearId={data.fiscal_year.id}
                        isLocked={isLocked}
                        key={data.fiscal_year.id}
                        managementQueryKey={managementQueryKey}
                        record={data.school_budget}
                    />

                    <section className="space-y-4" aria-labelledby="department-allocation-heading">
                        <div>
                            <h2 className="text-xl font-bold text-slate-950" id="department-allocation-heading">จัดสรรงบรายฝ่าย</h2>
                            <p className="mt-1 text-sm leading-6 text-slate-500">วงเงินแบบร่างจะยังไม่รวมในยอดจัดสรร จนกว่าจะเลือก “ยืนยันการจัดสรร” และบันทึก</p>
                        </div>

                        {!data.department_budgets.length ? (
                            <EmptyState description="เพิ่มฝ่าย/กลุ่มงานก่อนจึงจะจัดสรรงบประมาณได้" title="ยังไม่มีฝ่าย/กลุ่มงาน" />
                        ) : (
                            <div className="grid gap-4 xl:grid-cols-2">
                                {data.department_budgets.map((record) => (
                                    <DepartmentBudgetForm
                                        canEdit={!isLocked && data.school_budget !== null}
                                        fiscalYearId={data.fiscal_year?.id ?? 0}
                                        key={`${data.fiscal_year?.id}-${record.department.id}`}
                                        managementQueryKey={managementQueryKey}
                                        record={record}
                                    />
                                ))}
                            </div>
                        )}

                        {!isLocked && data.school_budget === null && data.department_budgets.length > 0 && (
                            <p className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800" role="status">บันทึกงบประมาณรวมของโรงเรียนก่อน แล้วจึงจัดสรรงบรายฝ่ายได้</p>
                        )}
                    </section>
                </>
            )}
        </div>
    );
}

type ManagementQueryKey = ReturnType<typeof budgetManagementKeys.detail>;

function SchoolBudgetForm({
    fiscalYearId,
    isLocked,
    managementQueryKey,
    record,
}: {
    fiscalYearId: number;
    isLocked: boolean;
    managementQueryKey: ManagementQueryKey;
    record: SchoolBudgetRecord | null;
}) {
    const queryClient = useQueryClient();
    const [totalAmount, setTotalAmount] = useState(record?.total_amount ?? '0.00');
    const [notes, setNotes] = useState(record?.notes ?? '');
    const [saved, setSaved] = useState(false);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    useEffect(() => {
        setTotalAmount(record?.total_amount ?? '0.00');
        setNotes(record?.notes ?? '');
        setFieldErrors({});
    }, [record, fiscalYearId]);

    useEffect(() => setSaved(false), [fiscalYearId]);

    const mutation = useMutation({
        mutationFn: (payload: SchoolBudgetPayload) => updateSchoolBudget(fiscalYearId, payload),
        onSuccess: async (updatedSchoolBudget) => {
            setSaved(true);
            queryClient.setQueryData<BudgetManagementData>(managementQueryKey, (current) =>
                current ? withUpdatedSchoolBudget(current, updatedSchoolBudget) : current);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: budgetManagementKeys.all }),
                queryClient.invalidateQueries({ queryKey: dashboardKeys.all }),
            ]);
        },
    });

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (isLocked) return;
        setSaved(false);
        setFieldErrors({});

        try {
            await mutation.mutateAsync({ total_amount: totalAmount, notes: notes.trim() || null });
        } catch (error) {
            if (error instanceof ApiError) setFieldErrors(error.errors);
        }
    };

    return (
        <section className="spa-card overflow-hidden" aria-labelledby="school-budget-form-heading">
            <div className="border-b border-slate-100 px-5 py-4 sm:px-6">
                <h2 className="font-bold text-slate-950" id="school-budget-form-heading">งบประมาณรวมของโรงเรียน</h2>
                <p className="mt-1 text-sm text-slate-500">วงเงินสูงสุดสำหรับการจัดสรรให้ทุกฝ่ายในปีนี้</p>
            </div>
            <form className="grid gap-5 p-5 sm:p-6 lg:grid-cols-[minmax(220px,0.7fr)_minmax(280px,1.3fr)_auto] lg:items-start" onSubmit={handleSubmit}>
                <label>
                    <span className="spa-label">วงเงินรวม (บาท)</span>
                    <input className="spa-input" disabled={isLocked || mutation.isPending} inputMode="decimal" min="0" onChange={(event) => { setSaved(false); setTotalAmount(event.target.value); }} required step="0.01" type="number" value={totalAmount} />
                    <FieldError errors={fieldErrors.total_amount} />
                    {record && <span className="mt-1.5 block text-xs text-slate-500">ปัจจุบัน {formatCurrency(record.total_amount)}</span>}
                </label>
                <label>
                    <span className="spa-label">หมายเหตุ</span>
                    <textarea className="spa-input min-h-24 resize-y" disabled={isLocked || mutation.isPending} maxLength={5000} onChange={(event) => { setSaved(false); setNotes(event.target.value); }} placeholder="ที่มาหรือเงื่อนไขของงบประมาณ (ถ้ามี)" rows={3} value={notes} />
                    <FieldError errors={fieldErrors.notes} />
                </label>
                {!isLocked && <button className="spa-button-primary lg:mt-[1.65rem]" disabled={mutation.isPending} type="submit">{mutation.isPending ? 'กำลังบันทึก…' : record ? 'บันทึกงบรวม' : 'ตั้งงบรวม'}</button>}
                {fieldErrors.fiscal_year_id && <div className="lg:col-span-3"><FieldError errors={fieldErrors.fiscal_year_id} /></div>}
                {mutation.isError && <InlineError className="lg:col-span-3" error={mutation.error} />}
                {saved && <p className="text-sm font-semibold text-emerald-700 lg:col-span-3" role="status">บันทึกงบประมาณรวมแล้ว</p>}
            </form>
        </section>
    );
}

function DepartmentBudgetForm({
    fiscalYearId,
    canEdit,
    managementQueryKey,
    record,
}: {
    fiscalYearId: number;
    canEdit: boolean;
    managementQueryKey: ManagementQueryKey;
    record: DepartmentBudgetRecord;
}) {
    const queryClient = useQueryClient();
    const [amount, setAmount] = useState(record.allocated_amount);
    const [isAllocated, setIsAllocated] = useState(record.is_allocated);
    const [notes, setNotes] = useState(record.notes ?? '');
    const [saved, setSaved] = useState(false);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    useEffect(() => {
        setAmount(record.allocated_amount);
        setIsAllocated(record.is_allocated);
        setNotes(record.notes ?? '');
        setFieldErrors({});
    }, [record]);

    const mutation = useMutation({
        mutationFn: (payload: DepartmentBudgetPayload) => updateDepartmentBudget(fiscalYearId, record.department.id, payload),
        onSuccess: async (updatedDepartmentBudget) => {
            setSaved(true);
            queryClient.setQueryData<BudgetManagementData>(managementQueryKey, (current) =>
                current ? withUpdatedDepartmentBudget(current, updatedDepartmentBudget) : current);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: budgetManagementKeys.all }),
                queryClient.invalidateQueries({ queryKey: dashboardKeys.all }),
            ]);
        },
    });

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!canEdit) return;
        setSaved(false);
        setFieldErrors({});

        try {
            await mutation.mutateAsync({ allocated_amount: amount, is_allocated: isAllocated, notes: notes.trim() || null });
        } catch (error) {
            if (error instanceof ApiError) setFieldErrors(error.errors);
        }
    };

    return (
        <article className={`spa-card overflow-hidden ${record.is_allocated ? 'border-teal-200' : ''}`}>
            <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 className="font-bold text-slate-950">{record.department.name}</h3>
                    <p className="mt-1 text-xs text-slate-500">วงเงินปัจจุบัน {formatCurrency(record.allocated_amount)}</p>
                </div>
                <span className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${record.is_allocated ? 'bg-teal-100 text-teal-800' : 'bg-slate-100 text-slate-600'}`}>{record.is_allocated ? 'จัดสรรแล้ว' : 'แบบร่าง'}</span>
            </div>
            <form className="space-y-4 p-5" onSubmit={handleSubmit}>
                <label>
                    <span className="spa-label">วงเงินจัดสรร (บาท)</span>
                    <input className="spa-input" disabled={!canEdit || mutation.isPending} inputMode="decimal" min="0" onChange={(event) => { setSaved(false); setAmount(event.target.value); }} required step="0.01" type="number" value={amount} />
                    <FieldError errors={fieldErrors.allocated_amount} />
                </label>
                <label>
                    <span className="spa-label">หมายเหตุ</span>
                    <textarea className="spa-input min-h-20 resize-y" disabled={!canEdit || mutation.isPending} maxLength={5000} onChange={(event) => { setSaved(false); setNotes(event.target.value); }} placeholder="รายละเอียดการจัดสรร (ถ้ามี)" rows={2} value={notes} />
                    <FieldError errors={fieldErrors.notes} />
                </label>
                <div className="flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <label className="flex cursor-pointer items-center gap-3 text-sm font-semibold text-slate-700">
                        <input checked={isAllocated} className="rounded border-slate-300 text-teal-700 focus:ring-teal-600" disabled={!canEdit || mutation.isPending} onChange={(event) => { setSaved(false); setIsAllocated(event.target.checked); }} type="checkbox" />
                        ยืนยันการจัดสรร
                    </label>
                    {canEdit && <button className="spa-button-primary min-h-10" disabled={mutation.isPending} type="submit">{mutation.isPending ? 'กำลังบันทึก…' : 'บันทึกฝ่ายนี้'}</button>}
                </div>
                <FieldError errors={fieldErrors.is_allocated} />
                <FieldError errors={fieldErrors.fiscal_year_id ?? fieldErrors.school_budget} />
                {mutation.isError && <InlineError error={mutation.error} />}
                {saved && <p className="text-sm font-semibold text-emerald-700" role="status">บันทึกงบของฝ่ายแล้ว</p>}
            </form>
        </article>
    );
}

function InlineError({ error, className = '' }: { error: unknown; className?: string }) {
    return (
        <p className={`rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 ${className}`} role="alert">
            {isApiError(error) ? error.message : 'บันทึกข้อมูลไม่สำเร็จ กรุณาลองใหม่'}
        </p>
    );
}
