import type { PDFDocumentProxy } from 'pdfjs-dist';
import { useEffect, useRef, useState } from 'react';

import { openPdf, pagePointFromClick, renderPdfPage, type PageSize } from '@/features/signatures/pdf';

export interface PreviewRectangle { page: number; x: number; y: number; width: number; height: number }
interface PdfViewerProps {
    url: string;
    page: number;
    onPageChange: (page: number) => void;
    onDocumentLoad: (pageCount: number) => void;
    onPageReady?: (size: PageSize) => void;
    placement: PreviewRectangle | null;
    onPlace?: (point: { x: number; y: number }, size: PageSize) => void;
}

export function PdfViewer(props: PdfViewerProps) {
    const { url, page, onDocumentLoad } = props;
    const [loaded, setLoaded] = useState<{ url: string; document: PDFDocumentProxy } | null>(null);
    const [failure, setFailure] = useState<{ url: string; message: string } | null>(null);
    const [width, setWidth] = useState(640);
    const container = useRef<HTMLDivElement>(null);
    const onLoad = useRef(onDocumentLoad);
    onLoad.current = onDocumentLoad;
    useEffect(() => {
        const controller = new AbortController();
        let document: PDFDocumentProxy | undefined;
        setFailure(null);
        setLoaded(null);
        void openPdf(url, controller.signal).then((pdf) => {
            document = pdf;
            if (controller.signal.aborted) { void pdf.destroy(); return; }
            setLoaded({ url, document: pdf });
            onLoad.current(pdf.numPages);
        }).catch((error: unknown) => {
            if (!controller.signal.aborted) setFailure({ url, message: error instanceof Error ? error.message : 'ไม่สามารถเปิด PDF ได้' });
        });
        return () => { controller.abort(); void document?.destroy(); };
    }, [url]);
    useEffect(() => {
        const element = container.current;
        if (!element) return;
        const observer = new ResizeObserver(([entry]) => { if (entry.contentRect.width > 0) setWidth(Math.floor(entry.contentRect.width)); });
        observer.observe(element);
        return () => observer.disconnect();
    }, []);
    const document = loaded?.url === url ? loaded.document : null;
    const error = failure?.url === url ? failure.message : null;
    return <section aria-label="PDF viewer" className="space-y-3">
        <PdfPageControls page={page} pageCount={document?.numPages ?? 0} onPageChange={props.onPageChange} />
        {error && <p className="text-sm text-rose-700" role="alert">{error}</p>}
        {!document && !error && <p role="status">กำลังโหลด PDF…</p>}
        <div className="w-full min-w-0 overflow-hidden rounded-lg bg-slate-100" ref={container}>
            {document && page >= 1 && page <= document.numPages && <PdfCanvas key={`${url}:${page}:${width}`} {...props} document={document} width={width} />}
        </div>
    </section>;
}

export function PdfPageControls({ page, pageCount, onPageChange }: { page: number; pageCount: number; onPageChange: (page: number) => void }) {
    return <nav aria-label="PDF pages" className="flex flex-wrap items-center gap-3">
        <button className="spa-button-secondary" disabled={pageCount === 0 || page <= 1} onClick={() => onPageChange(page - 1)} type="button">หน้าก่อนหน้า</button>
        <span aria-live="polite">หน้า {pageCount === 0 ? '—' : page} / {pageCount || '—'}</span>
        <button className="spa-button-secondary" disabled={pageCount === 0 || page >= pageCount} onClick={() => onPageChange(page + 1)} type="button">หน้าถัดไป</button>
    </nav>;
}

function PdfCanvas({ document, width, page, placement, onPlace, onPageReady }: PdfViewerProps & { document: PDFDocumentProxy; width: number }) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const [size, setSize] = useState<PageSize | null>(null);
    const [error, setError] = useState<string | null>(null);
    const ready = useRef(onPageReady);
    ready.current = onPageReady;
    useEffect(() => {
        if (!canvas.current) return;
        return renderPdfPage(document, page, canvas.current, width, window.devicePixelRatio || 1,
            (dimensions) => { setSize(dimensions); ready.current?.(dimensions); },
            (reason) => setError(reason instanceof Error ? reason.message : 'ไม่สามารถแสดงหน้านี้ได้'));
    }, [document, page, width]);
    const preview = placement?.page === page ? placement : null;
    return <div>
        {!size && !error && <p className="p-3" role="status">กำลังแสดงหน้า PDF…</p>}
        {error && <p className="p-3 text-rose-700" role="alert">{error}</p>}
        <div className="relative w-fit" style={{ visibility: size ? 'visible' : 'hidden' }}>
            <canvas aria-label={`PDF page ${page}`} className="block bg-white" ref={canvas} />
            {size && onPlace && <button aria-label="เลือกตำแหน่งลายเซ็นบนหน้า PDF" className="absolute inset-0 cursor-crosshair focus-visible:ring-2 focus-visible:ring-teal-600" onClick={(event) => {
                const point = pagePointFromClick(event);
                if (point) onPlace(point, size);
            }} type="button" />}
            {size && preview && <span aria-label="Signature placement preview" className="pointer-events-none absolute flex items-center justify-center overflow-hidden border-2 border-teal-600 bg-teal-100/50 text-xs text-teal-900" style={{ left: `${preview.x * 100}%`, top: `${preview.y * 100}%`, width: `${preview.width * 100}%`, height: `${preview.height * 100}%` }}>ลายเซ็น</span>}
        </div>
    </div>;
}
