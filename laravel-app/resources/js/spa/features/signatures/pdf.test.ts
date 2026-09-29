import type { PDFDocumentProxy, PDFPageProxy } from 'pdfjs-dist';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { apiClient } from '@/api/client';
import { PdfPageControls } from '@/features/signatures/PdfViewer';
import { openPdf, pagePointFromClick, renderPdfPage } from '@/features/signatures/pdf';

const pdfjs = vi.hoisted(() => ({ getDocument: vi.fn(), GlobalWorkerOptions: { workerSrc: '' } }));
vi.mock('pdfjs-dist', () => pdfjs);
const originalAdapter = apiClient.defaults.adapter;
afterEach(() => { apiClient.defaults.adapter = originalAdapter; vi.clearAllMocks(); });

describe('authenticated PDF loading', () => {
    it('passes verified download bytes through the authenticated client to the local PDF.js worker', async () => {
        const pdf = { numPages: 3, destroy: vi.fn() };
        pdfjs.getDocument.mockReturnValue({ promise: Promise.resolve(pdf), destroy: vi.fn() });
        const adapter = vi.fn(async (config) => ({ data: new Blob(['%PDF-fixture'], { type: 'application/pdf' }), status: 200, statusText: 'OK', headers: {}, config }));
        apiClient.defaults.adapter = adapter;
        expect(await openPdf('/api/v2/private/download', new AbortController().signal)).toBe(pdf);
        expect(adapter.mock.calls[0][0]).toMatchObject({ url: '/api/v2/private/download', responseType: 'blob', withCredentials: true });
        expect(pdfjs.getDocument).toHaveBeenCalledWith({ data: new TextEncoder().encode('%PDF-fixture'), isEvalSupported: false, enableXfa: false });
        expect(pdfjs.GlobalWorkerOptions.workerSrc).toContain('pdf.worker');
    });

    it('rejects a non-PDF response before starting PDF.js', async () => {
        apiClient.defaults.adapter = async (config) => ({ data: new Blob(['{}'], { type: 'application/json' }), status: 200, statusText: 'OK', headers: {}, config });
        await expect(openPdf('/download', new AbortController().signal)).rejects.toThrow('ไม่ใช่ PDF');
        expect(pdfjs.getDocument).not.toHaveBeenCalled();
    });

    it('destroys the PDF.js loading task when PDF parsing fails', async () => {
        const error = new Error('Invalid PDF structure');
        const destroy = vi.fn().mockResolvedValue(undefined);
        pdfjs.getDocument.mockImplementation(() => ({ promise: Promise.reject(error), destroy }));
        apiClient.defaults.adapter = async (config) => ({ data: new Blob(['corrupt'], { type: 'application/pdf' }), status: 200, statusText: 'OK', headers: {}, config });
        const abort = new AbortController();
        await expect(openPdf('/download', abort.signal)).rejects.toBe(error);
        expect(destroy).toHaveBeenCalledOnce();
        abort.abort();
        expect(destroy).toHaveBeenCalledOnce();
    });

    it('destroys an in-progress PDF parse when navigation aborts it', async () => {
        const abort = new AbortController();
        const destroy = vi.fn();
        let resolve!: (pdf: { destroy: typeof destroy }) => void;
        const parsing = new Promise<{ destroy: typeof destroy }>((done) => { resolve = done; });
        pdfjs.getDocument.mockReturnValue({ promise: parsing, destroy });
        apiClient.defaults.adapter = async (config) => ({ data: new Blob(['%PDF'], { type: 'application/pdf' }), status: 200, statusText: 'OK', headers: {}, config });
        const loading = openPdf('/download', abort.signal);
        const rejection = expect(loading).rejects.toThrow();
        await vi.waitFor(() => expect(pdfjs.getDocument).toHaveBeenCalled());
        abort.abort();
        expect(destroy).toHaveBeenCalledOnce();
        resolve({ destroy });
        await rejection;
        expect(destroy).toHaveBeenCalledOnce();
    });
});

function fixture() {
    const context = {};
    const canvas = { width: 0, height: 0, style: { width: '', height: '' }, getContext: vi.fn(() => context) } as unknown as HTMLCanvasElement;
    const task = { promise: Promise.resolve(), cancel: vi.fn() };
    const page = { getViewport: vi.fn(({ scale }: { scale: number }) => ({ width: 600 * scale, height: 800 * scale })), render: vi.fn(() => task) };
    const document = { getPage: vi.fn(async () => page) };
    return { canvas, task, page, document, ready: vi.fn(), error: vi.fn() };
}

describe('PDF canvas lifecycle and page controls', () => {
    it('uses the clicked page button bounds to convert CSS pointer coordinates into normalized coordinates', () => {
        const getBoundingClientRect = vi.fn(() => ({ left: 50, top: 100, width: 300, height: 400 }) as DOMRect);
        const event = { currentTarget: { getBoundingClientRect }, detail: 1, clientX: 125, clientY: 300 };
        expect(pagePointFromClick(event)).toEqual({ x: 0.25, y: 0.5 });
        expect(getBoundingClientRect).toHaveBeenCalledOnce();
        getBoundingClientRect.mockReturnValue({ left: 50, top: 100, width: 600, height: 800 } as DOMRect);
        expect(pagePointFromClick({ ...event, clientX: 200, clientY: 500 })).toEqual({ x: 0.25, y: 0.5 });
        expect(pagePointFromClick({ ...event, detail: 0 })).toEqual({ x: 0.5, y: 0.5 });
        getBoundingClientRect.mockReturnValue({ left: 0, top: 0, width: 0, height: 0 } as DOMRect);
        expect(pagePointFromClick(event)).toBeNull();
    });

    it('renders a mocked PDF page fitted to the container and reports its displayed dimensions', async () => {
        const f = fixture();
        renderPdfPage(f.document as unknown as PDFDocumentProxy, 2, f.canvas, 300, 2, f.ready, f.error);
        await vi.waitFor(() => expect(f.ready).toHaveBeenCalledWith({ width: 600, height: 800 }));
        expect(f.document.getPage).toHaveBeenCalledWith(2);
        expect(f.canvas).toMatchObject({ width: 600, height: 800, style: { width: '300px', height: '400px' } });
        expect(f.page.render).toHaveBeenCalledWith(expect.objectContaining({ viewport: { width: 300, height: 400 }, transform: [2, 0, 0, 2, 0, 0] }));
        expect(f.error).not.toHaveBeenCalled();
    });

    it('cancels an active render without reporting the cancellation as an error', async () => {
        const f = fixture();
        let reject!: (error: Error) => void;
        f.task.promise = new Promise<void>((_, fail) => { reject = fail; });
        const cancel = renderPdfPage(f.document as unknown as PDFDocumentProxy, 1, f.canvas, 300, 1, f.ready, f.error);
        await vi.waitFor(() => expect(f.page.render).toHaveBeenCalled());
        cancel();
        reject(new Error('RenderingCancelledException'));
        await Promise.resolve();
        await Promise.resolve();
        expect(f.task.cancel).toHaveBeenCalledOnce();
        expect(f.ready).not.toHaveBeenCalled();
        expect(f.error).not.toHaveBeenCalled();
    });

    it('does not render a page that resolves after its canvas was unmounted', async () => {
        const f = fixture();
        let resolve!: (page: PDFPageProxy) => void;
        const document = { getPage: () => new Promise<PDFPageProxy>((done) => { resolve = done; }) } as unknown as PDFDocumentProxy;
        const cancel = renderPdfPage(document, 1, f.canvas, 300, 1, f.ready, f.error);
        cancel();
        resolve(f.page as unknown as PDFPageProxy);
        await Promise.resolve();
        expect(f.page.render).not.toHaveBeenCalled();
    });

    it('reports render failures so the viewer does not offer placement on a blank page', async () => {
        const f = fixture();
        f.document.getPage.mockRejectedValue(new Error('Corrupt page'));
        renderPdfPage(f.document as unknown as PDFDocumentProxy, 1, f.canvas, 300, 1, f.ready, f.error);
        await vi.waitFor(() => expect(f.error).toHaveBeenCalledWith(expect.objectContaining({ message: 'Corrupt page' })));
        expect(f.ready).not.toHaveBeenCalled();
    });

    it.each([[1, true, false], [3, false, true]])('renders page %s of the mocked count with navigation bounds', (page, previousDisabled, nextDisabled) => {
        const markup = renderToStaticMarkup(createElement(PdfPageControls, { page, pageCount: 3, onPageChange: vi.fn() }));
        expect(markup).toContain(`หน้า ${page} / 3`);
        const buttons = markup.match(/<button[^>]*>/g)!;
        expect(buttons[0].includes('disabled')).toBe(previousDisabled);
        expect(buttons[1].includes('disabled')).toBe(nextDisabled);
    });

    it('connects previous and next actions to the adjacent 1-based PDF page', () => {
        const onPageChange = vi.fn();
        const controls = PdfPageControls({ page: 2, pageCount: 3, onPageChange });
        const [previous, , next] = controls.props.children;
        previous.props.onClick();
        next.props.onClick();
        expect(onPageChange.mock.calls).toEqual([[1], [3]]);
        const empty = renderToStaticMarkup(createElement(PdfPageControls, { page: 1, pageCount: 0, onPageChange }));
        expect(empty.match(/<button[^>]*disabled=""/g)).toHaveLength(2);
        expect(empty).toContain('หน้า — / —');
    });
});
