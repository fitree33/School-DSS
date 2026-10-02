import { placementPayload } from '@/features/signatures/placement';
import type { PlacementDraft, PlacementRoute, PlacementSlot, SignatureAsset, SigningSnapshot } from '@/features/signatures/types';

/** The server fingerprint binds signing to the saved draft; edits must be saved first. */
export const signingSnapshot = (
    isCurrent: boolean | undefined,
    draft: PlacementDraft,
    slot: PlacementSlot | undefined,
    assets: SignatureAsset[],
    pageCount: number,
): SigningSnapshot | null => {
    const saved = slot?.placement;
    const payload = placementPayload(draft, slot, assets, pageCount);
    if (isCurrent !== true || !slot || !saved || saved.stale || !payload
        || !saved.fingerprint || !/^[0-9a-f]{64}$/.test(saved.fingerprint)
        || saved.signature_slot_id !== slot.id || saved.assignment_revision !== slot.assignment_revision
        || payload.assignment_revision !== saved.assignment_revision
        || payload.signature_asset_id !== saved.signature_asset_id
        || !(['page', 'x', 'y', 'width', 'height'] as const).every((field) => payload[field] === saved[field])) return null;

    return { assignment_revision: saved.assignment_revision, placement_fingerprint: saved.fingerprint };
};

export const placementPath = ({ projectId, documentId, versionId }: PlacementRoute): string => (
    `/projects/${encodeURIComponent(projectId)}/documents/${encodeURIComponent(documentId)}/versions/${encodeURIComponent(versionId)}/placement`
);
