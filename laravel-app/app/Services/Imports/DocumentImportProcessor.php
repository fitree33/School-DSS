<?php

namespace App\Services\Imports;

use App\Contracts\Imports\PdfTextExtractor;
use App\Contracts\Imports\ProjectExtractionProvider;
use App\Enums\AiExtractionRunStatus;
use App\Enums\DocumentImportProcessingStage;
use App\Enums\DocumentImportStatus;
use App\Models\AiExtractionRun;
use App\Models\DocumentImport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class DocumentImportProcessor
{
    public function __construct(
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly ProjectExtractionProvider $provider,
    ) {}

    /**
     * Process one import attempt. Duplicate job delivery is a no-op after an
     * active run has been claimed or dispatched.
     */
    public function process(int $documentImportId, ?int $expectedAttempt = null): void
    {
        $runId = $this->claimRun($documentImportId, $expectedAttempt);

        if ($runId === null) {
            return;
        }

        $failureStage = DocumentImportProcessingStage::Validating->value;

        try {
            $documentImport = DocumentImport::query()->findOrFail($documentImportId);
            $run = AiExtractionRun::query()->findOrFail($runId);
            $absolutePath = $this->validatedSourcePath($documentImport);

            $failureStage = DocumentImportProcessingStage::ExtractingText->value;

            if (! $this->advanceStageIfCurrent(
                $documentImport->id,
                $run->id,
                DocumentImportProcessingStage::ExtractingText,
            )) {
                return;
            }

            $extraction = $this->pdfTextExtractor->extract($absolutePath);

            if (! $this->storeExtractionIfCurrent($documentImport->id, $run->id, $extraction)) {
                return;
            }

            $failureStage = DocumentImportProcessingStage::WaitingForAi->value;
            $documentImport->refresh();
            $run->refresh();
            $dispatch = $this->provider->dispatch($documentImport, $run, $extraction);

            $this->storeDispatchIfCurrent($documentImport->id, $run->id, $dispatch);
        } catch (PdfTextExtractionException $exception) {
            $this->failIfCurrent(
                $documentImportId,
                $runId,
                $failureStage,
                $exception->errorCode,
                $exception->getMessage(),
            );
        } catch (ProjectExtractionProviderException $exception) {
            $this->failIfCurrent(
                $documentImportId,
                $runId,
                DocumentImportProcessingStage::WaitingForAi->value,
                $exception->errorCode,
                $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            $this->failIfCurrent(
                $documentImportId,
                $runId,
                'processing',
                'DOCUMENT_IMPORT_PROCESSING_FAILED',
                'The document import could not be processed.',
            );

            Log::error('Unexpected document import processing failure.', [
                'document_import_id' => $documentImportId,
                'extraction_run_id' => $runId,
                'exception' => $exception,
            ]);
        }
    }

    public static function failJob(int $documentImportId, ?int $expectedAttempt = null): void
    {
        DB::transaction(function () use ($documentImportId, $expectedAttempt): void {
            $documentImport = DocumentImport::query()->lockForUpdate()->find($documentImportId);
            $attempt = $expectedAttempt ?? 1;
            $message = 'The document import worker failed or exceeded its time limit. You can retry it safely.';

            if ($documentImport !== null
                && $expectedAttempt === null
                && $documentImport->status === DocumentImportStatus::Uploaded
                && $documentImport->active_extraction_attempt === null
                && ! AiExtractionRun::query()->where('document_import_id', $documentImportId)->exists()) {
                $documentImport->update([
                    'status' => DocumentImportStatus::Failed,
                    'processing_stage' => null,
                    'failure_stage' => 'queue',
                    'failure_code' => 'DOCUMENT_IMPORT_JOB_FAILED',
                    'failure_message' => $message,
                ]);

                return;
            }

            if ($documentImport === null
                || $documentImport->status !== DocumentImportStatus::Processing
                || (int) $documentImport->active_extraction_attempt !== $attempt) {
                return;
            }

            $run = AiExtractionRun::query()
                ->where('document_import_id', $documentImportId)
                ->where('attempt_no', $attempt)
                ->lockForUpdate()
                ->first();

            if ($run === null || $run->status->isTerminal()) {
                return;
            }

            $run->update([
                'status' => AiExtractionRunStatus::Failed,
                'finished_at' => now(),
                'failure_code' => 'DOCUMENT_IMPORT_JOB_FAILED',
                'failure_message' => $message,
            ]);
            $documentImport->update([
                'status' => DocumentImportStatus::Failed,
                'processing_stage' => null,
                'failure_stage' => 'queue',
                'failure_code' => 'DOCUMENT_IMPORT_JOB_FAILED',
                'failure_message' => $message,
            ]);
        }, 3);
    }

    private function claimRun(int $documentImportId, ?int $expectedAttempt): ?int
    {
        return DB::transaction(function () use ($documentImportId, $expectedAttempt): ?int {
            $documentImport = DocumentImport::query()->lockForUpdate()->findOrFail($documentImportId);

            if (! in_array($documentImport->status, [
                DocumentImportStatus::Uploaded,
                DocumentImportStatus::Processing,
            ], true)) {
                return null;
            }

            // A redelivery of the initial upload job can never claim a retry.
            $attempt = $expectedAttempt ?? 1;

            if ($documentImport->active_extraction_attempt !== null) {
                if ((int) $documentImport->active_extraction_attempt !== $attempt) {
                    return null;
                }

                $activeRun = AiExtractionRun::query()
                    ->where('document_import_id', $documentImport->id)
                    ->where('attempt_no', $documentImport->active_extraction_attempt)
                    ->lockForUpdate()
                    ->first();

                if ($activeRun?->status === AiExtractionRunStatus::Queued) {
                    $activeRun->update([
                        'status' => AiExtractionRunStatus::Processing,
                        'started_at' => $activeRun->started_at ?? now(),
                    ]);

                    $documentImport->update([
                        'status' => DocumentImportStatus::Processing,
                        'processing_stage' => DocumentImportProcessingStage::Validating,
                        'failure_stage' => null,
                        'failure_code' => null,
                        'failure_message' => null,
                    ]);

                    return $activeRun->id;
                }

                return null;
            }

            if ($expectedAttempt !== null
                || $documentImport->status !== DocumentImportStatus::Uploaded
                || AiExtractionRun::query()->where('document_import_id', $documentImport->id)->exists()) {
                return null;
            }

            $run = AiExtractionRun::query()->create([
                'document_import_id' => $documentImport->id,
                'attempt_no' => $attempt,
                'provider' => (string) config('project_imports.provider.driver', 'n8n'),
                'model_name' => config('project_imports.provider.model_name'),
                'schema_version' => (string) config(
                    'project_imports.provider.schema_version',
                    'project-import.v1',
                ),
                'prompt_version' => config('project_imports.provider.prompt_version'),
                'status' => AiExtractionRunStatus::Processing,
                'started_at' => now(),
            ]);

            $documentImport->update([
                'status' => DocumentImportStatus::Processing,
                'processing_stage' => DocumentImportProcessingStage::Validating,
                'active_extraction_attempt' => $attempt,
                'failure_stage' => null,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return $run->id;
        }, 3);
    }

    private function validatedSourcePath(DocumentImport $documentImport): string
    {
        try {
            $disk = Storage::disk($documentImport->storage_disk);

            if (! $disk->exists($documentImport->storage_path)) {
                throw new PdfTextExtractionException(
                    PdfTextExtractionException::SOURCE_FILE_UNAVAILABLE,
                    'The imported PDF source file is unavailable.',
                );
            }

            $absolutePath = $disk->path($documentImport->storage_path);
        } catch (PdfTextExtractionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::SOURCE_FILE_UNAVAILABLE,
                'The imported PDF source file is unavailable.',
                $exception,
            );
        }

        $this->assertSourceIntegrity($disk, $documentImport);

        return $absolutePath;
    }

    private function assertSourceIntegrity(Filesystem $disk, DocumentImport $documentImport): void
    {
        $actualSize = $disk->size($documentImport->storage_path);
        $maxBytes = (int) config('project_imports.upload.max_bytes', 10 * 1024 * 1024);

        if ($actualSize > $maxBytes) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::SOURCE_FILE_LIMIT_EXCEEDED,
                "The imported PDF exceeds the configured {$maxBytes}-byte limit.",
            );
        }

        $stream = $disk->readStream($documentImport->storage_path);

        if (! is_resource($stream)) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::SOURCE_FILE_UNAVAILABLE,
                'The imported PDF source file cannot be read.',
            );
        }

        try {
            $hash = hash_init('sha256');

            if (hash_update_stream($hash, $stream) === false) {
                throw new RuntimeException('Unable to hash the imported PDF.');
            }

            $actualSha256 = hash_final($hash);
        } catch (Throwable $exception) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::SOURCE_FILE_UNAVAILABLE,
                'The imported PDF source file cannot be read.',
                $exception,
            );
        } finally {
            fclose($stream);
        }

        if ($actualSize !== (int) $documentImport->size_bytes
            || ! hash_equals(strtolower($documentImport->sha256), strtolower($actualSha256))) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::SOURCE_INTEGRITY_MISMATCH,
                'The imported PDF no longer matches its immutable upload metadata.',
            );
        }
    }

    private function advanceStageIfCurrent(
        int $documentImportId,
        int $runId,
        DocumentImportProcessingStage $stage,
    ): bool {
        return DB::transaction(function () use ($documentImportId, $runId, $stage): bool {
            [$documentImport, $run] = $this->lockImportAndRun($documentImportId, $runId);

            if (! $this->isCurrentProcessingRun($documentImport, $run)) {
                $this->supersede($run);

                return false;
            }

            $documentImport->update(['processing_stage' => $stage]);

            return true;
        }, 3);
    }

    private function storeExtractionIfCurrent(
        int $documentImportId,
        int $runId,
        PdfTextExtractionResult $extraction,
    ): bool {
        return DB::transaction(function () use ($documentImportId, $runId, $extraction): bool {
            [$documentImport, $run] = $this->lockImportAndRun($documentImportId, $runId);

            if (! $this->isCurrentProcessingRun($documentImport, $run)) {
                $this->supersede($run);

                return false;
            }

            $run->update([
                'extracted_text' => $extraction->text,
                'extracted_text_sha256' => $extraction->textSha256,
            ]);
            $documentImport->update([
                'processing_stage' => DocumentImportProcessingStage::WaitingForAi,
                'page_count' => $extraction->pageCount,
                'extracted_at' => now(),
            ]);

            return true;
        }, 3);
    }

    private function storeDispatchIfCurrent(
        int $documentImportId,
        int $runId,
        ProjectExtractionDispatchResult $dispatch,
    ): void {
        DB::transaction(function () use ($documentImportId, $runId, $dispatch): void {
            [$documentImport, $run] = $this->lockImportAndRun($documentImportId, $runId);

            if (! $this->isCurrentProcessingRun($documentImport, $run)) {
                $this->supersede($run);

                return;
            }

            $run->update([
                'provider_job_id' => $dispatch->providerJobId,
                'dispatched_at' => now(),
            ]);
        }, 3);
    }

    private function failIfCurrent(
        int $documentImportId,
        int $runId,
        string $failureStage,
        string $failureCode,
        string $failureMessage,
    ): void {
        DB::transaction(function () use (
            $documentImportId,
            $runId,
            $failureStage,
            $failureCode,
            $failureMessage,
        ): void {
            [$documentImport, $run] = $this->lockImportAndRun($documentImportId, $runId);

            if (! $this->isCurrentProcessingRun($documentImport, $run)) {
                $this->supersede($run);

                return;
            }

            $message = substr($failureMessage, 0, 4000);
            $run->update([
                'status' => AiExtractionRunStatus::Failed,
                'finished_at' => now(),
                'failure_code' => $failureCode,
                'failure_message' => $message,
            ]);
            $documentImport->update([
                'status' => DocumentImportStatus::Failed,
                'processing_stage' => null,
                'failure_stage' => $failureStage,
                'failure_code' => $failureCode,
                'failure_message' => $message,
            ]);
        }, 3);
    }

    /**
     * @return array{DocumentImport, AiExtractionRun}
     */
    private function lockImportAndRun(int $documentImportId, int $runId): array
    {
        $documentImport = DocumentImport::query()->lockForUpdate()->findOrFail($documentImportId);
        $run = AiExtractionRun::query()
            ->where('document_import_id', $documentImport->id)
            ->lockForUpdate()
            ->findOrFail($runId);

        return [$documentImport, $run];
    }

    private function isCurrentProcessingRun(DocumentImport $documentImport, AiExtractionRun $run): bool
    {
        return $documentImport->status === DocumentImportStatus::Processing
            && (int) $documentImport->active_extraction_attempt === $run->attempt_no
            && $run->status === AiExtractionRunStatus::Processing;
    }

    private function supersede(AiExtractionRun $run): void
    {
        if (! $run->status->isTerminal()) {
            $run->update([
                'status' => AiExtractionRunStatus::Superseded,
                'finished_at' => now(),
            ]);
        }
    }
}
