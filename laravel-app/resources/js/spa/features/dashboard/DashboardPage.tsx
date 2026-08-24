import { useQuery } from '@tanstack/react-query';
import type { CSSProperties } from 'react';
import { Link, useSearchParams } from 'react-router-dom';

import { isApiError } from '@/api/client';
import type {
    DashboardDepartmentBudget,
    DashboardEvaluationStatusCounts,
    DashboardExecutionStatusCounts,
    DashboardSchoolBudget,
} from '@/api/contracts';
import { EmptyState, ErrorState, LoadingBlock } from '@/components/Feedback';
import { PageHeader } from '@/components/PageHeader';
import { dashboardKeys, fetchDashboard } from '@/features/dashboard/api';
import {
    amountValue,
    budgetVisualRatio,
    comparativeBarScale,
    formatPercentage,
} from '@/features/dashboard/format';
import { formatCurrency } from '@/features/projects/format';

const executionStatuses: Array<{ code: keyof DashboardExecutionStatusCounts; label: string; tone: string }> = [
    { code: 'not_started', label: 'ยังไม่ดำเนินการ', tone: 'border-slate-200 bg-slate-50 text-slate-700' },
    { code: 'in_progress', label: 'กำลังดำเนินการ', tone: 'border-sky-200 bg-sky-50 text-sky-700' },
    { code: 'completed', label: 'ดำเนินการแล้ว', tone: 'border-emerald-200 bg-emerald-50 text-emerald-700' },
];

const evaluationStatuses: Array<{ code: keyof DashboardEvaluationStatusCounts; label: string; tone: string }> = [
    { code: 'pending', label: 'รอประเมิน', tone: 'border-amber-200 bg-amber-50 text-amber-800' },
    { code: 'passed', label: 'ผ่าน', tone: 'border-emerald-200 bg-emerald-50 text-emerald-700' },
    { code: 'failed', label: 'ไม่ผ่าน', tone: 'border-rose-200 bg-rose-50 text-rose-700' },
];

export function DashboardPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const fiscalYearId = searchParams.get('fiscal_year_id') || undefined;
    const dashboardQuery = useQuery({
        queryKey: dashboardKeys.summary(fiscalYearId),
        queryFn: () => fetchDashboard(fiscalYearId),
    });

    if (dashboardQuery.isPending) return <LoadingBlock label="กำลังสรุปข้อมูลงบประมาณ" />;

    if (dashboardQuery.isError) {
        return (
            <ErrorState
                action={<button className="spa-button-primary" onClick={() => void dashboardQuery.refetch()} type="button">ลองใหม่</button>}
                message={isApiError(dashboardQuery.error) ? dashboardQuery.error.message : 'ไม่สามารถโหลดข้อมูลแดชบอร์ดได้'}
                title="โหลดแดชบอร์ดไม่สำเร็จ"
            />
        );
    }

    const dashboard = dashboardQuery.data;
    const selectedFiscalYearId = fiscalYearId ?? (dashboard.fiscal_year ? String(dashboard.fiscal_year.id) : '');

    return (
        <div className="space-y-7">
            <PageHeader
                actions={dashboard.can.manage_budgets ? <Link className="spa-button-secondary" to={`/budgets${selectedFiscalYearId ? `?fiscal_year_id=${selectedFiscalYearId}` : ''}`}>จัดการงบประมาณ</Link> : undefined}
                description="ติดตามงบรวม การจัดสรรรายฝ่าย การใช้จริง และสถานะโครงการจากข้อมูลล่าสุด"
                eyebrow="Overview"
                title="แดชบอร์ด"
            />

            <section className="spa-card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5" aria-label="เลือกปีงบประมาณ">
                <div>
                    <p className="text-sm font-bold text-slate-900">ปีงบประมาณ</p>
                    <p className="mt-1 text-xs text-slate-500">ตัวเลขทั้งหมดด้านล่างอ้างอิงปีที่เลือก</p>
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
                    {!dashboard.fiscal_years.length && <option value="">ไม่มีปีงบประมาณ</option>}
                    {dashboard.fiscal_years.map((year) => (
                        <option key={year.id} value={year.id}>
                            {year.year}{year.is_active ? ' · ปีปัจจุบัน' : ''}{year.is_locked ? ' · ล็อกแล้ว' : ''}
                        </option>
                    ))}
                </select>
            </section>

            {!dashboard.fiscal_year ? (
                <EmptyState description="เพิ่มปีงบประมาณและงบตั้งต้นก่อน จึงจะเริ่มสรุปข้อมูลได้" title="ยังไม่มีปีงบประมาณ" />
            ) : (
                <>
                    <SchoolBudgetOverview budget={dashboard.school_budget} />

                    <section className="grid gap-5 xl:grid-cols-2">
                        <StatusOverview
                            counts={dashboard.project_execution_status_counts}
                            filterName="execution_status"
                            fiscalYearId={selectedFiscalYearId}
                            items={executionStatuses}
                            title="สถานะการดำเนินโครงการ"
                            total={dashboard.total_projects}
                        />
                        <StatusOverview
                            counts={dashboard.evaluation_status_counts}
                            filterName="evaluation_status"
                            fiscalYearId={selectedFiscalYearId}
                            items={evaluationStatuses}
                            title="ผลประเมินโครงการ"
                            total={dashboard.total_projects}
                        />
                    </section>

                    <DepartmentBudgetSection departments={dashboard.departments} />
                </>
            )}
        </div>
    );
}

function SchoolBudgetOverview({ budget }: { budget: DashboardSchoolBudget }) {
    const total = amountValue(budget.total_budget);
    const actual = amountValue(budget.total_actual_spent);
    const remaining = amountValue(budget.remaining);

    return (
        <section className="space-y-4" aria-labelledby="school-budget-heading">
            {(budget.overallocated || budget.overspent) && (
                <div className="flex flex-wrap gap-2" role="alert">
                    {budget.overallocated && <WarningBadge label="จัดสรรให้ฝ่ายเกินงบประมาณรวม" />}
                    {budget.overspent && <WarningBadge label="ยอดใช้จริงเกินงบประมาณรวม" />}
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label="งบประมาณรวม" value={formatCurrency(budget.total_budget)} />
                <MetricCard label="ใช้ไปแล้ว" tone={budget.overspent ? 'danger' : 'default'} value={formatCurrency(budget.total_actual_spent)} />
                <MetricCard label="งบคงเหลือ" tone={remaining < 0 ? 'danger' : 'positive'} value={formatCurrency(budget.remaining)} />
                <MetricCard
                    detail={`คงเหลือ ${formatPercentage(budget.remaining_percentage)}`}
                    label="สัดส่วนที่ใช้ไป"
                    tone={budget.overspent ? 'danger' : 'default'}
                    value={formatPercentage(budget.used_percentage)}
                />
            </div>

            <div className="spa-card grid gap-6 p-5 lg:grid-cols-[240px_1fr] lg:p-6">
                <BudgetDonut budget={total} overspent={budget.overspent} spent={actual} usedPercentage={budget.used_percentage} />
                <div className="min-w-0">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h2 className="font-bold text-slate-950" id="school-budget-heading">ภาพรวมการจัดสรรงบโรงเรียน</h2>
                            <p className="mt-1 text-sm text-slate-500">เปรียบเทียบงบที่จัดสรรให้แต่ละฝ่ายกับวงเงินทั้งหมด</p>
                        </div>
                        <span className={`rounded-full px-3 py-1 text-xs font-bold ${budget.overallocated ? 'bg-rose-100 text-rose-800' : 'bg-teal-100 text-teal-800'}`}>
                            {budget.overallocated ? 'จัดสรรเกินวงเงิน' : 'อยู่ในวงเงิน'}
                        </span>
                    </div>
                    <dl className="mt-6 grid gap-3 sm:grid-cols-2">
                        <BudgetDefinition label="จัดสรรให้ฝ่ายแล้ว" value={formatCurrency(budget.allocated_to_departments)} />
                        <BudgetDefinition label="ยังไม่ได้จัดสรร" tone={amountValue(budget.unallocated) < 0 ? 'danger' : 'default'} value={formatCurrency(budget.unallocated)} />
                    </dl>
                    <div className="mt-5" role="img" aria-label={`จัดสรรแล้ว ${formatCurrency(budget.allocated_to_departments)} จากงบรวม ${formatCurrency(budget.total_budget)}`}>
                        <ComparativeTrack
                            actual={amountValue(budget.allocated_to_departments)}
                            actualLabel="จัดสรรแล้ว"
                            budget={total}
                            danger={budget.overallocated}
                        />
                    </div>
                </div>
            </div>
        </section>
    );
}

function MetricCard({ label, value, detail, tone = 'default' }: { label: string; value: string; detail?: string; tone?: 'default' | 'positive' | 'danger' }) {
    const tones = {
        default: 'text-slate-950',
        positive: 'text-teal-700',
        danger: 'text-rose-700',
    };

    return (
        <article className="spa-card p-5">
            <p className="text-sm font-semibold text-slate-600">{label}</p>
            <p className={`mt-3 break-words text-2xl font-bold tabular-nums ${tones[tone]}`}>{value}</p>
            {detail && <p className="mt-2 text-xs font-medium text-slate-500">{detail}</p>}
        </article>
    );
}

function BudgetDonut({ budget, spent, usedPercentage, overspent }: { budget: number; spent: number; usedPercentage: number | null; overspent: boolean }) {
    const visualRatio = budgetVisualRatio(budget, spent);
    const ringColor = overspent ? '#e11d48' : '#0f766e';

    return (
        <figure className="flex flex-col items-center justify-center text-center">
            <div className="relative size-44" role="img" aria-label={`ใช้ไป ${formatPercentage(usedPercentage)} ยอดใช้จริง ${formatCurrency(spent)} จากงบ ${formatCurrency(budget)}`}>
                <svg className="size-full -rotate-90" viewBox="0 0 120 120">
                    <circle cx="60" cy="60" fill="none" r="49" stroke="#e2e8f0" strokeWidth="13" />
                    <circle
                        cx="60"
                        cy="60"
                        fill="none"
                        pathLength="100"
                        r="49"
                        stroke={ringColor}
                        strokeDasharray={`${visualRatio} ${100 - visualRatio}`}
                        strokeLinecap="round"
                        strokeWidth="13"
                    />
                </svg>
                <div className="absolute inset-0 grid place-content-center px-6">
                    <span className={`text-2xl font-black tabular-nums ${overspent ? 'text-rose-700' : 'text-slate-950'}`}>{formatPercentage(usedPercentage)}</span>
                    <span className="mt-1 text-xs font-semibold text-slate-500">ใช้ไปแล้ว</span>
                </div>
            </div>
            {overspent && <figcaption className="mt-2 text-xs font-bold text-rose-700">เกินงบ {formatCurrency(Math.abs(budget - spent))}</figcaption>}
        </figure>
    );
}

function StatusOverview<T extends DashboardExecutionStatusCounts | DashboardEvaluationStatusCounts>({
    title,
    counts,
    items,
    total,
    filterName,
    fiscalYearId,
}: {
    title: string;
    counts: T;
    items: Array<{ code: keyof T; label: string; tone: string }>;
    total: number;
    filterName: 'execution_status' | 'evaluation_status';
    fiscalYearId: string;
}) {
    return (
        <section className="spa-card p-5 sm:p-6" aria-label={title}>
            <div className="flex items-center justify-between gap-3">
                <h2 className="font-bold text-slate-950">{title}</h2>
                <span className="text-xs font-semibold text-slate-500">ทั้งหมด {total.toLocaleString('th-TH')} โครงการ</span>
            </div>
            <div className="mt-5 grid gap-3 sm:grid-cols-3">
                {items.map((item) => {
                    const count = Number(counts[item.code]);
                    const projectFilters = new URLSearchParams({ [filterName]: String(item.code) });
                    if (fiscalYearId) projectFilters.set('fiscal_year_id', fiscalYearId);
                    return (
                        <Link className={`rounded-xl border p-4 transition hover:-translate-y-0.5 hover:shadow-sm ${item.tone}`} key={String(item.code)} to={`/projects?${projectFilters.toString()}`}>
                            <p className="text-xs font-bold">{item.label}</p>
                            <p className="mt-2 text-2xl font-black tabular-nums">{count.toLocaleString('th-TH')}</p>
                        </Link>
                    );
                })}
            </div>
        </section>
    );
}

function DepartmentBudgetSection({ departments }: { departments: DashboardDepartmentBudget[] }) {
    return (
        <section className="space-y-4" aria-labelledby="department-budget-heading">
            <div>
                <h2 className="text-xl font-bold text-slate-950" id="department-budget-heading">งบประมาณรายฝ่าย</h2>
                <p className="mt-1 text-sm text-slate-500">งบที่จัดสรร แผนโครงการ ยอดใช้จริง และยอดคงเหลือของแต่ละฝ่าย</p>
            </div>

            {!departments.length ? (
                <EmptyState description="ยังไม่มีฝ่ายหรือข้อมูลงบประมาณสำหรับปีที่เลือก" title="ยังไม่มีข้อมูลงบรายฝ่าย" />
            ) : (
                <div className="grid gap-4 xl:grid-cols-2">
                    {departments.map((department) => <DepartmentBudgetCard budget={department} key={department.department.id} />)}
                </div>
            )}
        </section>
    );
}

function DepartmentBudgetCard({ budget }: { budget: DashboardDepartmentBudget }) {
    const scale = comparativeBarScale(budget.allocated_budget, budget.planned_project_budget, budget.actual_spent);
    const remaining = amountValue(budget.remaining);

    return (
        <article className={`spa-card overflow-hidden ${budget.overspent || budget.overcommitted ? 'border-rose-200' : ''}`}>
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h3 className="font-bold text-slate-950">{budget.department.name}</h3>
                    <p className="mt-1 text-xs text-slate-500">{budget.is_allocated ? 'ยืนยันการจัดสรรแล้ว' : 'ยังไม่ยืนยันการจัดสรร'}</p>
                </div>
                <div className="flex flex-wrap justify-end gap-1.5">
                    {budget.overcommitted && <WarningBadge compact label="แผนเกินวงเงิน" />}
                    {budget.overspent && <WarningBadge compact label="ใช้จริงเกินวงเงิน" />}
                </div>
            </div>
            <div className="p-5">
                <dl className="grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-4">
                    <BudgetDefinition label="จัดสรร" value={formatCurrency(budget.allocated_budget)} />
                    <BudgetDefinition label="แผนโครงการ" tone={budget.overcommitted ? 'danger' : 'default'} value={formatCurrency(budget.planned_project_budget)} />
                    <BudgetDefinition label="ใช้จริง" tone={budget.overspent ? 'danger' : 'default'} value={formatCurrency(budget.actual_spent)} />
                    <BudgetDefinition label="คงเหลือ" tone={remaining < 0 ? 'danger' : 'default'} value={formatCurrency(budget.remaining)} />
                </dl>

                <div
                    aria-label={`ฝ่าย ${budget.department.name}: จัดสรร ${formatCurrency(budget.allocated_budget)}, แผนโครงการ ${formatCurrency(budget.planned_project_budget)}, ใช้จริง ${formatCurrency(budget.actual_spent)}`}
                    className="mt-6 space-y-3"
                    role="img"
                >
                    <DepartmentBar color="bg-cyan-500" label="แผนโครงการ" marker={scale.allocated} value={scale.planned} />
                    <DepartmentBar color={budget.overspent ? 'bg-rose-500' : 'bg-teal-600'} label="ใช้จริง" marker={scale.allocated} value={scale.actual} />
                </div>

                <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-4 text-xs font-semibold">
                    <span className={budget.overspent ? 'text-rose-700' : 'text-slate-600'}>ใช้ไป {formatPercentage(budget.used_percentage)}</span>
                    <span className={remaining < 0 ? 'text-rose-700' : 'text-slate-600'}>คงเหลือ {formatPercentage(budget.remaining_percentage)}</span>
                </div>
            </div>
        </article>
    );
}

function DepartmentBar({ label, value, marker, color }: { label: string; value: number; marker: number; color: string }) {
    const valueStyle: CSSProperties = { width: `${value}%` };
    const markerStyle: CSSProperties = { left: `${marker}%` };

    return (
        <div>
            <div className="mb-1.5 flex items-center justify-between text-[11px] font-semibold text-slate-500">
                <span>{label}</span>
                <span>เส้นประ = วงเงินจัดสรร</span>
            </div>
            <div className="relative h-3 overflow-visible rounded-full bg-slate-100">
                <div className={`h-full rounded-full ${color}`} style={valueStyle} />
                <span className="absolute -top-1 h-5 border-l-2 border-dashed border-slate-700" style={markerStyle} />
            </div>
        </div>
    );
}

function ComparativeTrack({ budget, actual, actualLabel, danger }: { budget: number; actual: number; actualLabel: string; danger: boolean }) {
    const scale = comparativeBarScale(budget, 0, actual);

    return (
        <div>
            <div className="mb-2 flex justify-between gap-3 text-xs font-semibold text-slate-600">
                <span>{actualLabel} {formatCurrency(actual)}</span>
                <span>งบรวม {formatCurrency(budget)}</span>
            </div>
            <div className="relative h-3 rounded-full bg-slate-100">
                <div className={`h-full rounded-full ${danger ? 'bg-rose-500' : 'bg-teal-600'}`} style={{ width: `${scale.actual}%` }} />
                <span className="absolute -top-1 h-5 border-l-2 border-dashed border-slate-700" style={{ left: `${scale.allocated}%` }} />
            </div>
        </div>
    );
}

function BudgetDefinition({ label, value, tone = 'default' }: { label: string; value: string; tone?: 'default' | 'danger' }) {
    return (
        <div>
            <dt className="text-xs font-medium text-slate-500">{label}</dt>
            <dd className={`mt-1 break-words text-sm font-bold tabular-nums ${tone === 'danger' ? 'text-rose-700' : 'text-slate-900'}`}>{value}</dd>
        </div>
    );
}

function WarningBadge({ label, compact = false }: { label: string; compact?: boolean }) {
    return <span className={`inline-flex rounded-full bg-rose-100 font-bold text-rose-800 ring-1 ring-inset ring-rose-200 ${compact ? 'px-2.5 py-1 text-[11px]' : 'px-3 py-1.5 text-xs'}`}>{label}</span>;
}
