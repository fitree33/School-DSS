import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiError, apiClient } from '@/api/client';
import { projectKeys } from '@/features/projects/api';
import { placementKeys } from '@/features/signatures/api';
import * as placement from '@/features/signatures/placement';
import { PlacementError, PlacementSelectors, SignDocumentButton, SignaturePlacementPage } from '@/features/signatures/SignaturePlacementPage';
import type { DocumentSignature, PlacementContext, SignaturePlacement } from '@/features/signatures/types';

const rendered = vi.hoisted(() => ({
    selects: [] as Array<{ value: number | string; onChange: (event: { target: { value: string } }) => void }>,
    save: null as null | { disabled: boolean; onClick: () => void },
    reset: null as null | { disabled: boolean; onClick: () => void },
    sign: null as null | { disabled: boolean; onClick: () => void },
    navigate: vi.fn(),
    viewer: null as null | { url: string; page: number; placement: SignaturePlacement | null; onPlace?: (point: { x: number; y: number }, size: { width: number; height: number }) => void },
}));
vi.mock('react/jsx-runtime', async (importOriginal) => {
    const runtime = await importOriginal<typeof import('react/jsx-runtime')>();
    const capture = (type: unknown, props: unknown) => {
        if (type === 'select') rendered.selects.push(props as typeof rendered.selects[number]);
        if (type === 'button' && (props as { children: unknown }).children === 'บันทึกตำแหน่ง') rendered.save = props as typeof rendered.save;
        if (type === 'button' && (props as { children: unknown }).children === 'ลบตำแหน่งที่บันทึก') rendered.reset = props as typeof rendered.reset;
        if (type === 'button' && (props as { 'data-action'?: string })['data-action'] === 'sign-document') rendered.sign = props as typeof rendered.sign;
    };
    const jsx: typeof runtime.jsx = (type, props, key) => { capture(type, props); return runtime.jsx(type, props, key); };
    const jsxs: typeof runtime.jsxs = (type, props, key) => { capture(type, props); return runtime.jsxs(type, props, key); };
    return { ...runtime, jsx, jsxs };
});
vi.mock('react/jsx-dev-runtime', async (importOriginal) => {
    const runtime = await importOriginal<typeof import('react/jsx-dev-runtime')>();
    const jsxDEV: typeof runtime.jsxDEV = (type, props, key, isStaticChildren, source, self) => {
        if (type === 'select') rendered.selects.push(props as typeof rendered.selects[number]);
        if (type === 'button' && (props as { children: unknown }).children === 'บันทึกตำแหน่ง') rendered.save = props as typeof rendered.save;
        if (type === 'button' && (props as { children: unknown }).children === 'ลบตำแหน่งที่บันทึก') rendered.reset = props as typeof rendered.reset;
        if (type === 'button' && (props as { 'data-action'?: string })['data-action'] === 'sign-document') rendered.sign = props as typeof rendered.sign;
        return runtime.jsxDEV(type, props, key, isStaticChildren, source, self);
    };
    return { ...runtime, jsxDEV };
});
vi.mock('@/features/signatures/PdfViewer', () => ({ PdfViewer: (props: typeof rendered.viewer) => { rendered.viewer = props; return 'Mock PDF viewer'; } }));
vi.mock('react-router-dom', async (importOriginal) => ({ ...await importOriginal<typeof import('react-router-dom')>(), useNavigate: () => rendered.navigate }));

const route = { projectId: '71', documentId: '18', versionId: 'version-uuid' };
const saved: SignaturePlacement = { id: 10, signature_slot_id: 1, signature_asset_id: 'owned-active', assignment_revision: 3, page: 2, x: 0.2, y: 0.3, width: 0.25, height: 0.1, updated_at: null, stale: false, fingerprint: 'a'.repeat(64) };
const context = (): PlacementContext => ({
    document: { id: 18, original_name: 'project.pdf' },
    version: { public_id: route.versionId, revision_no: 1, download_url: '/api/v2/private/version.pdf', page_count: 3, is_current: true },
    slots: [
        { id: 1, slot_code: 'project_proposer', slot_no: 1, assigned_user_id: 7, assignment_revision: 3, assignee_status: 'eligible', assignee: { id: 7, name: 'Teacher' }, can_sign: true, placement: saved },
        { id: 2, slot_code: 'related_approver', slot_no: 2, assigned_user_id: 8, assignment_revision: 1, assignee_status: 'eligible', assignee: { id: 8, name: 'Approver' }, can_sign: false, placement: null },
        { id: 3, slot_code: 'deputy_director', slot_no: 3, assigned_user_id: null, assignment_revision: 0, assignee_status: 'unassigned', assignee: null, can_sign: false, placement: null },
        { id: 4, slot_code: 'director', slot_no: 4, assigned_user_id: null, assignment_revision: 0, assignee_status: 'unassigned', assignee: null, can_sign: false, placement: null },
    ],
    assets: [{ public_id: 'owned-active', width: 400, height: 100, status: 'active', eligible_for_signing: true, created_at: null }],
});
const clients: QueryClient[] = [];

function renderPage(data: PlacementContext, state?: { signedVersionId: string }) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    clients.push(client);
    client.setQueryData(placementKeys.context(route), data);
    const markup = renderToStaticMarkup(createElement(QueryClientProvider, { client },
        createElement(MemoryRouter, { initialEntries: [{ pathname: '/projects/71/documents/18/versions/version-uuid/placement', state }] },
            createElement(Routes, null, createElement(Route, {
                path: '/projects/:projectId/documents/:documentId/versions/:versionId/placement', element: createElement(SignaturePlacementPage),
            })),
        ),
    ));
    return { client, markup };
}

beforeEach(() => { rendered.selects = []; rendered.save = null; rendered.reset = null; rendered.sign = null; rendered.viewer = null; rendered.navigate.mockReset(); });
afterEach(() => { clients.splice(0).forEach((client) => client.clear()); vi.restoreAllMocks(); vi.unstubAllGlobals(); });

describe('signature placement screen', () => {
    it('shows all four slots, disables unassigned or foreign slots and filters retired assets', () => {
        const data = context();
        data.assets.push({ ...data.assets[0], public_id: 'retired-asset', status: 'retired' });
        const { markup } = renderPage(data);
        expect(markup).toContain('ผู้เสนอโครงการ');
        expect(markup).toContain('ผู้เห็นชอบที่เกี่ยวข้อง');
        expect(markup).toContain('รองผู้อำนวยการ');
        expect(markup).toContain('ผู้อำนวยการ');
        expect(markup).toMatch(/<option disabled="" value="2"/);
        expect(markup).toMatch(/<option disabled="" value="3"/);
        expect(markup).toMatch(/<option disabled="" value="4"/);
        expect(markup).not.toContain('retired-asset');
        expect(markup).toContain('ยังไม่ได้ประทับลายเซ็นลงใน PDF');
    });

    it('passes selected slot and asset values through the rendered controls', () => {
        const onSlotChange = vi.fn();
        const onAssetChange = vi.fn();
        const data = context();
        renderToStaticMarkup(createElement(PlacementSelectors, { slots: data.slots, assets: data.assets, slotId: 1, assetId: 'owned-active', disabled: false, onSlotChange, onAssetChange }));
        rendered.selects.find((select) => select.value === 1)!.onChange({ target: { value: '2' } });
        rendered.selects.find((select) => select.value === 'owned-active')!.onChange({ target: { value: 'another-owned-asset' } });
        expect(onSlotChange).toHaveBeenCalledWith(2);
        expect(onAssetChange).toHaveBeenCalledWith('another-owned-asset');
    });

    it('restores the saved asset, 1-based page and rectangle when opening a version', () => {
        const { markup } = renderPage(context());
        expect(rendered.viewer).toMatchObject({ url: '/api/v2/private/version.pdf', page: 2, placement: { page: 2, x: 0.2, y: 0.3 } });
        expect(markup).toContain('value="owned-active" selected=""');
        expect(rendered.viewer?.onPlace).toBeTypeOf('function');
        expect(rendered.save?.disabled).toBe(false);
    });

    it('restores the selected eligible slot through its real selection handler', () => {
        const data = context();
        const next = { ...data.slots[1], can_sign: true, assigned_user_id: 7, placement: { ...saved, signature_slot_id: 2, page: 3 } };
        data.slots[1] = next;
        const restore = vi.spyOn(placement, 'restorePlacementDraft');
        renderPage(data);
        restore.mockClear();
        rendered.selects[0].onChange({ target: { value: '2' } });
        expect(restore).toHaveBeenCalledExactlyOnceWith(next, data.assets);
        expect(restore.mock.results[0].value).toMatchObject({ assetId: 'owned-active', position: { page: 3 } });
        rendered.selects[0].onChange({ target: { value: '3' } });
        expect(restore).toHaveBeenCalledOnce();
    });

    it('passes saved normalized dimensions through the real viewer reposition handler', () => {
        const create = vi.spyOn(placement, 'createClickPlacement');
        renderPage(context());
        rendered.viewer!.onPlace!({ x: 0.6, y: 0.7 }, { width: 800, height: 600 });
        expect(create).toHaveBeenCalledExactlyOnceWith(
            { x: 0.6, y: 0.7 }, { width: 800, height: 600 }, context().assets[0], 2,
            { page: 2, x: 0.2, y: 0.3, width: 0.25, height: 0.1 },
        );
        expect(create.mock.results[0].value).toEqual({ page: 2, x: 0.6, y: 0.7, width: 0.25, height: 0.1 });
    });

    it('saves through the real rendered action and updates the cached draft', async () => {
        const updated = { ...saved, id: 11, updated_at: '2026-09-27T12:00:00Z' };
        const put = vi.spyOn(apiClient, 'put').mockResolvedValue({ data: { data: updated } });
        const post = vi.spyOn(apiClient, 'post');
        const { client } = renderPage(context());
        rendered.save!.onClick();
        await vi.waitFor(() => expect(client.getQueryData<PlacementContext>(placementKeys.context(route))?.slots[0].placement?.id).toBe(11));
        expect(put).toHaveBeenCalledExactlyOnceWith('/api/v2/projects/71/documents/18/versions/version-uuid/placements/1', {
            signature_asset_id: 'owned-active', assignment_revision: 3, page: 2, x: 0.2, y: 0.3, width: 0.25, height: 0.1,
        });
        expect(post).not.toHaveBeenCalled();
    });

    it('resets through the rendered action and clears only the selected slot in the cache', async () => {
        const remove = vi.spyOn(apiClient, 'delete').mockResolvedValue({ status: 204 });
        const data = context();
        data.slots[1].placement = { ...saved, id: 12, signature_slot_id: 2 };
        const { client } = renderPage(data);
        expect(rendered.reset?.disabled).toBe(false);
        rendered.reset!.onClick();
        await vi.waitFor(() => expect(client.getQueryData<PlacementContext>(placementKeys.context(route))?.slots[0].placement).toBeNull());
        expect(remove).toHaveBeenCalledExactlyOnceWith('/api/v2/projects/71/documents/18/versions/version-uuid/placements/1');
        expect(client.getQueryData<PlacementContext>(placementKeys.context(route))?.slots[1].placement?.id).toBe(12);
    });

    it('preserves the saved draft when the backend rejects the rendered save action', async () => {
        const error = new Error('Assignment changed');
        vi.spyOn(apiClient, 'put').mockRejectedValue(error);
        const { client } = renderPage(context());
        rendered.save!.onClick();
        await vi.waitFor(() => expect(client.getMutationCache().getAll()[0].state.error).toBe(error));
        expect(client.getQueryData<PlacementContext>(placementKeys.context(route))?.slots[0].placement).toEqual(saved);
    });

    it('allows PDF reading but disables placement for an account without an assigned eligible slot', () => {
        const data = context();
        data.slots = data.slots.map((slot) => ({ ...slot, can_sign: false }));
        const { markup } = renderPage(data);
        expect(markup).toContain('สามารถดูเอกสารได้');
        expect(rendered.viewer?.onPlace).toBeUndefined();
        expect(rendered.viewer?.placement).toBeNull();
        expect(rendered.save?.disabled).toBe(true);
    });

    it('clears stale drafts and asks the signer to select and place again', () => {
        const data = context();
        data.slots[0].placement = { ...saved, stale: true };
        const { markup } = renderPage(data);
        expect(markup).toContain('ตำแหน่งเดิมใช้ไม่ได้แล้ว');
        expect(rendered.viewer?.placement).toBeNull();
        expect(rendered.save?.disabled).toBe(true);
    });
});

describe('explicit document signing screen', () => {
    const signature: DocumentSignature = {
        public_id: 'signature-uuid', source_version_id: route.versionId, signature_slot_id: 1,
        signed_version: { public_id: 'signed-version', revision_no: 2, download_url: '/api/v2/private/signed.pdf' },
        signed_at: '2026-09-30T12:00:00Z', before_sha256: 'b'.repeat(64), after_sha256: 'c'.repeat(64),
    };

    it('requires explicit confirmation and does not sign when confirmation is canceled', () => {
        const confirm = vi.fn(() => false);
        vi.stubGlobal('window', { confirm });
        const post = vi.spyOn(apiClient, 'post');
        renderPage(context());
        expect(rendered.sign?.disabled).toBe(false);
        rendered.sign!.onClick();
        expect(confirm).toHaveBeenCalledOnce();
        expect(confirm.mock.calls[0]).toEqual([expect.stringContaining('ฉบับที่ 1')]);
        expect(confirm.mock.calls[0]).toEqual([expect.stringContaining('หน้า 2')]);
        expect(post).not.toHaveBeenCalled();
    });

    it('signs once, blocks competing actions, updates the source cache and opens the signed version', async () => {
        vi.stubGlobal('window', { confirm: vi.fn(() => true) });
        const post = vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: signature } });
        const put = vi.spyOn(apiClient, 'put');
        const remove = vi.spyOn(apiClient, 'delete');
        const { client } = renderPage(context());
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        rendered.sign!.onClick();
        rendered.sign!.onClick();
        rendered.save!.onClick();
        rendered.reset!.onClick();
        await vi.waitFor(() => expect(rendered.navigate).toHaveBeenCalledOnce());
        expect(post).toHaveBeenCalledExactlyOnceWith('/api/v2/projects/71/documents/18/versions/version-uuid/signatures/1', {
            assignment_revision: 3, placement_fingerprint: saved.fingerprint, idempotency_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
        });
        expect(put).not.toHaveBeenCalled();
        expect(remove).not.toHaveBeenCalled();
        expect(client.getQueryData<PlacementContext>(placementKeys.context(route))).toMatchObject({ version: { is_current: false }, current_version: signature.signed_version });
        expect(invalidate).toHaveBeenCalledWith({ queryKey: projectKeys.detail('71') });
        expect(rendered.navigate).toHaveBeenCalledWith('/projects/71/documents/18/versions/signed-version/placement', { replace: true, state: { signedVersionId: 'signed-version' } });
        rendered.sign!.onClick();
        expect(post).toHaveBeenCalledOnce();
    });

    it('reuses the same idempotency key for an explicit retry after a lost response', async () => {
        vi.stubGlobal('window', { confirm: vi.fn(() => true) });
        const failure = new ApiError('Connection lost');
        const post = vi.spyOn(apiClient, 'post').mockRejectedValueOnce(failure).mockResolvedValueOnce({ data: { data: signature } });
        const { client } = renderPage(context());
        rendered.sign!.onClick();
        await vi.waitFor(() => expect(client.getMutationCache().getAll()[0].state.error).toBe(failure));
        expect(post).toHaveBeenCalledOnce();
        expect(rendered.navigate).not.toHaveBeenCalled();
        expect(client.getQueryData<PlacementContext>(placementKeys.context(route))?.slots[0].placement).toEqual(saved);
        rendered.sign!.onClick();
        await vi.waitFor(() => expect(rendered.navigate).toHaveBeenCalledOnce());
        expect(post).toHaveBeenCalledTimes(2);
        expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
    });

    it('keeps signing pending until the response arrives and blocks edits throughout generation', async () => {
        const confirm = vi.fn(() => true);
        vi.stubGlobal('window', { confirm });
        let finish!: (value: { data: { data: DocumentSignature } }) => void;
        const post = vi.spyOn(apiClient, 'post').mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
        const put = vi.spyOn(apiClient, 'put');
        const remove = vi.spyOn(apiClient, 'delete');
        const restore = vi.spyOn(placement, 'restorePlacementDraft');
        const move = vi.spyOn(placement, 'createClickPlacement');
        const data = context();
        data.slots[1] = { ...data.slots[1], can_sign: true, assigned_user_id: 7 };
        const { client } = renderPage(data);
        restore.mockClear();
        rendered.sign!.onClick();
        await vi.waitFor(() => expect(post).toHaveBeenCalledOnce());
        expect(client.getMutationCache().getAll()[0].state.status).toBe('pending');
        expect(rendered.navigate).not.toHaveBeenCalled();
        expect(client.getQueryData<PlacementContext>(placementKeys.context(route))).toEqual(data);

        rendered.sign!.onClick();
        rendered.save!.onClick();
        rendered.reset!.onClick();
        rendered.selects[0].onChange({ target: { value: '2' } });
        rendered.viewer!.onPlace!({ x: 0.6, y: 0.7 }, { width: 800, height: 600 });
        expect(confirm).toHaveBeenCalledOnce();
        expect(post).toHaveBeenCalledOnce();
        expect(put).not.toHaveBeenCalled();
        expect(remove).not.toHaveBeenCalled();
        expect(restore).not.toHaveBeenCalled();
        expect(move).not.toHaveBeenCalled();

        finish({ data: { data: signature } });
        await vi.waitFor(() => expect(rendered.navigate).toHaveBeenCalledOnce());
    });

    it('does not confirm or sign while Save Placement is still pending', async () => {
        const confirm = vi.fn(() => true);
        vi.stubGlobal('window', { confirm });
        let finish!: (value: { data: { data: SignaturePlacement } }) => void;
        const put = vi.spyOn(apiClient, 'put').mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
        const post = vi.spyOn(apiClient, 'post');
        const { client } = renderPage(context());
        rendered.save!.onClick();
        await vi.waitFor(() => expect(put).toHaveBeenCalledOnce());
        rendered.sign!.onClick();
        expect(confirm).not.toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
        finish({ data: { data: saved } });
        await vi.waitFor(() => expect(client.getMutationCache().getAll()[0].state.status).toBe('success'));
        expect(post).not.toHaveBeenCalled();
    });

    it.each([
        ['signature_assignment_changed', 409], ['signature_placement_changed', 409], ['document_already_signed', 409],
        ['forbidden', 403], ['signature_asset_ineligible', 409], ['document_signing_generation_failed', 503],
    ] as const)('preserves draft and source when the server rejects %s', async (code, status) => {
        vi.stubGlobal('window', { confirm: vi.fn(() => true) });
        const failure = new ApiError('Reload the current document', status, code);
        const post = vi.spyOn(apiClient, 'post').mockRejectedValue(failure);
        const { client } = renderPage(context());
        rendered.sign!.onClick();
        await vi.waitFor(() => expect(client.getMutationCache().getAll()[0].state.error).toBe(failure));
        expect(post).toHaveBeenCalledOnce();
        expect(rendered.navigate).not.toHaveBeenCalled();
        expect(client.getQueryData<PlacementContext>(placementKeys.context(route))).toEqual(context());
    });

    it('disables signing old versions and offers the current version', () => {
        const data = context();
        data.version.is_current = false;
        data.current_version = signature.signed_version;
        const { markup } = renderPage(data);
        expect(rendered.sign?.disabled).toBe(true);
        expect(markup).toContain('href="/projects/71/documents/18/versions/signed-version/placement"');
        const post = vi.spyOn(apiClient, 'post');
        rendered.sign!.onClick();
        expect(post).not.toHaveBeenCalled();
    });

    it('requires a saved current placement belonging to an eligible assigned slot', () => {
        const data = context();
        data.slots[0].placement = null;
        renderPage(data);
        expect(rendered.sign?.disabled).toBe(true);
        data.slots[0].placement = { ...saved, stale: true };
        renderPage(data);
        expect(rendered.sign?.disabled).toBe(true);
        data.slots[0] = { ...data.slots[0], placement: saved, can_sign: false };
        renderPage(data);
        expect(rendered.sign?.disabled).toBe(true);
    });

    it('renders signing progress and rejects an ineligible or busy action', () => {
        const onSign = vi.fn();
        const markup = renderToStaticMarkup(createElement(SignDocumentButton, { eligible: true, busy: true, signing: true, onSign }));
        expect(markup).toContain('กำลังลงนาม…');
        expect(rendered.sign?.disabled).toBe(true);
        renderToStaticMarkup(createElement(SignDocumentButton, { eligible: false, busy: false, signing: false, onSign }));
        expect(rendered.sign?.disabled).toBe(true);
    });

    it('shows signing errors and validation details with a reload action', () => {
        const error = new ApiError('Placement changed', 409, 'stale_placement', { placement_fingerprint: ['Save the new placement first'] });
        const markup = renderToStaticMarkup(createElement(PlacementError, { error, busy: false, onReload: vi.fn() }));
        expect(markup).toContain('role="alert"');
        expect(markup).toContain('Placement changed');
        expect(markup).toContain('Save the new placement first');
        expect(markup).toContain('โหลดข้อมูลล่าสุดและเริ่มใหม่');
    });

    it('shows a success notice only on the version opened by the completed signing action', () => {
        expect(renderPage(context(), { signedVersionId: route.versionId }).markup).toContain('ลงนามสำเร็จแล้ว');
        expect(renderPage(context(), { signedVersionId: 'another-version' }).markup).not.toContain('ลงนามสำเร็จแล้ว');
        expect(renderPage(context()).markup).not.toContain('ลงนามสำเร็จแล้ว');
    });
});
