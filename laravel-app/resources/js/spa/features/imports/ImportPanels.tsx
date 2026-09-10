import type {
    ImportExtractionSnapshot,
    ImportPreviewRevision,
    ImportWarning,
} from '@/api/contracts';
import { WarningIcon } from '@/components/Icons';
import {
    confidencePercentage,
    formatImportDateTime,
    warningMessage,
} from '@/features/imports/model';

const fieldLabels: Record<string, string> = {
    name: 'ชื่อโครงการ',
    fiscal_year_id: 'ปีงบประมาณ',
    department_id: 'ฝ่าย/กลุ่มงาน',
    school_plan_id: 'แผนโรงเรียน',
    project_category_id: 'ประเภทโครงการ',
    academic_year_id: 'ปีการศึกษา',
    responsible_person: 'ผู้รับผิดชอบ',
    monitor_person: 'ผู้ติดตามโครงการ',
    start_date: 'วันที่เริ่ม',
    end_date: 'วันที่สิ้นสุด',
    budget: 'งบประมาณ',
    objective: 'วัตถุประสงค์',
    key_points: 'ประเด็นสำคัญ',
    evaluation_method: 'วิธีประเมิน',
    evaluation_tools: 'เครื่องมือประเมิน',
    indicators: 'ตัวชี้วัด',
};

export function ConfidenceBadge({ value }: { value: number | null | undefined }) {
    const percentage = confidencePercentage(value);
    if (percentage === null) return null;

    const tone = percentage >= 80
        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
        : percentage >= 60
            ? 'bg-amber-50 text-amber-800 ring-amber-200'
            : 'bg-rose-50 text-rose-700 ring-rose-200';

    return (
        <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset ${tone}`}>
            AI {percentage}%
        </span>
    );
}

export function ImportWarnings({ warnings, title = 'ข้อควรตรวจสอบจาก AI' }: { warnings: ImportWarning[]; title?: string }) {
    if (!warnings.length) return null;

    return (
        <section className="rounded-2xl border border-amber-200 bg-amber-50 p-5" role="status">
            <div className="flex items-start gap-3">
                <WarningIcon className="mt-0.5 size-5 shrink-0 text-amber-700" />
                <div>
                    <h2 className="font-bold text-amber-950">{title}</h2>
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-sm leading-6 text-amber-900">
                        {warnings.map((warning, index) => (
                            <li key={`${typeof warning === 'string' ? warning : `${warning.code ?? ''}-${warning.field ?? ''}-${warning.message}`}-${index}`}>
                                {typeof warning !== 'string' && warning.field && <span className="font-semibold">{fieldLabels[warning.field] ?? warning.field}: </span>}
                                {warningMessage(warning)}
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </section>
    );
}

export function OriginalExtractionPanel({ snapshot }: { snapshot: ImportExtractionSnapshot | null }) {
    if (!snapshot) {
        return (
            <section className="spa-card p-5 sm:p-7">
                <h2 className="text-lg font-bold text-slate-900">ผลอ่านต้นฉบับจาก AI</h2>
                <p className="mt-2 text-sm leading-6 text-slate-500">ยังไม่มีผลอ่านต้นฉบับสำหรับรายการนี้</p>
            </section>
        );
    }

    const values = snapshot.payload ?? snapshot.values ?? {};
    const entries = Object.entries(values);
    const confidenceEntries = Object.entries(snapshot.confidence ?? {});

    return (
        <section className="spa-card p-5 sm:p-7">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-lg font-bold text-slate-900">ผลอ่านต้นฉบับจาก AI</h2>
                    <p className="mt-1 text-sm leading-6 text-slate-500">ข้อมูลส่วนนี้เป็น snapshot แบบอ่านอย่างเดียว การแก้ไขจะสร้าง preview revision ใหม่และไม่เขียนทับผลต้นฉบับ</p>
                </div>
                {snapshot.created_at && <span className="text-xs text-slate-500">{formatImportDateTime(snapshot.created_at)}</span>}
            </div>

            {snapshot.warnings && <div className="mt-5"><ImportWarnings title="คำเตือนในผลอ่านต้นฉบับ" warnings={snapshot.warnings} /></div>}

            {entries.length > 0 ? (
                <dl className="mt-6 grid gap-4 md:grid-cols-2">
                    {entries.map(([field, value]) => (
                        <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3" key={field}>
                            <dt className="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-500">
                                {fieldLabels[field] ?? field}
                                <ConfidenceBadge value={snapshot.confidence?.[field]} />
                            </dt>
                            <dd className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-800">{formatSnapshotValue(value)}</dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <p className="mt-5 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-500">ผลอ่านต้นฉบับไม่มีค่าฟิลด์ที่แสดงได้</p>
            )}

            {confidenceEntries.length > 0 && entries.length === 0 && (
                <div className="mt-5 flex flex-wrap gap-2">
                    {confidenceEntries.map(([field, value]) => <span className="inline-flex items-center gap-2 text-xs text-slate-600" key={field}>{fieldLabels[field] ?? field}<ConfidenceBadge value={value} /></span>)}
                </div>
            )}
        </section>
    );
}

export function RevisionHistory({ revisions, currentRevisionId }: { revisions: ImportPreviewRevision[]; currentRevisionId?: number | null }) {
    const sorted = [...revisions].sort((left, right) => right.revision_no - left.revision_no);

    return (
        <section className="spa-card p-5 sm:p-7">
            <div>
                <h2 className="text-lg font-bold text-slate-900">ประวัติ Preview revisions</h2>
                <p className="mt-1 text-sm leading-6 text-slate-500">ทุกการบันทึกเพิ่ม revision ใหม่ รายการเดิมไม่ถูกแก้ไขหรือลบ</p>
            </div>

            {sorted.length === 0 ? (
                <p className="mt-5 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-500">ยังไม่มี revision</p>
            ) : (
                <ol className="mt-5 space-y-3">
                    {sorted.map((revision) => {
                        const errorCount = Object.values(revision.validation_errors).reduce((total, messages) => total + messages.length, 0);
                        return (
                            <li className={`rounded-xl border p-4 ${revision.id === currentRevisionId ? 'border-teal-300 bg-teal-50/60' : 'border-slate-200 bg-white'}`} key={revision.id}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-bold text-slate-900">Revision {revision.revision_no}</p>
                                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{revision.source === 'ai' ? 'AI' : 'ผู้ใช้'}</span>
                                            {revision.id === currentRevisionId && <span className="rounded-full bg-teal-100 px-2 py-0.5 text-[11px] font-bold text-teal-800">ฉบับปัจจุบัน</span>}
                                        </div>
                                        <p className="mt-1 text-xs text-slate-500">{revision.edited_by?.name ?? (revision.source === 'ai' ? 'ระบบ AI' : 'ไม่ระบุผู้แก้ไข')} · {formatImportDateTime(revision.created_at)}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-2 text-xs">
                                        {errorCount > 0 && <span className="rounded-full bg-rose-50 px-2.5 py-1 font-semibold text-rose-700">ผิดพลาด {errorCount}</span>}
                                        {revision.warnings.length > 0 && <span className="rounded-full bg-amber-50 px-2.5 py-1 font-semibold text-amber-800">คำเตือน {revision.warnings.length}</span>}
                                        {errorCount === 0 && revision.warnings.length === 0 && <span className="rounded-full bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700">พร้อมตรวจยืนยัน</span>}
                                    </div>
                                </div>
                                <RevisionSnapshot revision={revision} />
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}

function RevisionSnapshot({ revision }: { revision: ImportPreviewRevision }) {
    const payloadEntries = Object.entries(revision.raw_payload ?? revision.payload);
    const validationEntries = Object.entries(revision.validation_errors)
        .filter(([, messages]) => messages.length > 0);

    return (
        <details className="mt-4 border-t border-slate-200 pt-3">
            <summary className="cursor-pointer text-sm font-semibold text-teal-700 hover:text-teal-800">
                ดูข้อมูลแบบอ่านอย่างเดียวของ Revision {revision.revision_no}
            </summary>
            <div className="mt-4 space-y-4">
                <dl className="grid gap-3 md:grid-cols-2">
                    {payloadEntries.map(([field, value]) => (
                        <div className="rounded-xl border border-slate-200 bg-white px-4 py-3" key={field}>
                            <dt className="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-500">
                                {fieldLabels[field] ?? field}
                                <ConfidenceBadge value={revision.confidence[field]} />
                            </dt>
                            <dd className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-800">
                                {formatSnapshotValue(value)}
                            </dd>
                        </div>
                    ))}
                </dl>

                {validationEntries.length > 0 && (
                    <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                        <p className="text-sm font-bold text-rose-900">ข้อมูลที่ต้องแก้ไข</p>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm leading-6 text-rose-800">
                            {validationEntries.flatMap(([field, messages]) => messages.map((message, index) => (
                                <li key={`${field}-${index}-${message}`}>
                                    <span className="font-semibold">{fieldLabels[field] ?? field}: </span>{message}
                                </li>
                            )))}
                        </ul>
                    </div>
                )}

                <ImportWarnings title={`คำเตือนของ Revision ${revision.revision_no}`} warnings={revision.warnings} />
            </div>
        </details>
    );
}

const formatSnapshotValue = (value: unknown): string => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return String(value);

    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return 'ไม่สามารถแสดงค่าได้';
    }
};
