import { apiClient } from '@/api/client';
import type { ApiEnvelope } from '@/api/contracts';
import type { DocumentSignature, PlacementContext, PlacementRoute, SavePlacementPayload, SignDocumentPayload, SignaturePlacement } from '@/features/signatures/types';

export const placementKeys = {
    context: ({ projectId, documentId, versionId }: PlacementRoute) => ['signature-placements', projectId, documentId, versionId] as const,
};

const versionUrl = ({ projectId, documentId, versionId }: PlacementRoute): string => (
    `/api/v2/projects/${encodeURIComponent(projectId)}/documents/${encodeURIComponent(documentId)}/versions/${encodeURIComponent(versionId)}`
);
const placementUrl = (route: PlacementRoute): string => `${versionUrl(route)}/placements`;

export const fetchPlacementContext = async (route: PlacementRoute): Promise<PlacementContext> => {
    const response = await apiClient.get<ApiEnvelope<PlacementContext>>(placementUrl(route));
    return response.data.data;
};

export const savePlacement = async (route: PlacementRoute, slotId: number, payload: SavePlacementPayload): Promise<SignaturePlacement> => {
    const response = await apiClient.put<ApiEnvelope<SignaturePlacement>>(`${placementUrl(route)}/${slotId}`, payload);
    return response.data.data;
};

export const deletePlacement = async (route: PlacementRoute, slotId: number): Promise<void> => {
    await apiClient.delete(`${placementUrl(route)}/${slotId}`);
};

export const signDocument = async (route: PlacementRoute, slotId: number, payload: SignDocumentPayload): Promise<DocumentSignature> => {
    const response = await apiClient.post<ApiEnvelope<DocumentSignature>>(`${versionUrl(route)}/signatures/${slotId}`, payload);
    return response.data.data;
};
