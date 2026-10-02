export type SignatureSlotCode = 'project_proposer' | 'related_approver' | 'deputy_director' | 'director';

export interface NormalizedPlacement {
    page: number;
    x: number;
    y: number;
    width: number;
    height: number;
}

export interface SignaturePlacement extends NormalizedPlacement {
    id: number;
    signature_slot_id: number;
    signature_asset_id: string;
    assignment_revision: number;
    updated_at: string | null;
    stale: boolean;
    fingerprint?: string;
}

export interface PlacementSlot {
    id: number;
    slot_code: SignatureSlotCode;
    slot_no: number;
    assigned_user_id: number | null;
    assignment_revision: number;
    assignee_status: string;
    assignee: { id: number; name: string } | null;
    can_sign: boolean;
    placement: SignaturePlacement | null;
}

export interface SignatureAsset {
    public_id: string;
    width: number;
    height: number;
    status: 'active' | 'retired';
    eligible_for_signing: boolean;
    created_at: string | null;
}

export interface PlacementContext {
    document: { id: number; original_name: string };
    version: DocumentVersionSummary & { page_count: number | null; is_current?: boolean };
    current_version?: DocumentVersionSummary;
    slots: PlacementSlot[];
    assets: SignatureAsset[];
}

export interface DocumentVersionSummary {
    public_id: string;
    revision_no: number;
    download_url: string;
}

export interface SigningSnapshot {
    assignment_revision: number;
    placement_fingerprint: string;
}

export interface SignDocumentPayload extends SigningSnapshot {
    idempotency_key: string;
}

export interface DocumentSignature {
    public_id: string;
    source_version_id: string;
    signed_version: DocumentVersionSummary;
    signature_slot_id: number;
    signed_at: string;
    before_sha256: string;
    after_sha256: string;
}

export interface PlacementRoute {
    projectId: string;
    documentId: string;
    versionId: string;
}

export interface SavePlacementPayload extends NormalizedPlacement {
    signature_asset_id: string;
    assignment_revision: number;
}

export interface PlacementDraft {
    assetId: string;
    assignmentRevision: number;
    position: NormalizedPlacement | null;
}
