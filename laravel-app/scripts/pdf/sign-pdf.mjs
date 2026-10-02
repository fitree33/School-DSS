import { readFile, stat, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

import { PDFDocument, concatTransformationMatrix, popGraphicsState, pushGraphicsState } from 'pdf-lib';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

const MAX_PDF_BYTES = 100_000_000;
const MAX_IMAGE_BYTES = 10_000_000;

/** Invert the very same displayed viewport used by the placement editor.
 * PDF image coordinates start at bottom-left; the saved rectangle starts at top-left.
 * Three corners preserve the image's upright orientation for every page rotation.
 * PDF.js supplies the effective crop/media intersection, inherited rotation and UserUnit.
 */
export function imageMatrix(viewport, rectangle) {
    const { x, y, width, height } = rectangle;
    if (![x, y, width, height].every(Number.isFinite)
        || x < 0 || y < 0 || width <= 0 || height <= 0
        || x + width > 1 + 1e-10 || y + height > 1 + 1e-10
        || !Number.isFinite(viewport.width) || !Number.isFinite(viewport.height)
        || viewport.width <= 0 || viewport.height <= 0) {
        throw new Error('Invalid normalized placement.');
    }
    const bottomLeft = viewport.convertToPdfPoint(x * viewport.width, (y + height) * viewport.height);
    const bottomRight = viewport.convertToPdfPoint((x + width) * viewport.width, (y + height) * viewport.height);
    const topLeft = viewport.convertToPdfPoint(x * viewport.width, y * viewport.height);
    const matrix = [
        bottomRight[0] - bottomLeft[0], bottomRight[1] - bottomLeft[1],
        topLeft[0] - bottomLeft[0], topLeft[1] - bottomLeft[1],
        bottomLeft[0], bottomLeft[1],
    ];
    if (!matrix.every(Number.isFinite)) throw new Error('Invalid PDF viewport.');
    return matrix;
}

function openForInspection(bytes) {
    // Copy because PDF.js transfers the buffer to its worker. No URL/network loading.
    return getDocument({ data: Uint8Array.from(bytes), isEvalSupported: false, enableXfa: false, stopAtErrors: true, verbosity: 0 });
}

export async function stampPdf(sourceBytes, imageBytes, rectangle) {
    if (sourceBytes.length < 8 || sourceBytes.length > MAX_PDF_BYTES
        || imageBytes.length < 8 || imageBytes.length > MAX_IMAGE_BYTES) throw new Error('Input size is invalid.');
    // Never bypass encryption. A password-protected PDF is not a signing source.
    const document = await PDFDocument.load(sourceBytes, { updateMetadata: false, throwOnInvalidObject: true });
    const source = openForInspection(sourceBytes);
    let generated;
    try {
        const inspected = await source.promise;
        if (!Number.isInteger(rectangle.page) || rectangle.page < 1 || rectangle.page > inspected.numPages
            || document.getPageCount() !== inspected.numPages) throw new Error('Invalid signing page.');
        const sourcePage = await inspected.getPage(rectangle.page);
        const viewport = sourcePage.getViewport({ scale: 1 });
        const matrix = imageMatrix(viewport, rectangle);
        await sourcePage.getOperatorList();
        const image = await document.embedPng(imageBytes);
        const page = document.getPage(rectangle.page - 1);
        page.pushOperators(pushGraphicsState(), concatTransformationMatrix(...matrix));
        page.drawImage(image, { x: 0, y: 0, width: 1, height: 1 });
        page.pushOperators(popGraphicsState());
        const bytes = await document.save({ useObjectStreams: true, addDefaultPage: false, updateFieldAppearances: false });
        if (bytes.length > MAX_PDF_BYTES) throw new Error('Generated PDF exceeds limit.');
        // Reopen and parse the generated page before allowing any canonical completion.
        generated = openForInspection(bytes);
        const checked = await generated.promise;
        const reopened = await PDFDocument.load(bytes, { updateMetadata: false, throwOnInvalidObject: true });
        if (checked.numPages !== inspected.numPages || reopened.getPageCount() !== inspected.numPages) {
            throw new Error('Generated page count mismatch.');
        }
        const checkedPage = await checked.getPage(rectangle.page);
        const checkedViewport = checkedPage.getViewport({ scale: 1 });
        if (checkedViewport.width !== viewport.width || checkedViewport.height !== viewport.height
            || checkedViewport.transform.some((value, index) => value !== viewport.transform[index])) {
            throw new Error('Generated page geometry mismatch.');
        }
        await checkedPage.getOperatorList();
        return { bytes, pageCount: checked.numPages };
    } finally {
        await Promise.allSettled([source.destroy(), generated?.destroy()]);
    }
}

async function main(args) {
    if (args.length !== 8) throw new Error('Invalid arguments.');
    const [source, image, output, ...values] = args;
    if (resolve(source) === resolve(output) || resolve(image) === resolve(output)) throw new Error('Output must be new.');
    const [sourceInfo, imageInfo] = await Promise.all([stat(source), stat(image)]);
    if (!sourceInfo.isFile() || sourceInfo.size > MAX_PDF_BYTES || !imageInfo.isFile() || imageInfo.size > MAX_IMAGE_BYTES) {
        throw new Error('Input size is invalid.');
    }
    const [sourceBytes, imageBytes] = await Promise.all([readFile(source), readFile(image)]);
    const [page, x, y, width, height] = values.map(Number);
    const result = await stampPdf(sourceBytes, imageBytes, { page, x, y, width, height });
    // The service owns an unused path in its private snapshot directory. Never overwrite.
    await writeFile(output, result.bytes, { flag: 'wx', mode: 0o600 });
    process.stdout.write(JSON.stringify({ page_count: result.pageCount }));
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    main(process.argv.slice(2)).catch(() => {
        // Paths, bytes and parser diagnostics must not escape the worker.
        process.stderr.write('PDF signing generation failed.\n');
        process.exitCode = 1;
    });
}
