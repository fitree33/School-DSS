import { ChevronLeftIcon, ChevronRightIcon } from '@/components/Icons';

export function Pagination({ currentPage, lastPage, from, to, total, onPageChange }: {
    currentPage: number;
    lastPage: number;
    from: number | null;
    to: number | null;
    total: number;
    onPageChange: (page: number) => void;
}) {
    if (total === 0) return null;

    return (
        <div className="flex flex-col gap-3 border-t border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <p className="text-sm text-slate-600">แสดง <span className="font-semibold text-slate-900">{from ?? 0}–{to ?? 0}</span> จาก {total.toLocaleString('th-TH')} รายการ</p>
            <div className="flex items-center gap-2">
                <button aria-label="หน้าก่อนหน้า" className="spa-button-secondary min-h-10 px-3" disabled={currentPage <= 1} onClick={() => onPageChange(currentPage - 1)} type="button">
                    <ChevronLeftIcon className="size-4" />
                </button>
                <span className="min-w-24 text-center text-sm font-medium text-slate-700">หน้า {currentPage} / {Math.max(lastPage, 1)}</span>
                <button aria-label="หน้าถัดไป" className="spa-button-secondary min-h-10 px-3" disabled={currentPage >= lastPage} onClick={() => onPageChange(currentPage + 1)} type="button">
                    <ChevronRightIcon className="size-4" />
                </button>
            </div>
        </div>
    );
}
