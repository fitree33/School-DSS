import type { ReactNode } from 'react';

export function PageHeader({ eyebrow, title, description, actions }: { eyebrow?: string; title: string; description?: string; actions?: ReactNode }) {
    return (
        <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                {eyebrow && <p className="text-xs font-bold uppercase tracking-[0.16em] text-teal-700">{eyebrow}</p>}
                <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">{title}</h1>
                {description && <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
    );
}
