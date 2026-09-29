import { apiClient } from '@/api/client';
import type { ApiEnvelope } from '@/api/contracts';
import type { PlacementContext, PlacementRoute, SavePlacementPayload, SignaturePlacement } from '@/features/signatures/types';

export const placementKeys = {
    context: ({ projectId, documentId, versionId }: PlacementRoute) => ['signature-placements', projectId, documentId, versionId] as const,
};

const placementUrl = ({ projectId, documentId, versionId }: PlacementRoute): string => (
    `/api/v2/projects/${encodeURIComponent(projectId)}/documents/${encodeURIComponent(documentId)}/versions/${encodeURIComponent(versionId)}/placements`
);

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
