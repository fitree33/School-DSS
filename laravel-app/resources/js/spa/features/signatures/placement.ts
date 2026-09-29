import type { NormalizedPlacement, PlacementDraft, PlacementSlot, SavePlacementPayload, SignatureAsset, SignatureSlotCode } from '@/features/signatures/types';

const coordinateScale = 100_000_000;

export const slotLabels: Record<SignatureSlotCode, string> = {
    project_proposer: 'ผู้เสนอโครงการ',
    related_approver: 'ผู้เห็นชอบที่เกี่ยวข้อง',
    deputy_director: 'รองผู้อำนวยการ',
    director: 'ผู้อำนวยการ',
};

export const eligibleAssets = (assets: SignatureAsset[]): SignatureAsset[] => assets.filter((asset) => asset.status === 'active' && asset.eligible_for_signing);

export const restorePlacementDraft = (slot: PlacementSlot | undefined, assets: SignatureAsset[]): PlacementDraft => {
    const saved = slot?.can_sign && !slot.placement?.stale ? slot.placement : null;
    const asset = saved && eligibleAssets(assets).find((candidate) => candidate.public_id === saved.signature_asset_id);

    return {
        assetId: asset ? asset.public_id : '',
        assignmentRevision: slot?.assignment_revision ?? 0,
        position: saved && asset ? { page: saved.page, x: saved.x, y: saved.y, width: saved.width, height: saved.height } : null,
    };
};

/** Canonical coordinates use the top-left of the displayed PDF viewport, including intrinsic page rotation. */
export const createClickPlacement = (
    point: { x: number; y: number },
    pageSize: { width: number; height: number },
    asset: Pick<SignatureAsset, 'width' | 'height'>,
    page: number,
    previousSize?: Pick<NormalizedPlacement, 'width' | 'height'> | null,
): NormalizedPlacement | null => {
    if (![point.x, point.y, pageSize.width, pageSize.height, asset.width, asset.height].every(Number.isFinite)
        || pageSize.width <= 0 || pageSize.height <= 0 || asset.width <= 0 || asset.height <= 0
        || !Number.isInteger(page) || page < 1) return null;

    // Repositioning retains the saved normalized dimensions, including across pages.
    // New rectangles use a quarter-page maximum without introducing resize tools.
    let width = previousSize?.width ?? 0.25;
    let height = previousSize?.height ?? width * (asset.height / asset.width) * (pageSize.width / pageSize.height);
    if (!previousSize && height > 0.25) {
        width *= 0.25 / height;
        height = 0.25;
    }
    if (![width, height].every(Number.isFinite) || width <= 0 || height <= 0 || width > 1 || height > 1) return null;
    const widthUnits = Math.round(width * coordinateScale);
    const heightUnits = Math.round(height * coordinateScale);
    if (widthUnits < 1 || heightUnits < 1) return null;

    // Clamp on the same eight-decimal grid as the API/database, including page edges.
    return {
        page,
        x: Math.min(coordinateScale - widthUnits, Math.max(0, Math.round(point.x * coordinateScale))) / coordinateScale,
        y: Math.min(coordinateScale - heightUnits, Math.max(0, Math.round(point.y * coordinateScale))) / coordinateScale,
        width: widthUnits / coordinateScale,
        height: heightUnits / coordinateScale,
    };
};

export const placementPayload = (draft: PlacementDraft, slot: PlacementSlot | undefined, assets: SignatureAsset[], pageCount: number): SavePlacementPayload | null => {
    const position = draft.position;
    if (!slot?.can_sign || !eligibleAssets(assets).some((asset) => asset.public_id === draft.assetId) || !position
        || !Number.isInteger(position.page) || position.page < 1 || position.page > pageCount
        || ![position.x, position.y, position.width, position.height].every(Number.isFinite)
        || position.x < 0 || position.y < 0 || position.width <= 0 || position.height <= 0
        || position.x > 1 || position.y > 1 || position.width > 1 || position.height > 1) return null;

    const [x, y, width, height] = [position.x, position.y, position.width, position.height].map((value) => Math.round(value * coordinateScale));
    if (width < 1 || height < 1 || x + width > coordinateScale || y + height > coordinateScale) return null;

    return { page: position.page, x: x / coordinateScale, y: y / coordinateScale, width: width / coordinateScale, height: height / coordinateScale, signature_asset_id: draft.assetId, assignment_revision: draft.assignmentRevision };
};
