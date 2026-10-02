import { describe, expect, it } from 'vitest';

import { restorePlacementDraft } from '@/features/signatures/placement';
import { placementPath, signingSnapshot } from '@/features/signatures/signing';
import type { PlacementDraft, PlacementSlot, SignatureAsset, SignaturePlacement } from '@/features/signatures/types';

const asset: SignatureAsset = { public_id: 'owned-active', width: 400, height: 100, status: 'active', eligible_for_signing: true, created_at: null };
const saved: SignaturePlacement = { id: 5, signature_slot_id: 1, signature_asset_id: asset.public_id, assignment_revision: 3, page: 2, x: 0.2, y: 0.4, width: 0.25, height: 0.1, stale: false, updated_at: null, fingerprint: 'a'.repeat(64) };
const slot: PlacementSlot = { id: 1, slot_code: 'project_proposer', slot_no: 1, assigned_user_id: 7, assignment_revision: 3, assignee_status: 'eligible', assignee: { id: 7, name: 'Teacher' }, can_sign: true, placement: saved };
const draft = (): PlacementDraft => restorePlacementDraft(slot, [asset]);

describe('explicit signing eligibility', () => {
    it('binds signing to the current saved fingerprint and assignment revision', () => {
        expect(signingSnapshot(true, draft(), slot, [asset], 3)).toEqual({ assignment_revision: 3, placement_fingerprint: saved.fingerprint });
    });

    it('allows the same assigned user to sign distinct eligible slots with their own saved snapshots', () => {
        const second: PlacementSlot = {
            ...slot, id: 2, slot_code: 'related_approver', slot_no: 2, assignment_revision: 5,
            placement: { ...saved, id: 6, signature_slot_id: 2, assignment_revision: 5, fingerprint: 'b'.repeat(64) },
        };
        expect(signingSnapshot(true, draft(), slot, [asset], 3)).toEqual({ assignment_revision: 3, placement_fingerprint: saved.fingerprint });
        expect(signingSnapshot(true, restorePlacementDraft(second, [asset]), second, [asset], 3))
            .toEqual({ assignment_revision: 5, placement_fingerprint: second.placement!.fingerprint });
        expect(signingSnapshot(true, draft(), second, [asset], 3)).toBeNull();
    });

    it.each([false, undefined])('fails closed when the version is not explicitly current (%s)', (current) => {
        expect(signingSnapshot(current, draft(), slot, [asset], 3)).toBeNull();
    });

    it('requires an assigned eligible slot, active owned asset, saved draft and valid PDF page', () => {
        expect(signingSnapshot(true, draft(), undefined, [asset], 3)).toBeNull();
        expect(signingSnapshot(true, draft(), { ...slot, can_sign: false }, [asset], 3)).toBeNull();
        expect(signingSnapshot(true, draft(), slot, [{ ...asset, status: 'retired' }], 3)).toBeNull();
        expect(signingSnapshot(true, draft(), slot, [{ ...asset, eligible_for_signing: false }], 3)).toBeNull();
        expect(signingSnapshot(true, draft(), slot, [], 3)).toBeNull();
        expect(signingSnapshot(true, draft(), { ...slot, placement: null }, [asset], 3)).toBeNull();
        expect(signingSnapshot(true, draft(), slot, [asset], 1)).toBeNull();
    });

    it.each(['page', 'x', 'y', 'width', 'height'] as const)('requires saving a changed %s before signing', (field) => {
        const edited = draft();
        edited.position = { ...edited.position!, [field]: field === 'page' ? 1 : 0.15 };
        expect(signingSnapshot(true, edited, slot, [asset], 3)).toBeNull();
    });

    it('rejects an unsaved asset change or assignment revision', () => {
        const other = { ...asset, public_id: 'second-owned-active' };
        expect(signingSnapshot(true, { ...draft(), assetId: other.public_id }, slot, [asset, other], 3)).toBeNull();
        expect(signingSnapshot(true, { ...draft(), assignmentRevision: 4 }, slot, [asset], 3)).toBeNull();
        expect(signingSnapshot(true, draft(), { ...slot, assignment_revision: 4 }, [asset], 3)).toBeNull();
    });

    it.each([
        { stale: true },
        { signature_slot_id: 2 },
        { assignment_revision: 2 },
        { fingerprint: undefined },
        { fingerprint: 'invalid-fingerprint' },
    ])('rejects stale or mismatched saved evidence %j', (change) => {
        expect(signingSnapshot(true, draft(), { ...slot, placement: { ...saved, ...change } }, [asset], 3)).toBeNull();
    });

    it('encodes every route identifier when opening the new signed version', () => {
        expect(placementPath({ projectId: 'project/1', documentId: 'doc?2', versionId: 'version#3' }))
            .toBe('/projects/project%2F1/documents/doc%3F2/versions/version%233/placement');
    });
});
