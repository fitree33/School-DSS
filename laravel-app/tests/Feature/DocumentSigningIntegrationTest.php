<?php

namespace Tests\Feature;

use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Models\AuditLog;
use App\Models\DocumentSignature;
use App\Models\DocumentVersion;
use App\Services\Signatures\GdSignatureImageNormalizer;
use App\Services\Signatures\SignatureAssetStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\BuildsProjectSignatureSlots;
use Tests\Feature\Concerns\UsesPrivateSignatureStorage;
use Tests\TestCase;

/** Real parser, renderer, image normalization and private files; SQLite only. */
class DocumentSigningIntegrationTest extends TestCase
{
    use BuildsProjectSignatureSlots;
    use RefreshDatabase;
    use UsesPrivateSignatureStorage;

    private string $runtimeDirectory;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectSignatures();
        $parent = realpath(base_path('..')).DIRECTORY_SEPARATOR.'.foundation-runtime'.DIRECTORY_SEPARATOR.'phase6d';
        File::ensureDirectoryExists($parent, 0700);
        $this->runtimeDirectory = $parent.DIRECTORY_SEPARATOR.'integration-'.Str::uuid();
        File::ensureDirectoryExists($this->runtimeDirectory, 0700);
        foreach (['source', 'assets', 'temporary', 'fixtures'] as $name) {
            $path = $this->runtimeDirectory.DIRECTORY_SEPARATOR.$name;
            File::ensureDirectoryExists($path, 0700);
            $this->protectSignatureFixtureDirectory($path);
        }
        config()->set([
            'filesystems.disks.signing-integration-source' => [
                'driver' => 'local', 'root' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'source', 'visibility' => 'private',
            ],
            'filesystems.disks.signed-documents.root' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'generated',
            'document_versions.allowed_disks' => ['signing-integration-source', 'signed-documents'],
            'document_versions.temporary_directory' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'snapshots',
            'document_signing.storage_disk' => 'signed-documents',
            'signature_assets.storage_root' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'assets',
            'signature_assets.temporary_directory' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'temporary',
        ]);
        Storage::forgetDisk('signing-integration-source');
        Storage::forgetDisk('signed-documents');
        $this->beforeApplicationDestroyed(function () use ($parent): void {
            Storage::forgetDisk('signing-integration-source');
            Storage::forgetDisk('signed-documents');
            $this->assertSame(realpath($parent), realpath(dirname($this->runtimeDirectory)));
            $this->assertSame($this->runtimeDirectory, realpath($this->runtimeDirectory));
            File::deleteDirectory($this->runtimeDirectory);
        });
    }

    public function test_real_signing_pipeline_preserves_source_stamps_saved_rectangle_and_replays_verified_result(): void
    {
        $owner = $this->signatureUser();
        $project = $this->signatureProject($owner);
        $slot = $project->signatureSlots()->where('slot_code', 'project_proposer')->firstOrFail();
        $slot->update(['assigned_user_id' => $owner->id, 'assignment_revision' => 1, 'assigned_by' => $owner->id, 'assigned_at' => now()]);
        $sourcePath = $this->runtimeDirectory.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'original.pdf';
        $imagePath = $this->runtimeDirectory.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'signature.png';
        $this->node(<<<'JS'
            import { writeFile } from 'node:fs/promises';
            import { createRequire } from 'node:module';
            import { PDFDocument, PDFName, PDFNumber, degrees, rgb } from 'pdf-lib';
            const require = createRequire(import.meta.url);
            const { createCanvas } = createRequire(require.resolve('pdfjs-dist/package.json'))('@napi-rs/canvas');
            const document = await PDFDocument.create();
            document.addPage([400, 600]).drawRectangle({ x: 30, y: 40, width: 20, height: 30, color: rgb(0.5, 0.5, 0.5) });
            const page = document.addPage([400, 600]);
            page.setCropBox(40, 60, 300, 420);
            page.setRotation(degrees(90));
            page.node.set(PDFName.of('UserUnit'), PDFNumber.of(2));
            const canvas = createCanvas(80, 40);
            const context = canvas.getContext('2d');
            for (const [color, x, y] of [['red', 0, 0], ['lime', 40, 0], ['blue', 0, 20], ['black', 40, 20]]) {
                context.fillStyle = color;
                context.fillRect(x, y, 40, 20);
            }
            await writeFile(process.argv[1], await document.save(), { flag: 'wx' });
            await writeFile(process.argv[2], canvas.toBuffer('image/png'), { flag: 'wx' });
            JS, [$sourcePath, $imagePath]);
        $sourceBytes = file_get_contents($sourcePath);
        $document = $project->documents()->create([
            'original_name' => 'original.pdf', 'path' => 'original.pdf', 'storage_disk' => 'signing-integration-source',
            'mime_type' => 'application/pdf', 'size' => strlen($sourceBytes), 'uploaded_by' => $owner->id,
            'checksum' => hash('sha256', $sourceBytes), 'version' => 1,
        ]);
        $source = $document->versions()->create([
            'revision_no' => 1, 'created_via' => DocumentVersionCreatedVia::Backfill,
            'storage_disk' => 'signing-integration-source', 'storage_path' => 'original.pdf', 'original_name' => 'original.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => strlen($sourceBytes), 'sha256' => hash('sha256', $sourceBytes),
            'integrity_basis' => DocumentVersionIntegrityBasis::RecordedSha256, 'created_by' => null, 'verified_at' => now(),
        ])->refresh();
        $sourceRow = $source->getRawOriginal();
        $assets = app(SignatureAssetStorage::class);
        $workspace = $assets->beginRequest();
        $receipt = null;
        try {
            $normalized = app(GdSignatureImageNormalizer::class)->normalize($imagePath, $workspace->directory());
            $receipt = $assets->storeNormalized($normalized, (string) Str::uuid(), $workspace);
            $asset = $this->signatureAssetForReceipt($receipt);
            $asset->owner_id = $owner->id;
            $asset->save();
        } finally {
            if ($receipt !== null) $assets->preserve($receipt);
            $assets->cleanup($workspace);
        }
        $root = "/api/v2/projects/{$project->id}/documents/{$document->id}/versions/{$source->public_id}";
        $placement = $this->actingAs($owner)->putJson($root."/placements/{$slot->id}", [
            'signature_asset_id' => $asset->public_id, 'assignment_revision' => 1,
            'page' => 2, 'x' => 0.2, 'y' => 0.25, 'width' => 0.25, 'height' => 0.125,
        ])->assertSuccessful()->json('data');
        $this->assertDatabaseCount('document_signatures', 0);
        $this->assertDatabaseCount('document_versions', 1);
        $payload = ['assignment_revision' => 1, 'placement_fingerprint' => $placement['fingerprint'], 'idempotency_key' => (string) Str::uuid()];
        $response = $this->postJson($root."/signatures/{$slot->id}", $payload)->assertCreated();
        $signed = DocumentVersion::query()->where('public_id', $response->json('data.signed_version.public_id'))->sole();
        $evidence = DocumentSignature::query()->sole();
        $signedPath = Storage::disk($signed->storage_disk)->path($signed->storage_path);
        $signedBytes = file_get_contents($signedPath);
        $this->assertSame($sourceRow, $source->refresh()->getRawOriginal());
        $this->assertSame($sourceBytes, file_get_contents($sourcePath));
        $this->assertSame(hash('sha256', $sourceBytes), $evidence->before_sha256);
        $this->assertSame(hash('sha256', $signedBytes), $evidence->after_sha256);
        $this->assertSame($signed->sha256, $evidence->after_sha256);
        $this->assertNotSame($evidence->before_sha256, $evidence->after_sha256);
        $this->assertSame(strlen($signedBytes), $signed->size_bytes);
        $this->assertSame(2, $signed->revision_no);
        $this->assertSame(DocumentVersionCreatedVia::Signature, $signed->created_via);
        $this->assertSame($asset->sha256, hash('sha256', $assets->readVerified($asset)));
        $this->assertSame($sourceBytes, $this->get($root.'/download')->assertOk()->streamedContent());
        $this->assertSame($signedBytes, $this->get($response->json('data.signed_version.download_url'))->assertOk()->streamedContent());
        $this->assertStampedPixels($sourcePath, $signedPath);
        $beforeRetry = $evidence->getRawOriginal();
        $this->postJson($root."/signatures/{$slot->id}", $payload)->assertOk()->assertExactJson($response->json());
        $this->assertSame($beforeRetry, $evidence->refresh()->getRawOriginal());
        $this->assertSame($signedBytes, file_get_contents($signedPath));
        $this->assertDatabaseCount('document_signatures', 1);
        $this->assertDatabaseCount('document_versions', 2);
        $audit = AuditLog::query()->where('action', 'document.signed')->sole();
        $this->assertSame($evidence->id, $audit->auditable_id);
        $this->assertSame($evidence->before_sha256, $audit->old_values['sha256']);
        $this->assertSame($evidence->after_sha256, $audit->new_values['sha256']);
        $this->assertCount(1, File::files(dirname($signedPath)));
        foreach (['temporary', 'snapshots'] as $name) {
            $this->assertSame([], array_values(array_diff(scandir($this->runtimeDirectory.DIRECTORY_SEPARATOR.$name), ['.', '..'])));
        }
    }

    private function assertStampedPixels(string $sourcePath, string $signedPath): void
    {
        $this->node(<<<'JS'
            import assert from 'node:assert/strict';
            import { readFile } from 'node:fs/promises';
            import { createRequire } from 'node:module';
            import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
            const require = createRequire(import.meta.url);
            const { createCanvas } = createRequire(require.resolve('pdfjs-dist/package.json'))('@napi-rs/canvas');
            const tasks = await Promise.all(process.argv.slice(1).map(async path => getDocument({ data: new Uint8Array(await readFile(path)), isEvalSupported: false, enableXfa: false, verbosity: 0 })));
            try {
                const [source, signed] = await Promise.all(tasks.map(task => task.promise));
                assert.equal(source.numPages, 2);
                assert.equal(signed.numPages, 2);
                async function render(document, pageNo) {
                    const page = await document.getPage(pageNo);
                    const viewport = page.getViewport({ scale: 1 });
                    const canvas = createCanvas(Math.ceil(viewport.width), Math.ceil(viewport.height));
                    await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
                    return canvas;
                }
                assert.deepEqual((await render(source, 1)).toBuffer('image/png'), (await render(signed, 1)).toBuffer('image/png'));
                const canvas = await render(signed, 2);
                const pixel = (x, y) => [...canvas.getContext('2d').getImageData(Math.floor(x * canvas.width), Math.floor(y * canvas.height), 1, 1).data];
                for (const [dx, dy, color] of [[0.25, 0.25, [255, 0, 0, 255]], [0.75, 0.25, [0, 255, 0, 255]], [0.25, 0.75, [0, 0, 255, 255]], [0.75, 0.75, [0, 0, 0, 255]]]) {
                    assert.deepEqual(pixel(0.2 + dx * 0.25, 0.25 + dy * 0.125), color);
                }
                assert.deepEqual(pixel(0.18, 0.3), [255, 255, 255, 255]);
                assert.deepEqual(pixel(0.47, 0.3), [255, 255, 255, 255]);
            } finally {
                await Promise.all(tasks.map(task => task.destroy()));
            }
            JS, [$sourcePath, $signedPath]);
    }

    private function node(string $script, array $arguments): void
    {
        $process = new Process([(string) config('document_signing.node_binary'), '--input-type=module', '-e', $script, ...$arguments], base_path());
        $process->setTimeout(45);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Real PDF fixture/render process failed: '.$process->getErrorOutput());
    }
}
