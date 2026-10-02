import { afterEach, describe, expect, it, vi } from 'vitest';

import { ApiError, apiClient } from '@/api/client';
import { deletePlacement, fetchPlacementContext, savePlacement, signDocument } from '@/features/signatures/api';
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
        const post = vi.spyOn(apiClient, 'post');
        expect(await savePlacement(route, 9, payload)).toEqual(saved);
        expect(put).toHaveBeenCalledExactlyOnceWith(`${url}/9`, payload);
        expect(post).not.toHaveBeenCalled();
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

describe('document signing API', () => {
    const signing = { assignment_revision: 3, placement_fingerprint: 'a'.repeat(64), idempotency_key: 'b575e22e-07bd-47df-a4f0-b4329b3aedb7' };

    it('posts a confirmed signing snapshot to a separate version and slot endpoint', async () => {
        const result = { public_id: 'signature-uuid', signed_version: { public_id: 'signed-version', revision_no: 2, download_url: '/private/signed.pdf' } };
        const post = vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: result } });
        expect(await signDocument(route, 9, signing)).toEqual(result);
        expect(post).toHaveBeenCalledExactlyOnceWith('/api/v2/projects/71/documents/18/versions/version-uuid/signatures/9', signing);
    });

    it('encodes route identifiers without accepting request-side geometry or a different signer', async () => {
        const post = vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: {} } });
        await signDocument({ projectId: 'p/1', documentId: 'd?2', versionId: 'v#3' }, 9, signing);
        expect(post).toHaveBeenCalledExactlyOnceWith('/api/v2/projects/p%2F1/documents/d%3F2/versions/v%233/signatures/9', signing);
    });

    it('preserves a rejected signing response for error feedback and explicit retry', async () => {
        const error = new ApiError('Placement changed', 409, 'stale_placement');
        const post = vi.spyOn(apiClient, 'post').mockRejectedValue(error);
        await expect(signDocument(route, 9, signing)).rejects.toBe(error);
        expect(post).toHaveBeenCalledOnce();
    });
});
