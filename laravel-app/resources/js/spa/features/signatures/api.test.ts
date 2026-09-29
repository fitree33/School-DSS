import { afterEach, describe, expect, it, vi } from 'vitest';

import { ApiError, apiClient } from '@/api/client';
import { deletePlacement, fetchPlacementContext, savePlacement } from '@/features/signatures/api';
import type { SavePlacementPayload } from '@/features/signatures/types';

const route = { projectId: '71', documentId: '18', versionId: 'version-uuid' };
const url = '/api/v2/projects/71/documents/18/versions/version-uuid/placements';
const payload: SavePlacementPayload = { signature_asset_id: 'asset-uuid', assignment_revision: 3, page: 2, x: 0.3, y: 0.4, width: 0.25, height: 0.1 };

afterEach(() => vi.restoreAllMocks());

describe('placement API', () => {
    it('loads the version-scoped context from the authenticated SPA client', async () => {
        const context = { document: { id: 18 }, slots: [], assets: [] };
        const get = vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: context } });
        expect(await fetchPlacementContext(route)).toEqual(context);
        expect(get).toHaveBeenCalledWith(url);
        expect(apiClient.defaults.withCredentials).toBe(true);
    });

    it('saves the chosen slot and normalized placement without creating a signed version', async () => {
        const saved = { id: 5, ...payload, signature_slot_id: 9, stale: false, updated_at: null };
        const put = vi.spyOn(apiClient, 'put').mockResolvedValue({ data: { data: saved } });
        expect(await savePlacement(route, 9, payload)).toEqual(saved);
        expect(put).toHaveBeenCalledExactlyOnceWith(`${url}/9`, payload);
    });

    it('preserves backend errors for stale assignments and rejected assets', async () => {
        const error = new ApiError('Assignment changed', 409, 'stale_assignment');
        vi.spyOn(apiClient, 'put').mockRejectedValue(error);
        await expect(savePlacement(route, 9, payload)).rejects.toBe(error);
    });

    it('resets only the version and slot draft', async () => {
        const remove = vi.spyOn(apiClient, 'delete').mockResolvedValue({ status: 204 });
        await deletePlacement(route, 9);
        expect(remove).toHaveBeenCalledExactlyOnceWith(`${url}/9`);
    });
});
