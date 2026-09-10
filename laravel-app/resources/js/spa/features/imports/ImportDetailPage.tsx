import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, Navigate, useNavigate, useParams } from 'react-router-dom';

import { ApiError, isApiError } from '@/api/client';
import { ErrorState, LoadingBlock } from '@/components/Feedback';
import { ArrowLeftIcon, CheckCircleIcon, DocumentIcon, RefreshIcon } from '@/components/Icons';
import { OriginalDocumentDownloadButton } from '@/components/OriginalDocumentDownloadButton';
import { PageHeader } from '@/components/PageHeader';
import {
    fetchDocumentImport,
    fetchImportPreviewRevisions,
    importKeys,
    originalDocumentUrl,
    retryDocumentImport,
} from '@/features/imports/api';
import { ImportStatusBadge } from '@/features/imports/ImportStatusBadge';
import { OriginalExtractionPanel, RevisionHistory } from '@/features/imports/ImportPanels';
import {
    formatFileSize,
    formatImportDateTime,
    importStageLabel,
    importStatusMeta,
    shouldPollDocumentImport,
} from '@/features/imports/model';

export function ImportDetailPage() {
    const { importId = '' } = useParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    const importQuery = useQuery({
        queryKey: importKeys.detail(importId),
        queryFn: () => fetchDocumentImport(importId),
        enabled: importId !== '',
        refetchInterval: (query) => shouldPollDocumentImport(query.state.data) ? 2_000 : false,
    });

    const revisionsQuery = useQuery({
        queryKey: importKeys.revisions(importId),
        queryFn: () => fetchImportPreviewRevisions(importId),
        enabled: importId !== '' && Boolean(importQuery.data?.current_preview),
    });

    const retryMutation = useMutation({
        mutationFn: () => retryDocumentImport(importId),
        onSuccess: async (documentImport) => {
            queryClient.setQueryData(importKeys.detail(importId), documentImport);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: importKeys.lists() }),
                queryClient.invalidateQueries({ queryKey: importKeys.revisions(importId) }),
            ]);
        },
    });

    if (isApiError(importQuery.error) && importQuery.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (importQuery.isPending) return <LoadingBlock label="กำลังโหลดรายละเอียดรายการนำเข้า" />;
    if (importQuery.isError || !importQuery.data) {
        return (
            <ErrorState
                action={<Link className="spa-button-secondary" to="/imports">กลับรายการนำเข้า</Link>}
                message={isApiError(importQuery.error) ? importQuery.error.message : 'ไม่พบรายการนำเข้าที่ต้องการ'}
                title="โหลดรายละเอียดไม่สำเร็จ"
            />
        );
    }

    const documentImport = importQuery.data;
    const stageLabel = importStageLabel(documentImport.processing_stage);

    const handleRetry = async () => {
        if (retryMutation.isPending || documentImport.status !== 'failed' || !documentImport.abilities.retry) return;

        try {
            await retryMutation.mutateAsync();
        } catch (unknownError) {
            if (unknownError instanceof ApiError && unknownError.status === 403) {
                navigate('/forbidden', { replace: true });
            }
            if (unknownError instanceof ApiError && unknownError.status === 409) {
                await Promise.all([
                    importQuery.refetch(),
                    queryClient.invalidateQueries({ queryKey: importKeys.lists() }),
                ]);
            }
        }
    };

    const actions = (
        <>
            {documentImport.status === 'needs_review' && documentImport.current_preview && (
                <Link
                    className={documentImport.abilities.review ? 'spa-button-primary' : 'spa-button-secondary'}
                    to={`/imports/${documentImport.public_id}/preview`}
                >
                    {documentImport.abilities.review ? 'ตรวจสอบ Preview' : 'ดู Preview แบบอ่านอย่างเดียว'}
                </Link>
            )}
            {documentImport.status === 'confirmed' && documentImport.confirmed_project && (
                <Link className="spa-button-primary" to={`/projects/${documentImport.confirmed_project.id}`}><CheckCircleIcon className="size-4" />เปิดโครงการ</Link>
            )}
            {documentImport.status === 'failed' && documentImport.abilities.retry && (
                <button className="spa-button-primary" disabled={retryMutation.isPending} onClick={() => void handleRetry()} type="button">
                    <RefreshIcon className={`size-4 ${retryMutation.isPending ? 'animate-spin' : ''}`} />
                    {retryMutation.isPending ? 'กำลังเริ่มใหม่…' : 'ลองประมวลผลใหม่'}
                </button>
            )}
        </>
    );

    return (
        <div className="space-y-7">
            <Link className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-teal-700" to="/imports"><ArrowLeftIcon className="size-4" />กลับรายการนำเข้า</Link>

            <PageHeader
                actions={actions}
                description="ติดตามไฟล์ต้นฉบับ การประมวลผล ผลอ่าน AI และ preview revisions โดยยังไม่สร้างโครงการจนกว่าจะยืนยัน"
                eyebrow="Document import detail"
                title={documentImport.original.name}
            />

            {retryMutation.isError && (
                <ErrorState
                    message={isApiError(retryMutation.error) ? retryMutation.error.message : 'ไม่สามารถเริ่มประมวลผลใหม่ได้'}
                    title="ลองประมวลผลใหม่ไม่สำเร็จ"
                />
            )}

            <section className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.38fr)]">
                <article className="spa-card p-5 sm:p-7">
                    <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex min-w-0 items-start gap-4">
                            <div className="grid size-12 shrink-0 place-items-center rounded-2xl bg-rose-50 text-rose-700"><DocumentIcon className="size-6" /></div>
                            <div className="min-w-0">
                                <p className="break-words text-lg font-bold text-slate-950">{documentImport.original.name}</p>
                                <p className="mt-1 text-sm text-slate-500">{documentImport.original.mime_type} · {formatFileSize(documentImport.original.size_bytes)}</p>
                            </div>
                        </div>
                        {documentImport.abilities.view_original && (
                            <OriginalDocumentDownloadButton filename={documentImport.original.name} label="เปิดไฟล์ต้นฉบับ" url={originalDocumentUrl(documentImport)} />
                        )}
                    </div>

                    <dl className="mt-6 grid gap-4 border-t border-slate-100 pt-6 sm:grid-cols-2">
                        <Detail label="SHA-256" value={documentImport.original.sha256} mono />
                        <Detail label="อัปโหลดเมื่อ" value={formatImportDateTime(documentImport.created_at)} />
                        <Detail label="ผู้อัปโหลด" value={documentImport.uploader?.name ?? '—'} />
                        <Detail label="ฝ่าย/กลุ่มงาน" value={documentImport.uploader_department?.name ?? '—'} />
                    </dl>
                    <p className="mt-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm leading-6 text-sky-950">ไฟล์ต้นฉบับนี้เก็บไว้เป็นหลักฐานการนำเข้า การเพิ่มหรือเปลี่ยนเอกสารของโครงการภายหลังจะไม่เปลี่ยนไฟล์นี้</p>
                </article>

                <aside className="spa-card p-5 sm:p-6">
                    <p className="text-xs font-bold uppercase tracking-wide text-slate-500">สถานะปัจจุบัน</p>
                    <div className="mt-3"><ImportStatusBadge status={documentImport.status} /></div>
                    <p className="mt-3 text-sm leading-6 text-slate-600">{importStatusMeta[documentImport.status].description}</p>
                    {stageLabel && <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">{stageLabel}</p>}
                    {shouldPollDocumentImport(documentImport) && <p className="mt-3 text-xs text-sky-700" role="status">หน้านี้กำลังอัปเดตสถานะอัตโนมัติทุก 2 วินาที</p>}

                    {documentImport.latest_run && (
                        <dl className="mt-5 space-y-3 border-t border-slate-100 pt-5 text-sm">
                            <Detail label="ครั้งประมวลผล" value={`ครั้งที่ ${documentImport.latest_run.attempt_no}`} />
                            <Detail label="ผู้ให้บริการ" value={documentImport.latest_run.provider} />
                            <Detail label="สถานะ Run" value={documentImport.latest_run.status} />
                            <Detail label="เสร็จเมื่อ" value={formatImportDateTime(documentImport.latest_run.finished_at)} />
                        </dl>
                    )}
                </aside>
            </section>

            {documentImport.failure && (
                <section className="rounded-2xl border border-rose-200 bg-rose-50 p-5" role="alert">
                    <h2 className="font-bold text-rose-950">ประมวลผลไม่สำเร็จ</h2>
                    <p className="mt-2 text-sm leading-6 text-rose-900">{documentImport.failure.message}</p>
                    <div className="mt-3 flex flex-wrap gap-2 text-xs font-semibold text-rose-800">
                        <span className="rounded-full bg-white/70 px-2.5 py-1">code: {documentImport.failure.code}</span>
                        {documentImport.failure.stage && <span className="rounded-full bg-white/70 px-2.5 py-1">stage: {documentImport.failure.stage}</span>}
                    </div>
                </section>
            )}

            <OriginalExtractionPanel snapshot={documentImport.original_extraction} />

            {documentImport.current_preview && (
                revisionsQuery.isError ? (
                    <ErrorState
                        action={<button className="spa-button-secondary" onClick={() => void revisionsQuery.refetch()} type="button">ลองโหลดประวัติใหม่</button>}
                        message={isApiError(revisionsQuery.error) ? revisionsQuery.error.message : 'ไม่สามารถโหลดประวัติ revision ได้'}
                        title="โหลดประวัติ Preview ไม่สำเร็จ"
                    />
                ) : revisionsQuery.isPending ? (
                    <LoadingBlock label="กำลังโหลดประวัติ Preview revisions" />
                ) : (
                    <RevisionHistory currentRevisionId={documentImport.current_preview.id} revisions={revisionsQuery.data ?? []} />
                )
            )}
        </div>
    );
}

function Detail({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs font-bold text-slate-500">{label}</dt>
            <dd className={`mt-1 break-all text-sm text-slate-800 ${mono ? 'font-mono text-xs' : ''}`}>{value}</dd>
        </div>
    );
}
