<?php

namespace Tests\Feature\Api\V2;

use App\Contracts\Imports\PdfTextExtractor;
use App\Contracts\Imports\ProcessRunner;
use App\Contracts\Imports\ProjectExtractionProvider;
use App\Enums\AiExtractionRunStatus;
use App\Enums\DocumentImportProcessingStage;
use App\Enums\DocumentImportStatus;
use App\Jobs\ProcessDocumentImport;
use App\Models\AiExtractionRun;
use App\Models\DocumentImport;
use App\Services\Imports\DocumentImportProcessor;
use App\Services\Imports\DocumentImportService;
use App\Services\Imports\N8nProjectExtractionProvider;
use App\Services\Imports\PdfTextExtractionException;
use App\Services\Imports\PdfTextExtractionResult;
use App\Services\Imports\PopplerPdfTextExtractor;
use App\Services\Imports\ProcessResult;
use App\Services\Imports\ProjectExtractionDispatchResult;
use App\Services\Imports\ProjectExtractionProviderException;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\TestCase;
use Throwable;

class DocumentImportProcessorTest extends TestCase
{
    use BuildsPhaseFiveImports;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPhaseFiveImports();
        Storage::fake('project-imports');
    }

    public function test_job_claims_once_extracts_text_and_waits_for_callback_without_canonical_writes(): void
    {
        config()->set('project_imports.processing.queue', 'phase-five-imports');
        config()->set('project_imports.processing.tries', 2);
        config()->set('project_imports.processing.timeout_seconds', 45);

        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);
        $extractor = new RecordingPdfTextExtractor(new PdfTextExtractionResult(
            'Text extracted directly from the source PDF.',
            3,
        ));
        $provider = new RecordingProjectExtractionProvider(
            new ProjectExtractionDispatchResult('provider-job-123'),
        );
        $processor = new DocumentImportProcessor($extractor, $provider);
        $job = new ProcessDocumentImport($documentImport->id);

        $this->assertSame('phase-five-imports', $job->queue);
        $this->assertSame(2, $job->tries);
        $this->assertSame(45, $job->timeout);
        $this->assertSame(105, $job->uniqueFor);
        $this->assertSame((string) $documentImport->id, $job->uniqueId());

        $job->handle($processor);

        $documentImport->refresh();
        $run = AiExtractionRun::query()->sole();

        $this->assertSame(1, $extractor->calls);
        $this->assertSame(1, $provider->calls);
        $this->assertSame(DocumentImportStatus::Processing, $documentImport->status);
        $this->assertSame(DocumentImportProcessingStage::WaitingForAi, $documentImport->processing_stage);
        $this->assertSame(1, $documentImport->active_extraction_attempt);
        $this->assertSame(3, $documentImport->page_count);
        $this->assertNotNull($documentImport->extracted_at);
        $this->assertSame(AiExtractionRunStatus::Processing, $run->status);
        $this->assertSame('Text extracted directly from the source PDF.', $run->extracted_text);
        $this->assertSame(hash('sha256', $run->extracted_text), $run->extracted_text_sha256);
        $this->assertSame('provider-job-123', $run->provider_job_id);
        $this->assertNotNull($run->dispatched_at);
        $this->assertSame($documentImport->id, $provider->documentImportIds[0]);
        $this->assertSame($run->id, $provider->runIds[0]);

        $job->handle($processor);

        $this->assertSame(1, $extractor->calls, 'Duplicate job delivery must not re-extract an active run.');
        $this->assertSame(1, $provider->calls, 'Duplicate job delivery must not dispatch twice.');
        $this->assertDatabaseCount('ai_extraction_runs', 1);
        $this->assertDatabaseCount('import_preview_revisions', 0);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_extraction_and_provider_failures_have_precise_durable_state_transitions(): void
    {
        $owner = $this->phaseFiveUser();
        $extractionImport = $this->createDocumentImport($owner);
        $extractor = new RecordingPdfTextExtractor(
            exception: new PdfTextExtractionException(
                PdfTextExtractionException::TEXT_EXTRACTION_UNAVAILABLE,
                'The PDF has no extractable text layer; OCR is not enabled.',
            ),
        );
        $provider = new RecordingProjectExtractionProvider;
        $processor = new DocumentImportProcessor($extractor, $provider);

        $processor->process($extractionImport->id);

        $extractionImport->refresh();
        $extractionRun = AiExtractionRun::query()
            ->where('document_import_id', $extractionImport->id)
            ->sole();
        $this->assertSame(DocumentImportStatus::Failed, $extractionImport->status);
        $this->assertNull($extractionImport->processing_stage);
        $this->assertSame('extracting_text', $extractionImport->failure_stage);
        $this->assertSame('TEXT_EXTRACTION_UNAVAILABLE', $extractionImport->failure_code);
        $this->assertStringContainsString('OCR is not enabled', $extractionImport->failure_message);
        $this->assertSame(AiExtractionRunStatus::Failed, $extractionRun->status);
        $this->assertSame('TEXT_EXTRACTION_UNAVAILABLE', $extractionRun->failure_code);
        $this->assertSame(0, $provider->calls);

        $providerImport = $this->createDocumentImport($owner);
        $successfulExtractor = new RecordingPdfTextExtractor(
            new PdfTextExtractionResult('Extracted provider input.', 1),
        );
        $failedProvider = new RecordingProjectExtractionProvider(
            exception: new ProjectExtractionProviderException(
                ProjectExtractionProviderException::DISPATCH_FAILED,
                'The provider was unavailable.',
            ),
        );

        (new DocumentImportProcessor($successfulExtractor, $failedProvider))
            ->process($providerImport->id);

        $providerImport->refresh();
        $providerRun = AiExtractionRun::query()
            ->where('document_import_id', $providerImport->id)
            ->sole();
        $this->assertSame(DocumentImportStatus::Failed, $providerImport->status);
        $this->assertSame('waiting_ai', $providerImport->failure_stage);
        $this->assertSame('AI_PROVIDER_DISPATCH_FAILED', $providerImport->failure_code);
        $this->assertSame('Extracted provider input.', $providerRun->extracted_text);
        $this->assertSame(AiExtractionRunStatus::Failed, $providerRun->status);
        $this->assertNotNull($providerRun->finished_at);
        $this->assertDatabaseCount('import_preview_revisions', 0);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_retry_allocates_a_new_run_and_reentrant_duplicate_jobs_do_not_claim_it_twice(): void
    {
        Queue::fake();
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);
        $provider = new RecordingProjectExtractionProvider(
            exception: new ProjectExtractionProviderException('PROVIDER_TIMEOUT', 'Provider timed out.'),
        );
        $extractor = new RecordingPdfTextExtractor(new PdfTextExtractionResult('Original verified text.', 1));
        $processor = new DocumentImportProcessor($extractor, $provider);
        $processor->process($documentImport->id);
        $priorRun = AiExtractionRun::query()->sole();
        $priorSnapshot = $priorRun->getRawOriginal();

        app(DocumentImportService::class)->retry($owner, $documentImport->fresh());
        $retryRun = $documentImport->extractionRuns()->where('attempt_no', 2)->sole();
        $this->assertNotSame($priorRun->id, $retryRun->id);
        $this->assertNotSame($priorRun->public_id, $retryRun->public_id);
        $this->assertSame(AiExtractionRunStatus::Queued, $retryRun->status);
        Queue::assertPushed(ProcessDocumentImport::class, fn ($job) => $job->attemptNo === 2);

        $provider->exception = null;
        $extractor->beforeReturn = function () use ($processor, $documentImport): void {
            (new ProcessDocumentImport($documentImport->id))->handle($processor);
            (new ProcessDocumentImport($documentImport->id, 2))->handle($processor);
            (new ProcessDocumentImport($documentImport->id))->failed(new RuntimeException('Stale failure.'));
        };
        (new ProcessDocumentImport($documentImport->id, 2))->handle($processor);
        (new ProcessDocumentImport($documentImport->id, 2))->handle($processor);

        $this->assertSame(2, $extractor->calls);
        $this->assertSame(2, $provider->calls);
        $this->assertSame($priorSnapshot, $priorRun->fresh()->getRawOriginal());
        $this->assertSame(2, $documentImport->fresh()->active_extraction_attempt);
        $this->assertSame(DocumentImportProcessingStage::WaitingForAi, $documentImport->fresh()->processing_stage);
        $this->assertDatabaseCount('ai_extraction_runs', 2);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_provider_completion_after_a_timeout_and_retry_cannot_change_either_attempt(): void
    {
        Queue::fake();
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);
        $snapshots = [];
        $provider = new RecordingProjectExtractionProvider(
            beforeReturn: function () use ($owner, $documentImport, &$snapshots): void {
                (new ProcessDocumentImport($documentImport->id))->failed(new RuntimeException('Timeout.'));
                app(DocumentImportService::class)->retry($owner, $documentImport->fresh());
                $snapshots = $documentImport->extractionRuns()->get()->map->getRawOriginal()->all();
            },
        );
        $processor = new DocumentImportProcessor(
            new RecordingPdfTextExtractor(new PdfTextExtractionResult('Original verified text.', 1)),
            $provider,
        );
        $processor->process($documentImport->id);

        $this->assertSame($snapshots, $documentImport->extractionRuns()->get()->map->getRawOriginal()->all());
        $this->assertSame(2, $documentImport->fresh()->active_extraction_attempt);
        $this->assertSame(DocumentImportProcessingStage::Validating, $documentImport->fresh()->processing_stage);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_duplicate_delivery_after_terminal_failure_is_a_no_op_until_explicit_retry(): void
    {
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);
        $extractor = new RecordingPdfTextExtractor(
            exception: new PdfTextExtractionException(
                PdfTextExtractionException::PDF_INVALID,
                'Invalid PDF.',
            ),
        );
        $provider = new RecordingProjectExtractionProvider;
        $processor = new DocumentImportProcessor($extractor, $provider);

        $processor->process($documentImport->id);
        $this->assertSame(DocumentImportStatus::Failed, $documentImport->fresh()->status);
        $this->assertDatabaseCount('ai_extraction_runs', 1);

        $extractor->exception = null;
        $extractor->result = new PdfTextExtractionResult('A duplicate must not consume this.', 1);
        $processor->process($documentImport->id);

        $this->assertSame(1, $extractor->calls);
        $this->assertSame(0, $provider->calls);
        $this->assertDatabaseCount('ai_extraction_runs', 1);
        $this->assertSame(DocumentImportStatus::Failed, $documentImport->fresh()->status);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_stale_worker_is_superseded_and_cannot_overwrite_the_new_active_attempt(): void
    {
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);
        $provider = new RecordingProjectExtractionProvider;
        $newRun = null;
        $extractor = new RecordingPdfTextExtractor(
            new PdfTextExtractionResult('Stale extraction output.', 9),
            beforeReturn: function () use ($documentImport, &$newRun): void {
                $newRun = $this->createExtractionRun(
                    $documentImport,
                    2,
                    AiExtractionRunStatus::Processing,
                );
                $documentImport->update([
                    'status' => DocumentImportStatus::Processing,
                    'processing_stage' => DocumentImportProcessingStage::Validating,
                    'active_extraction_attempt' => 2,
                ]);
            },
        );

        (new DocumentImportProcessor($extractor, $provider))->process($documentImport->id);

        $oldRun = AiExtractionRun::query()
            ->where('document_import_id', $documentImport->id)
            ->where('attempt_no', 1)
            ->sole();
        $documentImport->refresh();
        $newRun?->refresh();

        $this->assertSame(AiExtractionRunStatus::Superseded, $oldRun->status);
        $this->assertNull($oldRun->extracted_text);
        $this->assertSame(AiExtractionRunStatus::Processing, $newRun?->status);
        $this->assertSame(2, $documentImport->active_extraction_attempt);
        $this->assertSame(DocumentImportProcessingStage::Validating, $documentImport->processing_stage);
        $this->assertNull($documentImport->page_count);
        $this->assertSame(0, $provider->calls);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_retry_job_claims_only_its_queued_attempt_and_initial_redelivery_cannot_claim_it(): void
    {
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner, DocumentImportStatus::Processing, [
            'active_extraction_attempt' => 2,
            'processing_stage' => DocumentImportProcessingStage::Validating,
        ]);
        $this->createExtractionRun($documentImport, 1, AiExtractionRunStatus::Failed);
        $run = $this->createExtractionRun($documentImport, 2, AiExtractionRunStatus::Queued);
        $extractor = new RecordingPdfTextExtractor(new PdfTextExtractionResult('Retry source text.', 2));
        $provider = new RecordingProjectExtractionProvider;
        $processor = new DocumentImportProcessor($extractor, $provider);

        (new ProcessDocumentImport($documentImport->id))->handle($processor);
        (new ProcessDocumentImport($documentImport->id, 1))->handle($processor);
        $this->assertSame(0, $extractor->calls);
        $this->assertSame(AiExtractionRunStatus::Queued, $run->fresh()->status);

        $retry = new ProcessDocumentImport($documentImport->id, 2);
        $this->assertSame($documentImport->id.':2', $retry->uniqueId());
        $retry->handle($processor);
        $retry->handle($processor);

        $this->assertSame(1, $extractor->calls);
        $this->assertSame(1, $provider->calls);
        $this->assertSame(AiExtractionRunStatus::Processing, $run->fresh()->status);
        $this->assertSame(2, $documentImport->fresh()->active_extraction_attempt);
        $this->assertDatabaseCount('ai_extraction_runs', 2);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_job_failure_marks_current_attempt_failed_but_cannot_fail_a_new_attempt_or_completed_callback(): void
    {
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner, DocumentImportStatus::Processing, [
            'active_extraction_attempt' => 2,
            'processing_stage' => DocumentImportProcessingStage::Validating,
        ]);
        $this->createExtractionRun($documentImport, 1, AiExtractionRunStatus::Failed);
        $run = $this->createExtractionRun($documentImport, 2, AiExtractionRunStatus::Queued);
        $processor = new DocumentImportProcessor(new RecordingPdfTextExtractor, new RecordingProjectExtractionProvider);
        $this->app->instance(DocumentImportProcessor::class, $processor);

        (new ProcessDocumentImport($documentImport->id))->failed(new RuntimeException('Sensitive worker detail.'));
        $this->assertSame(AiExtractionRunStatus::Queued, $run->fresh()->status);
        $this->assertSame(DocumentImportStatus::Processing, $documentImport->fresh()->status);

        (new ProcessDocumentImport($documentImport->id, 2))->failed(new RuntimeException('Sensitive worker detail.'));
        $this->assertSame(AiExtractionRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(DocumentImportStatus::Failed, $documentImport->fresh()->status);
        $this->assertSame('DOCUMENT_IMPORT_JOB_FAILED', $documentImport->fresh()->failure_code);
        $this->assertStringNotContainsString('Sensitive worker detail', $documentImport->fresh()->failure_message);

        [$reviewableImport, $completedRun] = $this->createReviewableImport($owner);
        (new ProcessDocumentImport($reviewableImport->id))->failed(new RuntimeException('Late worker failure.'));
        $this->assertSame(DocumentImportStatus::NeedsReview, $reviewableImport->fresh()->status);
        $this->assertSame(AiExtractionRunStatus::Succeeded, $completedRun->fresh()->status);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_job_failure_before_dependency_resolution_durably_fails_an_unclaimed_upload(): void
    {
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);
        $this->app->bind(DocumentImportProcessor::class, function (): never {
            throw new RuntimeException('Unavailable provider configuration.');
        });

        (new ProcessDocumentImport($documentImport->id))->failed(new RuntimeException('Unavailable provider configuration.'));

        $this->assertSame(DocumentImportStatus::Failed, $documentImport->fresh()->status);
        $this->assertSame('DOCUMENT_IMPORT_JOB_FAILED', $documentImport->fresh()->failure_code);
        $this->assertDatabaseCount('ai_extraction_runs', 0);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_source_tampering_is_rejected_before_extraction_and_provider_dispatch(): void
    {
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);
        Storage::disk('project-imports')->put($documentImport->storage_path, "%PDF-1.4\nTampered source.\n");
        $extractor = new RecordingPdfTextExtractor;
        $provider = new RecordingProjectExtractionProvider;

        (new DocumentImportProcessor($extractor, $provider))->process($documentImport->id);

        $this->assertSame(0, $extractor->calls);
        $this->assertSame(0, $provider->calls);
        $this->assertSame(DocumentImportStatus::Failed, $documentImport->fresh()->status);
        $this->assertSame(PdfTextExtractionException::SOURCE_INTEGRITY_MISMATCH, $documentImport->fresh()->failure_code);
        $this->assertSame('validating', $documentImport->fresh()->failure_stage);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_provider_dispatch_uses_text_and_public_run_provenance_without_storage_paths(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://provider.test/import' => Http::response(['job_id' => 'provider-accepted'], 202)]);
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);
        $run = $this->createExtractionRun($documentImport);
        $text = new PdfTextExtractionResult('Original PDF text.', 1);
        $provider = new N8nProjectExtractionProvider(
            Http::getFacadeRoot(),
            'https://provider.test/import',
            'test-provider-token',
            'https://school.test/api/v2/integrations/n8n/import-extraction-callback',
            10,
            3,
        );

        $result = $provider->dispatch($documentImport, $run, $text);

        $this->assertSame('provider-accepted', $result->providerJobId);
        Http::assertSent(function (Request $request) use ($documentImport, $run, $text): bool {
            $this->assertFalse($request->hasHeader('X-Import-Signature'));
            $this->assertArrayNotHasKey('storage_path', $request->data());
            $this->assertArrayNotHasKey('storage_disk', $request->data());
            $this->assertArrayNotHasKey('document_import_id', $request->data());

            return $request->hasHeader('Authorization', 'Bearer test-provider-token')
                && $request['document_import_public_id'] === $documentImport->public_id
                && $request['extraction_run_id'] === $run->public_id
                && $request['extracted_text'] === $text->text
                && $request['extracted_text_sha256'] === $text->textSha256
                && $request['source_sha256'] === $documentImport->sha256;
        });
        $this->assertNoCanonicalImportWrites();
    }

    public function test_provider_rejects_redirects_and_schema_incompatible_job_identifiers(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push('', 302, ['Location' => 'https://redirect.test/import'])
            ->push(['job_id' => str_repeat('j', 192)], 202);
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);
        $run = $this->createExtractionRun($documentImport);
        $provider = new N8nProjectExtractionProvider(Http::getFacadeRoot(), 'https://provider.test/import', null, null, 10, 3);

        foreach (['redirect', 'oversized identifier'] as $case) {
            try {
                $provider->dispatch($documentImport, $run, new PdfTextExtractionResult('Source text.', 1));
                $this->fail('The provider must reject '.$case.'.');
            } catch (ProjectExtractionProviderException $exception) {
                $this->assertSame(ProjectExtractionProviderException::DISPATCH_FAILED, $exception->errorCode);
            }
        }

        Http::assertSentCount(2);
        $this->assertNoCanonicalImportWrites();
    }

    public function test_poppler_empty_text_stops_without_any_ocr_fallback(): void
    {
        $owner = $this->phaseFiveUser();
        $documentImport = $this->createDocumentImport($owner);
        $path = Storage::disk('project-imports')->path($documentImport->storage_path);
        $runner = new SequencedProcessRunner([
            new ProcessResult(0, "Pages: 2\nEncrypted: no\n", ''),
            new ProcessResult(0, " \n\f\n", ''),
        ]);
        $extractor = new PopplerPdfTextExtractor(
            $runner,
            'pdfinfo-test-binary',
            'pdftotext-test-binary',
            5,
            10,
            4096,
            true,
        );

        try {
            $extractor->extract($path);
            $this->fail('A PDF without a text layer must not proceed to AI or OCR.');
        } catch (PdfTextExtractionException $exception) {
            $this->assertSame(PdfTextExtractionException::TEXT_EXTRACTION_UNAVAILABLE, $exception->errorCode);
            $this->assertStringContainsString('OCR is not enabled', $exception->getMessage());
        }

        $this->assertCount(2, $runner->commands);
        $this->assertSame('pdfinfo-test-binary', $runner->commands[0][0]);
        $this->assertSame('pdftotext-test-binary', $runner->commands[1][0]);
        $allCommands = strtolower(implode(' ', array_merge(...$runner->commands)));
        $this->assertStringNotContainsString('ocr', $allCommands);
        $this->assertStringNotContainsString('tesseract', $allCommands);
    }
}

final class RecordingPdfTextExtractor implements PdfTextExtractor
{
    public int $calls = 0;

    public function __construct(
        public ?PdfTextExtractionResult $result = null,
        public ?Throwable $exception = null,
        public ?Closure $beforeReturn = null,
    ) {}

    public function extract(string $absolutePath): PdfTextExtractionResult
    {
        $this->calls++;

        if ($this->beforeReturn !== null) {
            ($this->beforeReturn)($absolutePath);
        }

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result ?? throw new RuntimeException('No fake PDF extraction result was configured.');
    }
}

final class RecordingProjectExtractionProvider implements ProjectExtractionProvider
{
    public int $calls = 0;

    /** @var array<int, int> */
    public array $documentImportIds = [];

    /** @var array<int, int> */
    public array $runIds = [];

    public function __construct(
        public ?ProjectExtractionDispatchResult $result = null,
        public ?Throwable $exception = null,
        public ?Closure $beforeReturn = null,
    ) {}

    public function dispatch(
        DocumentImport $documentImport,
        AiExtractionRun $run,
        PdfTextExtractionResult $extraction,
    ): ProjectExtractionDispatchResult {
        $this->calls++;
        $this->documentImportIds[] = $documentImport->id;
        $this->runIds[] = $run->id;

        if ($this->beforeReturn !== null) {
            ($this->beforeReturn)();
        }

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result ?? new ProjectExtractionDispatchResult;
    }
}

final class SequencedProcessRunner implements ProcessRunner
{
    /** @var array<int, array<int, string>> */
    public array $commands = [];

    /** @param array<int, ProcessResult> $results */
    public function __construct(private array $results) {}

    public function run(array $command, float $timeoutSeconds, int $maxStdoutBytes): ProcessResult
    {
        $this->commands[] = $command;

        return array_shift($this->results)
            ?? throw new RuntimeException('The fake process runner has no response left.');
    }
}
