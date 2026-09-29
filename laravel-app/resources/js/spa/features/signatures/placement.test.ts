import { describe, expect, it } from 'vitest';

import { createClickPlacement, eligibleAssets, placementPayload, restorePlacementDraft } from '@/features/signatures/placement';
import type { PlacementSlot, SignatureAsset, SignaturePlacement } from '@/features/signatures/types';

const asset: SignatureAsset = { public_id: 'owned-active', width: 400, height: 100, status: 'active', eligible_for_signing: true, created_at: null };
const saved: SignaturePlacement = { id: 5, signature_slot_id: 1, signature_asset_id: asset.public_id, assignment_revision: 3, page: 2, x: 0.2, y: 0.4, width: 0.25, height: 0.1, stale: false, updated_at: null };
const slot: PlacementSlot = { id: 1, slot_code: 'project_proposer', slot_no: 1, assigned_user_id: 7, assignment_revision: 3, assignee_status: 'eligible', assignee: { id: 7, name: 'Teacher' }, can_sign: true, placement: saved };

describe('normalized signature placement', () => {
    it('preserves image aspect ratio using the displayed PDF viewport dimensions', () => {
        const result = createClickPlacement({ x: 0.3, y: 0.4 }, { width: 600, height: 800 }, asset, 2)!;
        expect(result).toEqual({ page: 2, x: 0.3, y: 0.4, width: 0.25, height: 0.046875 });
        expect((result.width * 600) / (result.height * 800)).toBe(4);
        expect(createClickPlacement({ x: 0.3, y: 0.4 }, { width: 1200, height: 1600 }, asset, 2)).toEqual(result);
    });

    it('clamps clicks at page edges to keep the entire rectangle inside the page', () => {
        const result = createClickPlacement({ x: 1, y: 1 }, { width: 600, height: 800 }, asset, 1)!;
        expect(result.x + result.width).toBe(1);
        expect(result.y + result.height).toBe(1);
        expect(createClickPlacement({ x: -0.1, y: -0.1 }, { width: 600, height: 800 }, asset, 1)).toMatchObject({ x: 0, y: 0 });
    });

    it('retains a saved rectangle size when repositioning on a page with a different aspect ratio', () => {
        const size = { width: 0.37, height: 0.12 };
        const portrait = createClickPlacement({ x: 0.1, y: 0.2 }, { width: 600, height: 800 }, asset, 2, size)!;
        expect(portrait).toEqual({ page: 2, x: 0.1, y: 0.2, ...size });
        expect(createClickPlacement({ x: 1, y: 1 }, { width: 800, height: 600 }, asset, 3, portrait))
            .toEqual({ page: 3, x: 0.63, y: 0.88, ...size });
    });

    it('rounds the preview and edge coordinates on the persisted eight-decimal grid', () => {
        const result = createClickPlacement({ x: 1, y: 1 }, { width: 595.276, height: 841.89 }, { width: 411, height: 123 }, 1)!;
        expect(result.height).toBe(Number((0.25 * (123 / 411) * (595.276 / 841.89)).toFixed(8)));
        expect(Math.round(result.y * 100_000_000) + Math.round(result.height * 100_000_000)).toBe(100_000_000);
        expect(placementPayload({ assetId: asset.public_id, assignmentRevision: 3, position: result }, slot, [asset], 1))
            .toMatchObject(result);
    });

    it('does not offer a rectangle whose dimension would be rounded to zero by storage', () => {
        expect(createClickPlacement({ x: 0, y: 0 }, { width: 1, height: 100_000_000 }, asset, 1)).toBeNull();
        expect(createClickPlacement({ x: 0, y: 0 }, { width: 600, height: 800 }, asset, 1, { width: 0.25, height: 0.000000001 })).toBeNull();
        expect(placementPayload({ ...restorePlacementDraft(slot, [asset]), position: { ...saved, height: 0.000000001 } }, slot, [asset], 3)).toBeNull();
    });

    it('validates page-edge sums using decimal units instead of floating-point addition', () => {
        const position = { page: 2, x: 0.98133741, width: 0.01866259, y: 0.88888889, height: 0.11111111 };
        expect(placementPayload({ ...restorePlacementDraft(slot, [asset]), position }, slot, [asset], 3)).toMatchObject(position);
        expect(placementPayload({ ...restorePlacementDraft(slot, [asset]), position: { ...position, width: 0.01866260 } }, slot, [asset], 3)).toBeNull();
    });

    it('caps tall images while preserving their aspect ratio on landscape pages', () => {
        const result = createClickPlacement({ x: 0, y: 0 }, { width: 800, height: 600 }, { width: 100, height: 400 }, 1)!;
        expect(result.height).toBe(0.25);
        expect((result.width * 800) / (result.height * 600)).toBe(0.25);
    });

    it('rejects non-finite points, invalid dimensions and non-1-based pages', () => {
        expect(createClickPlacement({ x: NaN, y: 0 }, { width: 600, height: 800 }, asset, 1)).toBeNull();
        expect(createClickPlacement({ x: 0, y: 0 }, { width: 0, height: 800 }, asset, 1)).toBeNull();
        expect(createClickPlacement({ x: 0, y: 0 }, { width: 600, height: 800 }, { width: 0, height: 100 }, 1)).toBeNull();
        expect(createClickPlacement({ x: 0, y: 0 }, { width: 600, height: 800 }, asset, 0)).toBeNull();
        expect(createClickPlacement({ x: 0, y: 0 }, { width: 600, height: 800 }, asset, 1.5)).toBeNull();
    });

    it('restores only a current draft with an eligible owned asset and signer slot', () => {
        expect(restorePlacementDraft(slot, [asset])).toMatchObject({ assetId: asset.public_id, assignmentRevision: 3, position: { page: 2, x: 0.2, y: 0.4 } });
        expect(restorePlacementDraft({ ...slot, placement: { ...saved, stale: true } }, [asset]).position).toBeNull();
        expect(restorePlacementDraft({ ...slot, can_sign: false }, [asset]).position).toBeNull();
        expect(restorePlacementDraft(slot, [{ ...asset, status: 'retired' }]).assetId).toBe('');
        expect(restorePlacementDraft(slot, []).position).toBeNull();
    });

    it('excludes retired and otherwise ineligible assets from selection', () => {
        expect(eligibleAssets([asset, { ...asset, public_id: 'retired', status: 'retired' }, { ...asset, public_id: 'ineligible', eligible_for_signing: false }])).toEqual([asset]);
    });

    it('builds a normalized save payload carrying the original assignment revision', () => {
        const draft = restorePlacementDraft(slot, [asset]);
        expect(placementPayload(draft, { ...slot, assignment_revision: 4 }, [asset], 3)).toEqual({ signature_asset_id: asset.public_id, assignment_revision: 3, page: 2, x: 0.2, y: 0.4, width: 0.25, height: 0.1 });
        expect(placementPayload(draft, slot, [asset], 0)).toBeNull();
        expect(placementPayload(draft, slot, [asset], 1)).toBeNull();
        expect(placementPayload(draft, { ...slot, can_sign: false }, [asset], 3)).toBeNull();
        expect(placementPayload(draft, slot, [], 3)).toBeNull();
        expect(placementPayload({ ...draft, position: { ...saved, x: 0.9 } }, slot, [asset], 3)).toBeNull();
    });
});
