import type { AxiosProgressEvent } from 'axios';

import { apiClient } from '@/api/client';
import type {
    ApiEnvelope,
    ConfirmDocumentImportPayload,
    CreateImportPreviewRevisionPayload,
    DocumentImport,
    DocumentImportConfirmation,
    DocumentImportFilters,
    DocumentImportOptions,
    ImportConfirmedProject,
    ImportPreviewRevision,
    PaginatedDocumentImports,
} from '@/api/contracts';
import { normalizeImportPreviewPayload } from '@/features/imports/model';

export const importKeys = {
    all: ['document-imports'] as const,
    lists: () => [...importKeys.all, 'list'] as const,
    list: (filters: DocumentImportFilters) => [...importKeys.lists(), filters] as const,
    details: () => [...importKeys.all, 'detail'] as const,
    detail: (publicId: string) => [...importKeys.details(), publicId] as const,
    revisions: (publicId: string) => [...importKeys.detail(publicId), 'preview-revisions'] as const,
    options: ['document-imports', 'options'] as const,
};

interface RawPreviewRevision extends Omit<ImportPreviewRevision, 'confidence' | 'payload'> {
    payload?: unknown;
    confidence?: Record<string, number | null>;
    field_confidence?: Record<string, number | null>;
}

interface RawDocumentImport extends Partial<Omit<DocumentImport, 'current_preview' | 'created_at' | 'updated_at' | 'extracted_at' | 'confirmed_at'>> {
    id: number;
    public_id: string;
    status: DocumentImport['status'];
    original: DocumentImport['original'];
    current_preview?: RawPreviewRevision | null;
    current_preview_revision?: RawPreviewRevision | null;
    timestamps?: {
        created_at?: string | null;
        updated_at?: string | null;
        extracted_at?: string | null;
        confirmed_at?: string | null;
    };
    created_at?: string | null;
    updated_at?: string | null;
    extracted_at?: string | null;
    confirmed_at?: string | null;
}

const normalizeRevision = (revision: RawPreviewRevision): ImportPreviewRevision => ({
    ...revision,
    payload: normalizeImportPreviewPayload(revision.payload),
    raw_payload: revision.payload,
    validation_errors: revision.validation_errors ?? {},
    warnings: revision.warnings ?? [],
    confidence: revision.confidence ?? revision.field_confidence ?? {},
});

const normalizeImport = (documentImport: RawDocumentImport): DocumentImport => {
    const timestamps = documentImport.timestamps ?? {};
    const preview = documentImport.current_preview ?? documentImport.current_preview_revision ?? null;

    return {
        id: documentImport.id,
        public_id: documentImport.public_id,
        status: documentImport.status,
        processing_stage: documentImport.processing_stage ?? null,
        original: documentImport.original,
        uploader: documentImport.uploader ?? null,
        uploader_department: documentImport.uploader_department ?? null,
        latest_run: documentImport.latest_run ?? null,
        original_extraction: documentImport.original_extraction ?? null,
        current_preview: preview ? normalizeRevision(preview) : null,
        confirmed_project: documentImport.confirmed_project ?? null,
        failure: documentImport.failure ?? null,
        abilities: documentImport.abilities ?? {
            view_original: false,
            review: false,
            retry: false,
            confirm: false,
        },
        created_at: documentImport.created_at ?? timestamps.created_at ?? '',
        updated_at: documentImport.updated_at ?? timestamps.updated_at ?? '',
        extracted_at: documentImport.extracted_at ?? timestamps.extracted_at ?? null,
        confirmed_at: documentImport.confirmed_at ?? timestamps.confirmed_at ?? null,
    };
};

const compactParams = (filters: DocumentImportFilters): Record<string, string | number> => Object.fromEntries(
    Object.entries(filters).filter((entry): entry is [string, string | number] => entry[1] !== undefined && entry[1] !== ''),
);

export const fetchDocumentImports = async (filters: DocumentImportFilters): Promise<PaginatedDocumentImports> => {
    const response = await apiClient.get<PaginatedDocumentImports>('/api/v2/imports', {
        params: compactParams(filters),
    });

    return {
        ...response.data,
        data: response.data.data.map((item) => normalizeImport(item as RawDocumentImport)),
    };
};

export const fetchDocumentImportOptions = async (): Promise<DocumentImportOptions> => {
    const response = await apiClient.get<ApiEnvelope<DocumentImportOptions>>('/api/v2/imports/options');
    return response.data.data;
};

export const fetchDocumentImport = async (publicId: string): Promise<DocumentImport> => {
    const response = await apiClient.get<ApiEnvelope<RawDocumentImport>>(`/api/v2/imports/${publicId}`);
    return normalizeImport(response.data.data);
};

export const uploadDocumentImport = async (
    document: File,
    onProgress?: (progress: number | null) => void,
): Promise<DocumentImport> => {
    const formData = new FormData();
    formData.append('document', document);

    const response = await apiClient.post<ApiEnvelope<RawDocumentImport>>('/api/v2/imports', formData, {
        onUploadProgress: (event: AxiosProgressEvent) => {
            if (!event.total) {
                onProgress?.(null);
                return;
            }

            onProgress?.(Math.min(100, Math.round((event.loaded / event.total) * 100)));
        },
    });

    return normalizeImport(response.data.data);
};

export const retryDocumentImport = async (publicId: string): Promise<DocumentImport> => {
    const response = await apiClient.post<ApiEnvelope<RawDocumentImport>>(`/api/v2/imports/${publicId}/retry`);
    return normalizeImport(response.data.data);
};

export const fetchImportPreviewRevisions = async (publicId: string): Promise<ImportPreviewRevision[]> => {
    const response = await apiClient.get<ApiEnvelope<RawPreviewRevision[]>>(`/api/v2/imports/${publicId}/preview-revisions`);
    return response.data.data.map(normalizeRevision);
};

export const createImportPreviewRevision = async (
    publicId: string,
    payload: CreateImportPreviewRevisionPayload,
): Promise<ImportPreviewRevision> => {
    const response = await apiClient.post<ApiEnvelope<RawPreviewRevision>>(
        `/api/v2/imports/${publicId}/preview-revisions`,
        payload,
    );
    return normalizeRevision(response.data.data);
};

interface RawConfirmation {
    document_import?: RawDocumentImport;
    import?: RawDocumentImport;
    project?: ImportConfirmedProject;
    confirmed_project?: ImportConfirmedProject;
}

export const confirmDocumentImport = async (
    publicId: string,
    payload: ConfirmDocumentImportPayload,
): Promise<DocumentImportConfirmation> => {
    const response = await apiClient.post<ApiEnvelope<RawConfirmation>>(
        `/api/v2/imports/${publicId}/confirm`,
        payload,
    );
    const data = response.data.data;
    const rawImport = data.document_import ?? data.import;
    const project = data.project ?? data.confirmed_project ?? rawImport?.confirmed_project;

    if (!rawImport || !project) {
        throw new Error('ผลยืนยันการนำเข้าไม่สมบูรณ์');
    }

    return {
        document_import: normalizeImport(rawImport),
        project,
    };
};

export const originalDocumentUrl = (documentImport: DocumentImport): string => (
    documentImport.original.download_url ?? `/api/v2/imports/${documentImport.public_id}/original`
);
