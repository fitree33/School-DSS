import type { ReactNode } from 'react';

export function LoadingBlock({ label = 'กำลังโหลดข้อมูล' }: { label?: string }) {
    return (
        <div className="spa-card flex min-h-56 items-center justify-center p-8" role="status">
            <div className="text-center">
                <div className="mx-auto size-9 animate-spin rounded-full border-4 border-slate-200 border-t-teal-700" />
                <p className="mt-4 text-sm font-medium text-slate-600">{label}</p>
            </div>
        </div>
    );
}

export function ErrorState({ title = 'เกิดข้อผิดพลาด', message, action }: { title?: string; message: string; action?: ReactNode }) {
    return (
        <div className="spa-card border-rose-200 bg-rose-50/50 p-7 text-center" role="alert">
            <div className="mx-auto grid size-11 place-items-center rounded-full bg-rose-100 text-xl font-bold text-rose-700">!</div>
            <h2 className="mt-4 text-base font-bold text-slate-900">{title}</h2>
            <p className="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">{message}</p>
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}

export function EmptyState({ title, description, action }: { title: string; description: string; action?: ReactNode }) {
    return (
        <div className="spa-card px-6 py-14 text-center">
            <div className="mx-auto grid size-14 place-items-center rounded-2xl bg-slate-100 text-2xl text-slate-500">—</div>
            <h2 className="mt-5 text-lg font-bold text-slate-900">{title}</h2>
            <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600">{description}</p>
            {action && <div className="mt-6">{action}</div>}
        </div>
    );
}

export function FieldError({ errors }: { errors?: string[] }) {
    if (!errors?.length) return null;
    return <p className="mt-1.5 text-xs font-medium text-rose-700">{errors[0]}</p>;
}
