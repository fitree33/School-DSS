import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import type { ImportPreviewRevision } from '@/api/contracts';
import { ImportWarnings, OriginalExtractionPanel, RevisionHistory } from '@/features/imports/ImportPanels';
import { emptyImportPreviewPayload } from '@/features/imports/model';

describe('import evidence panels', () => {
    it('renders AI values and warnings as escaped read-only text', () => {
        const markup = renderToStaticMarkup(createElement(OriginalExtractionPanel, { snapshot: {
            values: { name: '<script>alert("unsafe")</script>' },
            confidence: { name: 0.75 }, warnings: ['<img src=x onerror=alert(1)>'],
        } }));
        expect(markup).toContain('&lt;script&gt;');
        expect(markup).toContain('&lt;img');
        expect(markup).toContain('AI 75%');
        expect(markup).not.toContain('<script>');
        expect(markup).not.toContain('<input');
        expect(markup).not.toContain('<textarea');
    });

    it('keeps historical payloads intact and identifies the current row by ID', () => {
        const revision: ImportPreviewRevision = {
            id: 904, revision_no: 3, source: 'user', payload: emptyImportPreviewPayload(),
            raw_payload: { name: 'หลักฐานต้นฉบับ', budget: null, indicators: null },
            validation_errors: { budget: ['Required.'] }, warnings: [], confidence: {},
            edited_by: { id: 1, name: 'ผู้ตรวจสอบ' }, created_at: '2026-09-07T01:00:00.000Z',
        };
        const older = { ...revision, id: 903, revision_no: 2 };
        const markup = renderToStaticMarkup(createElement(RevisionHistory, { revisions: [older, revision], currentRevisionId: 904 }));
        expect(markup.indexOf('Revision 3')).toBeLessThan(markup.indexOf('Revision 2'));
        expect(markup.match(/ฉบับปัจจุบัน/g)).toHaveLength(1);
        expect(markup).toContain('หลักฐานต้นฉบับ');
        expect(markup).toContain('Required.');
        expect(markup).not.toContain('<input');
        expect(revision.raw_payload).toEqual({ name: 'หลักฐานต้นฉบับ', budget: null, indicators: null });
    });

    it('omits empty warnings', () => {
        expect(renderToStaticMarkup(createElement(ImportWarnings, { warnings: [] }))).toBe('');
    });
});
