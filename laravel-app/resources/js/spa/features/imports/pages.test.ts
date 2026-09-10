import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { type ComponentType, createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { DocumentImport, DocumentImportOptions, ImportPreviewRevision, PaginatedDocumentImports } from '@/api/contracts';
import { importKeys } from '@/features/imports/api';
import { ImportDetailPage } from '@/features/imports/ImportDetailPage';
import { ImportListPage } from '@/features/imports/ImportListPage';
import { ImportUploadPage } from '@/features/imports/ImportUploadPage';
import { emptyImportPreviewPayload } from '@/features/imports/model';

const auth = vi.hoisted(() => ({ permissions: new Set<string>() }));
vi.mock('@/auth/AuthContext', () => ({
    useAuth: () => ({ hasPermission: (permission: string) => auth.permissions.has(permission) }),
}));

const preview = (): ImportPreviewRevision => ({
    id: 904, revision_no: 3, source: 'ai', payload: emptyImportPreviewPayload(),
    validation_errors: {}, warnings: [], confidence: {}, edited_by: null,
    created_at: '2026-09-08T01:00:00Z',
});

const documentImport = (overrides: Partial<DocumentImport> = {}): DocumentImport => ({
    id: 42, public_id: 'import-42', status: 'processing', processing_stage: 'extracting_text',
    original: { name: 'project.pdf', mime_type: 'application/pdf', size_bytes: 2048, sha256: 'a'.repeat(64), download_url: null },
    uploader: { id: 1, name: 'ผู้ส่ง' }, latest_run: null, original_extraction: null,
    current_preview: null, confirmed_project: null, failure: null,
    abilities: { view_original: true, review: false, retry: false, confirm: false },
    extracted_at: null, confirmed_at: null,
    created_at: '2026-09-08T01:00:00Z', updated_at: '2026-09-08T01:00:00Z',
    ...overrides,
});

function renderPage(Page: ComponentType, path: string, seed: (client: QueryClient) => void): string {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } });
    seed(client);
    const markup = renderToStaticMarkup(createElement(QueryClientProvider, { client },
        createElement(MemoryRouter, { initialEntries: [path] },
            createElement(Routes, null,
                createElement(Route, { path: '/imports', element: createElement(Page) }),
                createElement(Route, { path: '/imports/new', element: createElement(Page) }),
                createElement(Route, { path: '/imports/:importId', element: createElement(Page) }),
            ),
        ),
    ));
    client.clear();
    return markup;
}

function renderDetail(item: DocumentImport): string {
    return renderPage(ImportDetailPage, '/imports/import-42', (client) => {
        client.setQueryData(importKeys.detail('import-42'), item);
        client.setQueryData(importKeys.revisions('import-42'), item.current_preview ? [item.current_preview] : []);
    });
}

beforeEach(() => auth.permissions.clear());

describe('document import pages', () => {
    it('renders processing state and original evidence without canonical creation controls', () => {
        const markup = renderDetail(documentImport());
        expect(markup).toContain('กำลังอ่านข้อความจาก PDF');
        expect(markup).toContain('ทุก 2 วินาที');
        expect(markup).toMatch(/<button[^>]*type="button">เปิดไฟล์ต้นฉบับ<\/button>/);
        expect(markup).not.toContain('href="/api/v2/imports/import-42/original"');
        expect(markup).not.toContain('ลองประมวลผลใหม่');
        expect(markup).not.toContain('ยืนยันและสร้างโครงการ');
    });

    it('shows failed retry and original-download actions only when the record grants those abilities', () => {
        const failed = documentImport({
            status: 'failed', processing_stage: null,
            failure: { code: 'unreadable_pdf', stage: 'extracting_text', message: '<script>failure</script>' },
            abilities: { view_original: false, review: false, retry: false, confirm: false },
        });
        const readOnly = renderDetail(failed);
        expect(readOnly).toContain('&lt;script&gt;failure&lt;/script&gt;');
        expect(readOnly).not.toContain('href="/api/v2/imports/import-42/original"');
        expect(readOnly).not.toContain('เปิดไฟล์ต้นฉบับ');
        expect(readOnly).not.toContain('ลองประมวลผลใหม่');

        const allowed = renderDetail({ ...failed, abilities: { ...failed.abilities, retry: true } });
        expect(allowed).toContain('ลองประมวลผลใหม่');
        expect(allowed).not.toContain('ทุก 2 วินาที');
    });

    it('lets viewers inspect preview history while confirmed imports link to their existing project', () => {
        const review = renderDetail(documentImport({ status: 'needs_review', current_preview: preview() }));
        expect(review).toContain('ดู Preview แบบอ่านอย่างเดียว');
        expect(review).toContain('Revision 3');
        expect(review).not.toContain('ยืนยันและสร้างโครงการ');

        const confirmed = renderDetail(documentImport({
            status: 'confirmed', current_preview: preview(), confirmed_project: { id: 71, name: 'โครงการ' },
        }));
        expect(confirmed).toContain('href="/projects/71"');
        expect(confirmed).not.toContain('href="/imports/import-42/preview"');
    });

    it('renders filtered paginated imports and hides upload for users without create permission', () => {
        const renderList = () => renderPage(ImportListPage, '/imports?status=needs_review&page=2', (client) => {
            const list: PaginatedDocumentImports = {
                data: [documentImport({ status: 'needs_review', current_preview: preview() })],
                meta: { current_page: 2, last_page: 2, from: 16, to: 16, per_page: 15, total: 16 },
                links: { first: null, last: null, prev: null, next: null },
            };
            client.setQueryData(importKeys.list({ status: 'needs_review', page: 2, per_page: 15 }), list);
        });
        const readOnly = renderList();
        expect(readOnly).toContain('project.pdf');
        expect(readOnly).toContain('หน้า 2 / 2');
        expect(readOnly).toContain('href="/imports/import-42/preview"');
        expect(readOnly).not.toContain('href="/imports/new"');
        auth.permissions.add('imports.create');
        expect(renderList()).toContain('href="/imports/new"');
    });

    it('uses server upload limits and keeps submission disabled until a PDF is selected', () => {
        auth.permissions.add('imports.create');
        const markup = renderPage(ImportUploadPage, '/imports/new', (client) => {
            const options: DocumentImportOptions = {
                constraints: { max_bytes: 2 * 1024 * 1024, accepted_mime_types: ['application/pdf'] },
                project_options: { departments: [], project_categories: [], academic_years: [], fiscal_years: [], school_plans: [], execution_statuses: [], evaluation_statuses: [], budget_sources: [] },
            };
            client.setQueryData(importKeys.options, options);
        });
        expect(markup).toContain('2 MB');
        expect(markup).toContain('accept=".pdf,application/pdf"');
        expect(markup).toMatch(/<button[^>]*disabled=""[^>]*type="submit"/);
    });
});
