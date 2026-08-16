const statusStyles: Record<string, string> = {
    not_started: 'bg-slate-100 text-slate-700 ring-slate-200',
    in_progress: 'bg-sky-50 text-sky-700 ring-sky-200',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    pending: 'bg-amber-50 text-amber-800 ring-amber-200',
    passed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
};

export function StatusBadge({ code, label }: { code?: string | null; label?: string | null }) {
    if (!label) return <span className="text-sm text-slate-400">—</span>;
    const style = statusStyles[code ?? ''] ?? 'bg-slate-100 text-slate-700 ring-slate-200';
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${style}`}>{label}</span>;
}
