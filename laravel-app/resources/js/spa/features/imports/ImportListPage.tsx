import { useQuery } from '@tanstack/react-query';
import { Link, Navigate, useSearchParams } from 'react-router-dom';

import { isApiError } from '@/api/client';
import type { DocumentImport, DocumentImportFilters, DocumentImportStatus } from '@/api/contracts';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, LoadingBlock } from '@/components/Feedback';
import { DocumentIcon, PlusIcon } from '@/components/Icons';
import { PageHeader } from '@/components/PageHeader';
import { Pagination } from '@/components/Pagination';
import { fetchDocumentImports, importKeys } from '@/features/imports/api';
import { ImportStatusBadge } from '@/features/imports/ImportStatusBadge';
import {
    formatFileSize,
    formatImportDateTime,
    importStageLabel,
    importStatusMeta,
    shouldPollDocumentImport,
} from '@/features/imports/model';

const statuses = Object.keys(importStatusMeta) as DocumentImportStatus[];

const filtersFromParams = (params: URLSearchParams): DocumentImportFilters => {
    const status = params.get('status');
    const page = Number(params.get('page') ?? '1');

    return {
        status: statuses.includes(status as DocumentImportStatus) ? status as DocumentImportStatus : '',
        page: Number.isInteger(page) && page > 0 ? page : 1,
        per_page: 15,
    };
};

export function ImportListPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const { hasPermission } = useAuth();
    const filters = filtersFromParams(searchParams);
    const importsQuery = useQuery({
        queryKey: importKeys.list(filters),
        queryFn: () => fetchDocumentImports(filters),
        refetchInterval: (query) => query.state.data?.data.some((documentImport) => shouldPollDocumentImport(documentImport)) ? 3_000 : false,
    });

    if (isApiError(importsQuery.error) && importsQuery.error.status === 403) {
        return <Navigate replace to="/forbidden" />;
    }

    const changeStatus = (status: string) => {
        const next = new URLSearchParams();
        if (status) next.set('status', status);
        setSearchParams(next);
    };

    const changePage = (page: number) => {
        const next = new URLSearchParams(searchParams);
        if (page <= 1) next.delete('page');
        else next.set('page', String(page));
        setSearchParams(next);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    return (
        <div className="space-y-7">
            <PageHeader
                actions={hasPermission('imports.create') ? (
                    <Link className="spa-button-primary" to="/imports/new"><PlusIcon className="size-4" />นำเข้า PDF</Link>
                ) : undefined}
                description="ติดตามไฟล์ต้นฉบับ การประมวลผล AI และรายการที่รอตรวจสอบก่อนสร้างโครงการ"
                eyebrow="PDF / AI Project Import"
                title="นำเข้าโครงการจากเอกสาร"
            />

            <section aria-label="ตัวกรองรายการนำเข้า" className="spa-card flex flex-col gap-4 p-4 sm:flex-row sm:items-end sm:justify-between sm:p-5">
                <label className="w-full sm:max-w-xs">
                    <span className="spa-label">สถานะ</span>
                    <select className="spa-input" onChange={(event) => changeStatus(event.target.value)} value={filters.status ?? ''}>
                        <option value="">ทุกสถานะ</option>
                        {statuses.map((status) => <option key={status} value={status}>{importStatusMeta[status].label}</option>)}
                    </select>
                </label>
                <p className="text-xs leading-5 text-slate-500">ข้อมูลที่ AI อ่านได้ยังไม่ใช่ข้อมูลโครงการจนกว่าผู้ใช้จะตรวจและกดยืนยัน</p>
            </section>

            {importsQuery.isPending && <LoadingBlock label="กำลังโหลดรายการนำเข้า" />}

            {importsQuery.isError && (
                <ErrorState
                    action={<button className="spa-button-primary" onClick={() => void importsQuery.refetch()} type="button">ลองใหม่</button>}
                    message={isApiError(importsQuery.error) ? importsQuery.error.message : 'ไม่สามารถโหลดรายการนำเข้าได้'}
                    title="โหลดรายการไม่สำเร็จ"
                />
            )}

            {importsQuery.data?.data.length === 0 && (
                <EmptyState
                    action={hasPermission('imports.create') ? <Link className="spa-button-primary" to="/imports/new">อัปโหลด PDF แรก</Link> : undefined}
                    description={filters.status ? 'ไม่มีรายการในสถานะที่เลือก' : 'อัปโหลดเอกสาร PDF เพื่อเริ่มกระบวนการอ่านข้อความ ตรวจสอบ และยืนยันโครงการ'}
                    title="ยังไม่มีรายการนำเข้า"
                />
            )}

            {importsQuery.data && importsQuery.data.data.length > 0 && (
                <section aria-label="รายการนำเข้าโครงการ" className="spa-card overflow-hidden">
                    <div className="hidden overflow-x-auto lg:block">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr className="text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                                    <th className="px-6 py-3.5">เอกสารต้นฉบับ</th>
                                    <th className="px-6 py-3.5">สถานะ</th>
                                    <th className="px-6 py-3.5">ผู้อัปโหลด</th>
                                    <th className="px-6 py-3.5">อัปโหลดเมื่อ</th>
                                    <th className="px-6 py-3.5 text-right">ดำเนินการ</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {importsQuery.data.data.map((documentImport) => (
                                    <ImportTableRow documentImport={documentImport} key={documentImport.public_id} />
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="divide-y divide-slate-100 lg:hidden">
                        {importsQuery.data.data.map((documentImport) => (
                            <ImportMobileCard documentImport={documentImport} key={documentImport.public_id} />
                        ))}
                    </div>

                    <Pagination
                        currentPage={importsQuery.data.meta.current_page}
                        from={importsQuery.data.meta.from}
                        lastPage={importsQuery.data.meta.last_page}
                        onPageChange={changePage}
                        to={importsQuery.data.meta.to}
                        total={importsQuery.data.meta.total}
                    />
                </section>
            )}
        </div>
    );
}

function ImportTableRow({ documentImport }: { documentImport: DocumentImport }) {
    return (
        <tr className="text-sm text-slate-700">
            <td className="max-w-md px-6 py-4">
                <div className="flex items-start gap-3">
                    <div className="grid size-10 shrink-0 place-items-center rounded-xl bg-rose-50 text-rose-700"><DocumentIcon className="size-5" /></div>
                    <div className="min-w-0">
                        <Link className="block truncate font-semibold text-slate-950 hover:text-teal-700" to={`/imports/${documentImport.public_id}`}>{documentImport.original.name}</Link>
                        <p className="mt-1 text-xs text-slate-500">{formatFileSize(documentImport.original.size_bytes)}</p>
                    </div>
                </div>
            </td>
            <td className="px-6 py-4">
                <ImportStatusBadge status={documentImport.status} />
                {importStageLabel(documentImport.processing_stage) && <p className="mt-1.5 text-xs text-slate-500">{importStageLabel(documentImport.processing_stage)}</p>}
            </td>
            <td className="px-6 py-4">
                <p className="font-medium text-slate-800">{documentImport.uploader?.name ?? '—'}</p>
                {documentImport.uploader_department && <p className="mt-1 text-xs text-slate-500">{documentImport.uploader_department.name}</p>}
            </td>
            <td className="whitespace-nowrap px-6 py-4 text-slate-600">{formatImportDateTime(documentImport.created_at)}</td>
            <td className="px-6 py-4 text-right"><ImportAction documentImport={documentImport} /></td>
        </tr>
    );
}

function ImportMobileCard({ documentImport }: { documentImport: DocumentImport }) {
    return (
        <article className="space-y-4 p-5">
            <div className="flex items-start gap-3">
                <div className="grid size-10 shrink-0 place-items-center rounded-xl bg-rose-50 text-rose-700"><DocumentIcon className="size-5" /></div>
                <div className="min-w-0 flex-1">
                    <Link className="block truncate font-bold text-slate-950" to={`/imports/${documentImport.public_id}`}>{documentImport.original.name}</Link>
                    <p className="mt-1 text-xs text-slate-500">{formatFileSize(documentImport.original.size_bytes)} · {formatImportDateTime(documentImport.created_at)}</p>
                </div>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <ImportStatusBadge status={documentImport.status} />
                <ImportAction documentImport={documentImport} />
            </div>
        </article>
    );
}

function ImportAction({ documentImport }: { documentImport: DocumentImport }) {
    if (documentImport.status === 'confirmed' && documentImport.confirmed_project) {
        return <Link className="spa-button-secondary min-h-10" to={documentImport.confirmed_project.url ?? `/projects/${documentImport.confirmed_project.id}`}>เปิดโครงการ</Link>;
    }

    if (documentImport.status === 'needs_review' && documentImport.current_preview) {
        return (
            <Link
                className={`${documentImport.abilities.review ? 'spa-button-primary' : 'spa-button-secondary'} min-h-10`}
                to={`/imports/${documentImport.public_id}/preview`}
            >
                {documentImport.abilities.review ? 'ตรวจสอบข้อมูล' : 'ดู Preview'}
            </Link>
        );
    }

    return <Link className="spa-button-secondary min-h-10" to={`/imports/${documentImport.public_id}`}>ดูรายละเอียด</Link>;
}
