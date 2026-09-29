import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { apiClient } from '@/api/client';
import { placementKeys } from '@/features/signatures/api';
import * as placement from '@/features/signatures/placement';
import { PlacementSelectors, SignaturePlacementPage } from '@/features/signatures/SignaturePlacementPage';
import type { PlacementContext, SignaturePlacement } from '@/features/signatures/types';

const rendered = vi.hoisted(() => ({
    selects: [] as Array<{ value: number | string; onChange: (event: { target: { value: string } }) => void }>,
    save: null as null | { disabled: boolean; onClick: () => void },
    reset: null as null | { disabled: boolean; onClick: () => void },
    viewer: null as null | { url: string; page: number; placement: SignaturePlacement | null; onPlace?: (point: { x: number; y: number }, size: { width: number; height: number }) => void },
}));
vi.mock('react/jsx-runtime', async (importOriginal) => {
    const runtime = await importOriginal<typeof import('react/jsx-runtime')>();
    const capture = (type: unknown, props: unknown) => {
        if (type === 'select') rendered.selects.push(props as typeof rendered.selects[number]);
        if (type === 'button' && (props as { children: unknown }).children === 'บันทึกตำแหน่ง') rendered.save = props as typeof rendered.save;
        if (type === 'button' && (props as { children: unknown }).children === 'ลบตำแหน่งที่บันทึก') rendered.reset = props as typeof rendered.reset;
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
        return runtime.jsxDEV(type, props, key, isStaticChildren, source, self);
    };
    return { ...runtime, jsxDEV };
});
vi.mock('@/features/signatures/PdfViewer', () => ({ PdfViewer: (props: typeof rendered.viewer) => { rendered.viewer = props; return 'Mock PDF viewer'; } }));

const route = { projectId: '71', documentId: '18', versionId: 'version-uuid' };
const saved: SignaturePlacement = { id: 10, signature_slot_id: 1, signature_asset_id: 'owned-active', assignment_revision: 3, page: 2, x: 0.2, y: 0.3, width: 0.25, height: 0.1, updated_at: null, stale: false };
const context = (): PlacementContext => ({
    document: { id: 18, original_name: 'project.pdf' },
    version: { public_id: route.versionId, revision_no: 1, download_url: '/api/v2/private/version.pdf', page_count: 3 },
    slots: [
        { id: 1, slot_code: 'project_proposer', slot_no: 1, assigned_user_id: 7, assignment_revision: 3, assignee_status: 'eligible', assignee: { id: 7, name: 'Teacher' }, can_sign: true, placement: saved },
        { id: 2, slot_code: 'related_approver', slot_no: 2, assigned_user_id: 8, assignment_revision: 1, assignee_status: 'eligible', assignee: { id: 8, name: 'Approver' }, can_sign: false, placement: null },
        { id: 3, slot_code: 'deputy_director', slot_no: 3, assigned_user_id: null, assignment_revision: 0, assignee_status: 'unassigned', assignee: null, can_sign: false, placement: null },
        { id: 4, slot_code: 'director', slot_no: 4, assigned_user_id: null, assignment_revision: 0, assignee_status: 'unassigned', assignee: null, can_sign: false, placement: null },
    ],
    assets: [{ public_id: 'owned-active', width: 400, height: 100, status: 'active', eligible_for_signing: true, created_at: null }],
});
const clients: QueryClient[] = [];

function renderPage(data: PlacementContext) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    clients.push(client);
    client.setQueryData(placementKeys.context(route), data);
    const markup = renderToStaticMarkup(createElement(QueryClientProvider, { client },
        createElement(MemoryRouter, { initialEntries: ['/projects/71/documents/18/versions/version-uuid/placement'] },
            createElement(Routes, null, createElement(Route, {
                path: '/projects/:projectId/documents/:documentId/versions/:versionId/placement', element: createElement(SignaturePlacementPage),
            })),
        ),
    ));
    return { client, markup };
}

beforeEach(() => { rendered.selects = []; rendered.save = null; rendered.reset = null; rendered.viewer = null; });
afterEach(() => { clients.splice(0).forEach((client) => client.clear()); vi.restoreAllMocks(); });

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
        const { client } = renderPage(context());
        rendered.save!.onClick();
        await vi.waitFor(() => expect(client.getQueryData<PlacementContext>(placementKeys.context(route))?.slots[0].placement?.id).toBe(11));
        expect(put).toHaveBeenCalledExactlyOnceWith('/api/v2/projects/71/documents/18/versions/version-uuid/placements/1', {
            signature_asset_id: 'owned-active', assignment_revision: 3, page: 2, x: 0.2, y: 0.3, width: 0.25, height: 0.1,
        });
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
