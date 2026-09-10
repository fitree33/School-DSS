import { afterEach, describe, expect, it, vi } from 'vitest';

import { apiClient } from '@/api/client';
import {
    confirmDocumentImport,
    createImportPreviewRevision,
    fetchDocumentImport,
    fetchDocumentImportOptions,
    fetchDocumentImports,
    fetchImportPreviewRevisions,
    retryDocumentImport,
    uploadDocumentImport,
} from '@/features/imports/api';
import { emptyImportPreviewPayload } from '@/features/imports/model';

const rawRevision = () => ({
    id: 904, revision_no: 3, source: 'user', payload: emptyImportPreviewPayload(),
    validation_errors: [], warnings: [], confidence: [], edited_by: null,
    created_at: '2026-09-07T01:00:00.000Z',
});

const rawImport = () => ({
    id: 42, public_id: 'import-42', status: 'needs_review',
    original: { name: 'project.pdf', mime_type: 'application/pdf', size_bytes: 100, sha256: 'a'.repeat(64), download_url: '/api/v2/imports/import-42/original' },
    current_preview: rawRevision(),
    created_at: '2026-09-07T01:00:00.000Z', updated_at: '2026-09-07T01:00:00.000Z',
    abilities: { view_original: true, review: true, retry: false, confirm: true },
});

afterEach(() => vi.restoreAllMocks());

describe('document import API', () => {
    it('loads upload constraints and preview selectors through the import-authorized options endpoint', async () => {
        const options = {
            constraints: { max_bytes: 1024, accepted_mime_types: ['application/pdf'] },
            project_options: { fiscal_years: [{ id: 1, year: 2570, is_locked: true }], departments: [{ id: 2, name: 'ฝ่าย' }] },
        };
        const get = vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: options } });
        expect(await fetchDocumentImportOptions()).toEqual(options);
        expect(get).toHaveBeenCalledWith('/api/v2/imports/options');
    });

    it('loads production import endpoints and preserves authoritative errors and the raw revision snapshot', async () => {
        const revision = {
            ...rawRevision(), payload: { budget: null, indicators: null },
            validation_errors: { budget: ['Required.'], indicators: ['Must be an array.'] },
        };
        const get = vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: { ...rawImport(), current_preview: revision } } });
        const documentImport = await fetchDocumentImport('import-42');
        expect(get).toHaveBeenCalledWith('/api/v2/imports/import-42');
        expect(documentImport.current_preview?.payload.indicators).toEqual([]);
        expect(documentImport.current_preview?.payload.budget).toBe('');
        expect(documentImport.current_preview?.raw_payload).toEqual(revision.payload);
        expect(documentImport.current_preview?.validation_errors).toEqual(revision.validation_errors);
        expect(documentImport.abilities.confirm).toBe(true);
    });

    it('normalizes list and history results and omits empty filters', async () => {
        const get = vi.spyOn(apiClient, 'get')
            .mockResolvedValueOnce({ data: { data: [rawImport()], meta: { current_page: 1 } } })
            .mockResolvedValueOnce({ data: { data: [rawRevision()] } });
        const list = await fetchDocumentImports({ status: '', page: 1, per_page: 15 });
        const history = await fetchImportPreviewRevisions('import-42');
        expect(get).toHaveBeenNthCalledWith(1, '/api/v2/imports', { params: { page: 1, per_page: 15 } });
        expect(get).toHaveBeenNthCalledWith(2, '/api/v2/imports/import-42/preview-revisions');
        expect(list.data[0].current_preview?.id).toBe(904);
        expect(history[0].raw_payload).toEqual(rawRevision().payload);
    });

    it('posts only the revision row and stable idempotency key to the production confirm route', async () => {
        const project = { id: 71, name: 'โครงการที่ยืนยัน' };
        const post = vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: {
            document_import: { ...rawImport(), status: 'confirmed', confirmed_project: project }, project,
        } } });
        const request = { preview_revision_id: 904, idempotency_key: 'stable-confirm-key' };
        const first = await confirmDocumentImport('import-42', request);
        const replay = await confirmDocumentImport('import-42', request);
        expect(post).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenNthCalledWith(1, '/api/v2/imports/import-42/confirm', request);
        expect(post).toHaveBeenNthCalledWith(2, '/api/v2/imports/import-42/confirm', request);
        expect(first).toEqual(replay);
        expect(first.project.id).toBe(71);
        expect(first.document_import.status).toBe('confirmed');
    });

    it('does not report success if confirmation response lacks a project', async () => {
        vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: { document_import: rawImport() } } });
        await expect(confirmDocumentImport('import-42', { preview_revision_id: 904, idempotency_key: 'confirm-key' }))
            .rejects.toThrow('ผลยืนยันการนำเข้าไม่สมบูรณ์');
    });

    it('uses production save and retry routes without submitting canonical project data to confirm', async () => {
        const post = vi.spyOn(apiClient, 'post')
            .mockResolvedValueOnce({ data: { data: rawRevision() } })
            .mockResolvedValueOnce({ data: { data: { ...rawImport(), status: 'processing' } } });
        const request = { base_revision_id: 904, idempotency_key: 'save-key', payload: emptyImportPreviewPayload() };
        await createImportPreviewRevision('import-42', request);
        const retried = await retryDocumentImport('import-42');
        expect(post).toHaveBeenNthCalledWith(1, '/api/v2/imports/import-42/preview-revisions', request);
        expect(post).toHaveBeenNthCalledWith(2, '/api/v2/imports/import-42/retry');
        expect(retried.status).toBe('processing');
    });

    it('uploads the original PDF as multipart document data and reports bounded progress', async () => {
        const post = vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: { ...rawImport(), status: 'uploaded' } } });
        const file = new File(['%PDF-1.7'], 'project.pdf', { type: 'application/pdf' });
        const progress = vi.fn();
        await uploadDocumentImport(file, progress);
        const [url, body, config] = post.mock.calls[0];
        expect(url).toBe('/api/v2/imports');
        expect((body as FormData).get('document')).toBe(file);
        expect(Array.from((body as FormData).keys())).toEqual(['document']);
        config?.onUploadProgress?.({ loaded: 25, total: 100, bytes: 25, lengthComputable: true });
        config?.onUploadProgress?.({ loaded: 120, total: 100, bytes: 95, lengthComputable: true });
        config?.onUploadProgress?.({ loaded: 1, bytes: 1, lengthComputable: false });
        expect(progress.mock.calls).toEqual([[25], [100], [null]]);
    });
});
