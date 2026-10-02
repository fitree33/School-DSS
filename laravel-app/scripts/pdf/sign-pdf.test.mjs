import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { PDFDocument, PDFName, PDFNumber, degrees, rgb } from 'pdf-lib';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

import { imageMatrix, stampPdf } from './sign-pdf.mjs';

// PDF.js's own optional Node renderer, already pinned by its dependency lock.
const require = createRequire(import.meta.url);
const { createCanvas } = createRequire(require.resolve('pdfjs-dist/package.json'))('@napi-rs/canvas');
const rectangle = { page: 2, x: 0.2, y: 0.25, width: 0.25, height: 0.125 };

function signatureImage() {
    const canvas = createCanvas(80, 40);
    const context = canvas.getContext('2d');
    // Asymmetric colored quadrants expose mirror and rotation mistakes in raster output.
    for (const [color, x, y] of [['red', 0, 0], ['lime', 40, 0], ['blue', 0, 20], ['black', 40, 20]]) {
        context.fillStyle = color;
        context.fillRect(x, y, 40, 20);
    }
    return canvas.toBuffer('image/png');
}

async function sourcePdf(rotation = 0, userUnit = 1, crop = 'inside') {
    const document = await PDFDocument.create();
    document.addPage([400, 600]);
    const page = document.addPage([400, 600]);
    page.setRotation(degrees(rotation));
    page.node.set(PDFName.of('UserUnit'), PDFNumber.of(userUnit));
    if (crop === 'inside') page.setCropBox(40, 60, 300, 420);
    if (crop === 'intersect') page.setCropBox(-40, 60, 340, 600);
    page.drawRectangle({ x: 80, y: 100, width: 5, height: 5, color: rgb(0.5, 0.5, 0.5) });
    return document.save();
}

async function rendered(bytes, pageNo = 2) {
    const loading = getDocument({ data: Uint8Array.from(bytes), isEvalSupported: false, enableXfa: false, verbosity: 0 });
    try {
        const document = await loading.promise;
        const page = await document.getPage(pageNo);
        const viewport = page.getViewport({ scale: 1 });
        const canvas = createCanvas(Math.ceil(viewport.width), Math.ceil(viewport.height));
        await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
        return { canvas, width: viewport.width, height: viewport.height };
    } finally {
        await loading.destroy();
    }
}

function pixel(render, x, y) {
    return [...render.canvas.getContext('2d').getImageData(Math.floor(x * render.width), Math.floor(y * render.height), 1, 1).data];
}

for (const rotation of [0, 90, 180, 270]) {
    for (const userUnit of [1, 2]) {
        for (const crop of ['inside', 'intersect']) {
            test(`raster placement stays upright at ${rotation} degrees, UserUnit ${userUnit}, ${crop} CropBox`, async () => {
                const source = await sourcePdf(rotation, userUnit, crop);
                const before = createHash('sha256').update(source).digest('hex');
                const result = await stampPdf(source, signatureImage(), rectangle);
                assert.equal(result.pageCount, 2);
                assert.equal(createHash('sha256').update(source).digest('hex'), before);
                assert.notEqual(createHash('sha256').update(result.bytes).digest('hex'), before);
                const render = await rendered(result.bytes);
                for (const [dx, dy, color] of [[0.25, 0.25, [255, 0, 0, 255]], [0.75, 0.25, [0, 255, 0, 255]],
                    [0.25, 0.75, [0, 0, 255, 255]], [0.75, 0.75, [0, 0, 0, 255]]]) {
                    assert.deepEqual(pixel(render, rectangle.x + dx * rectangle.width, rectangle.y + dy * rectangle.height), color);
                }
                assert.deepEqual(pixel(render, rectangle.x - 0.02, rectangle.y + rectangle.height / 2), [255, 255, 255, 255]);
                assert.deepEqual(pixel(render, rectangle.x + rectangle.width + 0.02, rectangle.y + rectangle.height / 2), [255, 255, 255, 255]);
                // An untouched page still renders identically.
                const originalOther = await rendered(source, 1);
                const generatedOther = await rendered(result.bytes, 1);
                assert.deepEqual(generatedOther.canvas.toBuffer('image/png'), originalOther.canvas.toBuffer('image/png'));
            });
        }
    }
}

test('edge placement and inherited page geometry use the actual PDF.js viewport', async () => {
    const document = await PDFDocument.load(await sourcePdf());
    const page = document.getPage(1);
    const parent = page.node.Parent();
    parent.set(PDFName.of('Rotate'), PDFNumber.of(90));
    parent.set(PDFName.of('CropBox'), document.context.obj([40, 60, 340, 480]));
    page.node.delete(PDFName.of('Rotate'));
    page.node.delete(PDFName.of('CropBox'));
    const placement = { page: 2, x: 0.75, y: 0.875, width: 0.25, height: 0.125 };
    const result = await stampPdf(await document.save(), signatureImage(), placement);
    const render = await rendered(result.bytes);
    assert.deepEqual(pixel(render, 0.8, 0.9), [255, 0, 0, 255]);
    assert.deepEqual(pixel(render, 0.95, 0.975), [0, 0, 0, 255]);
});

test('invalid placement, missing page and corrupt input fail closed', async () => {
    const source = await sourcePdf();
    for (const changes of [{ page: 0 }, { page: 3 }, { page: 1.5 }, { x: -0.1 }, { width: 0 }, { y: 0.95 }, { height: NaN }]) {
        await assert.rejects(stampPdf(source, signatureImage(), { ...rectangle, ...changes }));
    }
    await assert.rejects(stampPdf(Buffer.from('not a readable PDF'), signatureImage(), rectangle));
    await assert.rejects(stampPdf(source, Buffer.from('not a PNG image'), rectangle));
    assert.throws(() => imageMatrix({ width: 0, height: 1 }, rectangle));
});

test('transparent signature pixels preserve the original page content', async () => {
    const document = await PDFDocument.create();
    const page = document.addPage([400, 600]);
    page.drawRectangle({ x: 0, y: 0, width: 400, height: 600, color: rgb(1, 1, 0) });
    const canvas = createCanvas(80, 40);
    const context = canvas.getContext('2d');
    context.fillStyle = 'red';
    context.fillRect(0, 0, 40, 20);
    const result = await stampPdf(await document.save(), canvas.toBuffer('image/png'), { ...rectangle, page: 1 });
    const render = await rendered(result.bytes, 1);
    assert.deepEqual(pixel(render, 0.25, 0.28), [255, 0, 0, 255]);
    assert.deepEqual(pixel(render, 0.4, 0.35), [255, 255, 0, 255]);
    assert.deepEqual(pixel(render, 0.1, 0.1), [255, 255, 0, 255]);
});

test('an encrypted source is never accepted through ignoreEncryption', async () => {
    const document = await PDFDocument.load(await sourcePdf());
    document.context.trailerInfo.Encrypt = document.context.register(document.context.obj({ Filter: 'Standard', V: 1, R: 2 }));
    await assert.rejects(stampPdf(await document.save(), signatureImage(), rectangle), /encrypted/i);
});

test('CLI creates a new PDF without changing source and refuses an existing output', async () => {
    const directory = await mkdtemp(join(tmpdir(), 'school-dss-signing-'));
    try {
        const source = join(directory, 'source.pdf');
        const image = join(directory, 'signature.png');
        const output = join(directory, 'signed.pdf');
        const sourceBytes = await sourcePdf();
        await writeFile(source, sourceBytes);
        await writeFile(image, signatureImage());
        const args = [fileURLToPath(new URL('./sign-pdf.mjs', import.meta.url)), source, image, output, '2', '0.2', '0.25', '0.25', '0.125'];
        const first = spawnSync(process.execPath, args, { encoding: 'utf8', timeout: 45_000 });
        assert.equal(first.status, 0, first.stderr);
        assert.deepEqual(JSON.parse(first.stdout), { page_count: 2 });
        assert.deepEqual(await readFile(source), Buffer.from(sourceBytes));
        const signed = await readFile(output);
        const again = spawnSync(process.execPath, args, { encoding: 'utf8', timeout: 45_000 });
        assert.equal(again.status, 1);
        assert.equal(again.stdout, '');
        assert.equal(again.stderr, 'PDF signing generation failed.\n');
        assert.deepEqual(await readFile(output), signed);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});
