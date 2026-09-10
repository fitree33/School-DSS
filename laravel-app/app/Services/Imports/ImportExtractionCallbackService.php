<?php

namespace App\Services\Imports;

use App\Enums\AiExtractionRunStatus;
use App\Enums\DocumentImportProcessingStage;
use App\Enums\DocumentImportStatus;
use App\Exceptions\ApiProblemException;
use App\Models\AiExtractionRun;
use App\Models\DocumentImport;
use App\Models\ImportPreviewRevision;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ImportExtractionCallbackService
{
    public function __construct(
        private readonly ImportCallbackPayloadNormalizer $normalizer,
        private readonly ProjectImportPayloadValidator $payloadValidator,
    ) {}

    /**
     * @param  Closure(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function handle(
        AiExtractionRun $boundRun,
        Closure $callback,
        string $eventId,
        string $bodyDigest,
    ): array {
        try {
            return DB::transaction(
                fn (): array => $this->handleLocked(
                    $boundRun->getKey(),
                    $boundRun->document_import_id,
                    $callback,
                    $eventId,
                    $bodyDigest,
                ),
                3,
            );
        } catch (QueryException $exception) {
            if (! $this->isProviderEventUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->resolveConcurrentEventClaim(
                $boundRun->getKey(),
                $boundRun->document_import_id,
                $eventId,
                $bodyDigest,
                $exception,
            );
        }
    }

    /**
     * @param  Closure(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function handleLocked(
        int|string $runKey,
        int|string $documentImportKey,
        Closure $callback,
        string $eventId,
        string $bodyDigest,
    ): array {
        /** @var DocumentImport $documentImport */
        $documentImport = DocumentImport::query()
            ->lockForUpdate()
            ->findOrFail($documentImportKey);
        /** @var AiExtractionRun $run */
        $run = AiExtractionRun::query()
            ->where('document_import_id', $documentImport->id)
            ->lockForUpdate()
            ->findOrFail($runKey);

        /** @var AiExtractionRun|null $eventOwner */
        $eventOwner = AiExtractionRun::query()
            ->where('provider', $run->provider)
            ->where('provider_event_id', $eventId)
            ->lockForUpdate()
            ->first();

        if ($eventOwner !== null) {
            return $this->replayedResultOrConflict($run, $eventOwner, $bodyDigest);
        }

        if ($run->provider_event_id !== null) {
            throw new ApiProblemException(
                'This extraction run has already accepted a different callback event.',
                'callback_replay_conflict',
                Response::HTTP_CONFLICT,
            );
        }

        $receivedAt = now();

        if (! $this->isFresh($run, $documentImport)) {
            if ((int) $documentImport->active_extraction_attempt === (int) $run->attempt_no
                && $documentImport->status === DocumentImportStatus::Processing
                && in_array($run->status, [AiExtractionRunStatus::Queued, AiExtractionRunStatus::Processing], true)) {
                throw new ApiProblemException(
                    'The extraction run is not yet waiting for a provider callback.',
                    'callback_not_ready',
                    Response::HTTP_CONFLICT,
                );
            }

            $run->forceFill([
                'provider_event_id' => $eventId,
                'callback_digest' => $bodyDigest,
                'callback_received_at' => $receivedAt,
                'status' => AiExtractionRunStatus::Superseded->value,
                'finished_at' => $run->finished_at ?? $receivedAt,
            ])->save();

            return $this->result($run, $documentImport, replayed: false, stale: true);
        }

        // Authenticate and resolve replay/staleness before validating fresh payloads.
        $callback = $callback();

        $run->forceFill([
            'provider_event_id' => $eventId,
            'callback_digest' => $bodyDigest,
            'callback_received_at' => $receivedAt,
            'provider_job_id' => $callback['provider_job_id'] ?? $run->provider_job_id,
            'model_name' => $callback['model_name'] ?? $run->model_name,
        ])->save();

        if ($callback['status'] === AiExtractionRunStatus::Failed->value) {
            return $this->recordFailure($run, $documentImport, $callback, $receivedAt);
        }

        return $this->recordSuccess($run, $documentImport, $callback, $receivedAt);
    }

    private function isFresh(AiExtractionRun $run, DocumentImport $documentImport): bool
    {
        $runStatus = $run->status instanceof AiExtractionRunStatus
            ? $run->status->value
            : (string) $run->status;
        $importStatus = $documentImport->status instanceof DocumentImportStatus
            ? $documentImport->status->value
            : (string) $documentImport->status;

        return $runStatus === AiExtractionRunStatus::Processing->value
            && $importStatus === DocumentImportStatus::Processing->value
            && $documentImport->processing_stage === DocumentImportProcessingStage::WaitingForAi
            && (int) $documentImport->active_extraction_attempt === (int) $run->attempt_no;
    }

    /**
     * @param  array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function recordSuccess(
        AiExtractionRun $run,
        DocumentImport $documentImport,
        array $callback,
        mixed $receivedAt,
    ): array {
        /** @var array<string, mixed> $candidate */
        $candidate = $callback['candidate_payload'];
        /** @var array<string, mixed> $confidence */
        $confidence = $callback['confidence'] ?? [];
        /** @var array<int, mixed> $warnings */
        $warnings = $callback['warnings'] ?? [];
        $normalized = $this->normalizer->normalizeSuccess($candidate, $confidence, $warnings);
        $uploader = $documentImport->uploader()->firstOrFail();
        $validationErrors = $this->payloadValidator->errors($normalized['payload'], $uploader);
        $latestRevision = (int) ($documentImport->previewRevisions()->max('revision_no') ?? 0);
        $nextRevision = $latestRevision + 1;

        $revision = ImportPreviewRevision::query()->create([
            'document_import_id' => $documentImport->id,
            'revision_no' => $nextRevision,
            'parent_revision_no' => $documentImport->current_preview_revision,
            'source_extraction_run_id' => $run->id,
            'source' => ImportPreviewRevision::SOURCE_AI,
            'payload' => $normalized['payload'],
            'validation_errors' => $validationErrors,
            'warnings' => $normalized['warnings'],
            'field_confidence' => $normalized['confidence'],
            'edited_by' => null,
            'client_idempotency_key' => null,
        ]);

        $run->forceFill([
            'status' => AiExtractionRunStatus::Succeeded->value,
            'raw_result' => $callback['raw_result'] ?? null,
            'normalized_result' => $normalized['payload'],
            'confidence' => $normalized['confidence'],
            'warnings' => $normalized['warnings'],
            'finished_at' => $receivedAt,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        $documentImport->forceFill([
            'status' => DocumentImportStatus::NeedsReview->value,
            'processing_stage' => null,
            'current_preview_revision' => $nextRevision,
            'extracted_at' => $receivedAt,
            'failure_stage' => null,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        return $this->result(
            $run,
            $documentImport,
            replayed: false,
            stale: false,
            revision: $revision,
        );
    }

    /**
     * @param  array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function recordFailure(
        AiExtractionRun $run,
        DocumentImport $documentImport,
        array $callback,
        mixed $receivedAt,
    ): array {
        /** @var array<int, mixed> $warnings */
        $warnings = $callback['warnings'] ?? [];
        $normalizedWarnings = $this->normalizer->normalizeWarnings($warnings);
        $failureCode = (string) $callback['failure_code'];
        $failureMessage = substr((string) $callback['failure_message'], 0, 4000);

        $run->forceFill([
            'status' => AiExtractionRunStatus::Failed->value,
            'raw_result' => $callback['raw_result'] ?? null,
            'confidence' => null,
            'warnings' => $normalizedWarnings,
            'finished_at' => $receivedAt,
            'failure_code' => $failureCode,
            'failure_message' => $failureMessage,
        ])->save();

        $documentImport->forceFill([
            'status' => DocumentImportStatus::Failed->value,
            'processing_stage' => null,
            'failure_stage' => DocumentImportProcessingStage::WaitingForAi->value,
            'failure_code' => $failureCode,
            'failure_message' => $failureMessage,
        ])->save();

        return $this->result($run, $documentImport, replayed: false, stale: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function replayedResultOrConflict(
        AiExtractionRun $requestedRun,
        AiExtractionRun $eventOwner,
        string $bodyDigest,
    ): array {
        if ((int) $eventOwner->getKey() !== (int) $requestedRun->getKey()) {
            throw new ApiProblemException(
                'The callback event identifier has already been used for another extraction run.',
                'callback_event_reused',
                Response::HTTP_CONFLICT,
            );
        }

        if (! is_string($eventOwner->callback_digest)
            || ! hash_equals($eventOwner->callback_digest, $bodyDigest)) {
            throw new ApiProblemException(
                'The callback event was replayed with different content.',
                'callback_replay_conflict',
                Response::HTTP_CONFLICT,
            );
        }

        /** @var DocumentImport $documentImport */
        $documentImport = DocumentImport::query()
            ->lockForUpdate()
            ->findOrFail($eventOwner->document_import_id);
        $runStatus = $eventOwner->status instanceof AiExtractionRunStatus
            ? $eventOwner->status->value
            : (string) $eventOwner->status;
        $revision = $runStatus === AiExtractionRunStatus::Succeeded->value
            ? ImportPreviewRevision::query()
                ->where('source_extraction_run_id', $eventOwner->id)
                ->where('source', ImportPreviewRevision::SOURCE_AI)
                ->orderByDesc('revision_no')
                ->first()
            : null;

        return $this->result(
            $eventOwner,
            $documentImport,
            replayed: true,
            stale: $runStatus === AiExtractionRunStatus::Superseded->value,
            revision: $revision,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        AiExtractionRun $run,
        DocumentImport $documentImport,
        bool $replayed,
        bool $stale,
        ?ImportPreviewRevision $revision = null,
    ): array {
        $runStatus = $run->status instanceof AiExtractionRunStatus
            ? $run->status->value
            : (string) $run->status;
        $importStatus = $documentImport->status instanceof DocumentImportStatus
            ? $documentImport->status->value
            : (string) $documentImport->status;

        return [
            'run' => [
                'public_id' => $run->public_id,
                'attempt_no' => $run->attempt_no,
                'status' => $runStatus,
            ],
            'document_import' => [
                'public_id' => $documentImport->public_id,
                'status' => $importStatus,
                'current_preview_revision' => $documentImport->current_preview_revision,
            ],
            'preview_revision' => $revision === null ? null : [
                'id' => $revision->id,
                'revision_no' => $revision->revision_no,
            ],
            'replayed' => $replayed,
            'stale' => $stale,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Throwable
     */
    private function resolveConcurrentEventClaim(
        int|string $runKey,
        int|string $documentImportKey,
        string $eventId,
        string $bodyDigest,
        QueryException $originalException,
    ): array {
        return DB::transaction(function () use (
            $runKey,
            $documentImportKey,
            $eventId,
            $bodyDigest,
            $originalException,
        ): array {
            /** @var DocumentImport $documentImport */
            $documentImport = DocumentImport::query()
                ->lockForUpdate()
                ->findOrFail($documentImportKey);
            /** @var AiExtractionRun $run */
            $run = AiExtractionRun::query()
                ->where('document_import_id', $documentImport->id)
                ->lockForUpdate()
                ->findOrFail($runKey);
            /** @var AiExtractionRun|null $eventOwner */
            $eventOwner = AiExtractionRun::query()
                ->where('provider', $run->provider)
                ->where('provider_event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($eventOwner === null) {
                throw $originalException;
            }

            return $this->replayedResultOrConflict($run, $eventOwner, $bodyDigest);
        }, 3);
    }

    private function isProviderEventUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'ai_extraction_runs_provider_event_unique')
            || (str_contains($message, 'unique constraint failed')
                && str_contains($message, 'ai_extraction_runs.provider_event_id'));
    }
}
