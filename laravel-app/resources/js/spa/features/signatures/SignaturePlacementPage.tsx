import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, Navigate, useParams } from 'react-router-dom';

import { isApiError } from '@/api/client';
import { ErrorState, LoadingBlock } from '@/components/Feedback';
import { PageHeader } from '@/components/PageHeader';
import { deletePlacement, fetchPlacementContext, placementKeys, savePlacement } from '@/features/signatures/api';
import { PdfViewer } from '@/features/signatures/PdfViewer';
import { createClickPlacement, eligibleAssets, placementPayload, restorePlacementDraft, slotLabels } from '@/features/signatures/placement';
import type { PlacementContext, PlacementRoute, PlacementSlot, SavePlacementPayload, SignatureAsset, SignaturePlacement } from '@/features/signatures/types';

export function SignaturePlacementPage() {
    const { projectId = '', documentId = '', versionId = '' } = useParams();
    const route = { projectId, documentId, versionId };
    const [reload, setReload] = useState(0);
    const query = useQuery({
        queryKey: placementKeys.context(route),
        queryFn: () => fetchPlacementContext(route),
        enabled: Boolean(projectId && documentId && versionId),
        refetchOnWindowFocus: false,
        refetchOnReconnect: false,
    });

    if (isApiError(query.error) && query.error.status === 403) return <Navigate replace to="/forbidden" />;
    if (query.isPending) return <LoadingBlock label="กำลังโหลดเอกสารและตำแหน่งลายเซ็น" />;
    if (query.isError || !query.data) {
        return <ErrorState action={<Link className="spa-button-secondary" to={`/projects/${projectId}`}>กลับโครงการ</Link>} title="โหลดเอกสารไม่สำเร็จ" message={query.error instanceof Error ? query.error.message : 'ไม่พบเอกสารฉบับนี้'} />;
    }

    return <PlacementEditor context={query.data} key={`${versionId}:${reload}`} onReload={async () => { const result = await query.refetch(); if (result.isSuccess) setReload((value) => value + 1); }} route={route} />;
}

function PlacementEditor({ context, route, onReload }: { context: PlacementContext; route: PlacementRoute; onReload: () => Promise<void> }) {
    const queryClient = useQueryClient();
    const [slotId, setSlotId] = useState<number | null>(() => context.slots.find((slot) => slot.can_sign)?.id ?? null);
    const slot = context.slots.find((candidate) => candidate.id === slotId);
    const [draft, setDraft] = useState(() => restorePlacementDraft(slot, context.assets));
    const [page, setPage] = useState(() => draft.position?.page ?? 1);
    const [pageCount, setPageCount] = useState(context.version.page_count ?? 0);
    const [notice, setNotice] = useState('');
    const assets = eligibleAssets(context.assets);
    const asset = assets.find((candidate) => candidate.public_id === draft.assetId);

    const updateSaved = (updatedSlotId: number, placement: SignaturePlacement | null) => {
        queryClient.setQueryData<PlacementContext>(placementKeys.context(route), (current) => current ? {
            ...current,
            slots: current.slots.map((candidate) => candidate.id === updatedSlotId ? { ...candidate, placement } : candidate),
        } : current);
    };
    const save = useMutation({
        mutationFn: ({ selectedSlotId, payload }: { selectedSlotId: number; payload: SavePlacementPayload }) => savePlacement(route, selectedSlotId, payload),
        onSuccess: (saved, { selectedSlotId }) => {
            updateSaved(selectedSlotId, saved);
            setDraft(restorePlacementDraft(slot ? { ...slot, placement: saved } : undefined, context.assets));
            setNotice('บันทึกตำแหน่งแล้ว');
        },
    });
    const reset = useMutation({
        mutationFn: (selectedSlotId: number) => deletePlacement(route, selectedSlotId),
        onSuccess: (_, selectedSlotId) => {
            updateSaved(selectedSlotId, null);
            setDraft(restorePlacementDraft(slot ? { ...slot, placement: null } : undefined, context.assets));
            setNotice('ลบตำแหน่งที่บันทึกแล้ว');
        },
    });
    const busy = save.isPending || reset.isPending;
    const error = save.error ?? reset.error;
    const payload = placementPayload(draft, slot, assets, pageCount);
    const clearFeedback = () => { setNotice(''); save.reset(); reset.reset(); };

    return (
        <div className="space-y-5">
            <Link className="text-sm font-semibold text-teal-700" to={`/projects/${route.projectId}`}>กลับโครงการ</Link>
            <PageHeader title={context.document.original_name} description={`ฉบับที่ ${context.version.revision_no} · กำหนดตำแหน่งลายเซ็นแบบร่าง`} />
            <p className="text-sm text-slate-600">เลือกช่องลายเซ็นและลายเซ็นของคุณ จากนั้นคลิกบนหน้า PDF เพื่อวางกรอบ คลิกอีกครั้งเพื่อย้ายตำแหน่ง</p>
            <section className="spa-card space-y-4 p-5" aria-label="เลือกช่องและลายเซ็น">
                <PlacementSelectors assets={assets} assetId={draft.assetId} disabled={busy} onAssetChange={(assetId) => {
                    if (assetId && !assets.some((candidate) => candidate.public_id === assetId)) return;
                    setDraft((current) => ({ ...current, assetId, position: null }));
                    clearFeedback();
                }} onSlotChange={(nextSlotId) => {
                    const next = context.slots.find((candidate) => candidate.id === nextSlotId && candidate.can_sign);
                    if (!next) return;
                    const nextDraft = restorePlacementDraft(next, assets);
                    setSlotId(next.id);
                    setDraft(nextDraft);
                    if (nextDraft.position) setPage(nextDraft.position.page);
                    clearFeedback();
                }} slotId={slotId} slots={context.slots} />
                {!context.slots.some((candidate) => candidate.can_sign) && <p className="text-sm text-slate-600">บัญชีนี้ไม่ได้เป็นผู้ลงนามที่มีสิทธิ์ในช่องใด สามารถดูเอกสารได้</p>}
                {slot?.can_sign && assets.length === 0 && <p className="text-sm text-amber-800">คุณยังไม่มีลายเซ็นที่ใช้งานได้ กรุณาเพิ่มลายเซ็นก่อนกำหนดตำแหน่ง</p>}
                {slot?.placement?.stale && <p className="text-sm text-amber-800">ตำแหน่งเดิมใช้ไม่ได้แล้ว เนื่องจากผู้ลงนามหรือลายเซ็นเปลี่ยน กรุณาเลือกและวางใหม่</p>}
            </section>
            <PdfViewer onDocumentLoad={setPageCount} onPageChange={setPage} onPlace={slot?.can_sign && asset && !busy ? (point, pageSize) => {
                const position = createClickPlacement(point, pageSize, asset, page, draft.position);
                if (position) setDraft((current) => ({ ...current, position }));
                clearFeedback();
            } : undefined} page={page} placement={draft.position} url={context.version.download_url} />
            <section className="spa-card space-y-3 p-5" aria-label="บันทึกตำแหน่งลายเซ็น">
                {draft.position && <p className="text-sm text-slate-600">ตำแหน่งที่เลือก: หน้า {draft.position.page} · ซ้าย {(draft.position.x * 100).toFixed(1)}% · บน {(draft.position.y * 100).toFixed(1)}%</p>}
                <div className="flex flex-wrap gap-3">
                    <button className="spa-button-primary" disabled={!payload || busy} onClick={() => { if (payload && slotId !== null) { setNotice(''); save.mutate({ selectedSlotId: slotId, payload }); } }} type="button">{save.isPending ? 'กำลังบันทึก…' : 'บันทึกตำแหน่ง'}</button>
                    <button className="spa-button-secondary" disabled={!slot?.can_sign || !slot.placement || busy} onClick={() => { if (slotId !== null) { setNotice(''); reset.mutate(slotId); } }} type="button">{reset.isPending ? 'กำลังลบ…' : 'ลบตำแหน่งที่บันทึก'}</button>
                </div>
                <p className="text-xs text-slate-500">การบันทึกนี้เป็นแบบร่าง ยังไม่ได้ประทับลายเซ็นลงใน PDF</p>
                {notice && <p className="text-sm text-teal-800" role="status">{notice}</p>}
                {error && <div role="alert" className="space-y-2 text-sm text-rose-700">
                    <p>{error instanceof Error ? error.message : 'บันทึกตำแหน่งไม่สำเร็จ'}</p>
                    {isApiError(error) && Object.entries(error.errors).map(([field, messages]) => <p key={field}>{messages.join(' ')}</p>)}
                    <button className="spa-button-secondary" disabled={busy} onClick={() => void onReload()} type="button">โหลดข้อมูลล่าสุดและเริ่มใหม่</button>
                </div>}
            </section>
        </div>
    );
}

export function PlacementSelectors({ slots, assets, slotId, assetId, disabled, onSlotChange, onAssetChange }: {
    slots: PlacementSlot[];
    assets: SignatureAsset[];
    slotId: number | null;
    assetId: string;
    disabled: boolean;
    onSlotChange: (id: number) => void;
    onAssetChange: (id: string) => void;
}) {
    const canSign = slots.some((slot) => slot.id === slotId && slot.can_sign);
    return <div className="grid gap-4 md:grid-cols-2">
        <label className="space-y-2 text-sm font-semibold text-slate-700">ช่องลายเซ็น
            <select className="spa-input w-full" disabled={disabled} onChange={(event) => onSlotChange(Number(event.target.value))} value={slotId ?? ''}>
                <option disabled value="">เลือกช่องลายเซ็น</option>
                {slots.map((slot) => <option disabled={!slot.can_sign} key={slot.id} value={slot.id}>{slot.slot_no}. {slotLabels[slot.slot_code]} · {slot.assignee?.name ?? 'ยังไม่กำหนดผู้ลงนาม'}{slot.can_sign ? '' : ' (ไม่มีสิทธิ์วางลายเซ็น)'}</option>)}
            </select>
        </label>
        <label className="space-y-2 text-sm font-semibold text-slate-700">ลายเซ็นของคุณที่ใช้งานได้
            <select className="spa-input w-full" disabled={disabled || !canSign} onChange={(event) => onAssetChange(event.target.value)} value={assetId}>
                <option value="">เลือกลายเซ็น</option>
                {eligibleAssets(assets).map((asset, index) => <option key={asset.public_id} value={asset.public_id}>ลายเซ็น {index + 1} · {asset.width} × {asset.height} px · {asset.public_id.slice(0, 8)}</option>)}
            </select>
        </label>
    </div>;
}
