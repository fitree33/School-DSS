import type { DocumentImportStatus } from '@/api/contracts';
import { importStatusMeta } from '@/features/imports/model';

const statusStyles: Record<DocumentImportStatus, string> = {
    uploaded: 'bg-slate-100 text-slate-700 ring-slate-200',
    processing: 'bg-sky-50 text-sky-700 ring-sky-200',
    needs_review: 'bg-amber-50 text-amber-800 ring-amber-200',
    confirmed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
};

export function ImportStatusBadge({ status }: { status: DocumentImportStatus }) {
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${statusStyles[status]}`}>
            {status === 'processing' && <span aria-hidden className="size-1.5 animate-pulse rounded-full bg-current" />}
            {importStatusMeta[status].label}
        </span>
    );
}
