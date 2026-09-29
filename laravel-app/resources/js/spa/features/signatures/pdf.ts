import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

import { apiClient } from '@/api/client';

export interface PageSize { width: number; height: number }

export function pagePointFromClick(event: { currentTarget: Pick<HTMLElement, 'getBoundingClientRect'>; detail: number; clientX: number; clientY: number }): { x: number; y: number } | null {
    const bounds = event.currentTarget.getBoundingClientRect();
    if (bounds.width <= 0 || bounds.height <= 0) return null;
    // Keyboard activation chooses the page centre; pointer clicks use CSS dimensions.
    return event.detail === 0 ? { x: 0.5, y: 0.5 } : { x: (event.clientX - bounds.left) / bounds.width, y: (event.clientY - bounds.top) / bounds.height };
}

export async function openPdf(url: string, signal: AbortSignal): Promise<PDFDocumentProxy> {
    const response = await apiClient.get<Blob>(url, { responseType: 'blob', signal });
    if (!(response.data instanceof Blob) || response.data.type.split(';')[0].toLowerCase() !== 'application/pdf') {
        throw new Error('ไฟล์ที่ได้รับไม่ใช่ PDF');
    }
    const [bytes, pdfjs] = await Promise.all([response.data.arrayBuffer(), import('pdfjs-dist')]);
    signal.throwIfAborted();
    pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
    const loading = pdfjs.getDocument({ data: new Uint8Array(bytes), isEvalSupported: false, enableXfa: false });
    let destruction: Promise<void> | undefined;
    const destroy = () => destruction ??= Promise.resolve(loading.destroy()).catch(() => undefined);
    const abort = () => { void destroy(); };
    signal.addEventListener('abort', abort, { once: true });
    try {
        const document = await loading.promise;
        signal.throwIfAborted();
        return document;
    } catch (error) {
        // PDF.js rejects DocException without destroying its worker/transport.
        await destroy();
        throw error;
    } finally {
        signal.removeEventListener('abort', abort);
    }
}

// Each invocation owns a canvas. Cancelling also handles an unresolved getPage().
export function renderPdfPage(
    document: PDFDocumentProxy,
    page: number,
    canvas: HTMLCanvasElement,
    containerWidth: number,
    pixelRatio: number,
    onReady: (size: PageSize) => void,
    onError: (error: unknown) => void,
): () => void {
    let cancelled = false;
    let task: RenderTask | undefined;
    void (async () => {
        const pdfPage = await document.getPage(page);
        if (cancelled) return;
        const original = pdfPage.getViewport({ scale: 1 });
        const viewport = pdfPage.getViewport({ scale: Math.max(1, containerWidth) / original.width });
        const ratio = Math.max(1, Math.min(pixelRatio, 2));
        const context = canvas.getContext('2d');
        if (!context) throw new Error('เบราว์เซอร์ไม่รองรับการแสดง PDF');
        canvas.width = Math.ceil(viewport.width * ratio);
        canvas.height = Math.ceil(viewport.height * ratio);
        canvas.style.width = `${viewport.width}px`;
        canvas.style.height = `${viewport.height}px`;
        task = pdfPage.render({ canvas, canvasContext: context, viewport, transform: ratio === 1 ? undefined : [ratio, 0, 0, ratio, 0, 0] });
        await task.promise;
        if (!cancelled) onReady({ width: original.width, height: original.height });
    })().catch((error: unknown) => { if (!cancelled) onError(error); });
    return () => { cancelled = true; task?.cancel(); };
}
