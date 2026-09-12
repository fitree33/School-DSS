<?php

namespace App\Services\Imports;

use App\DTOs\Imports\ImportConfirmationResult;
use App\Enums\AiExtractionRunStatus;
use App\Enums\DocumentImportStatus;
use App\Enums\DocumentVersionCreatedVia;
use App\Exceptions\ApiProblemException;
use App\Models\AiExtractionRun;
use App\Models\AuditLog;
use App\Models\DocumentContent;
use App\Models\DocumentImport;
use App\Models\ImportPreviewRevision;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Services\Documents\DocumentVersionService;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ImportConfirmationService
{
    public function __construct(
        private readonly ProjectImportPayloadValidator $payloadValidator,
        private readonly ProjectService $projects,
        private readonly DocumentVersionService $versions,
    ) {}

    public function confirm(
        User $actor,
        DocumentImport $documentImport,
        int $previewRevisionId,
        string $idempotencyKey,
    ): ImportConfirmationResult {
        return DB::transaction(function () use (
            $actor,
            $documentImport,
            $idempotencyKey,
            $previewRevisionId,
        ): ImportConfirmationResult {
            $lockedImport = DocumentImport::query()->lockForUpdate()->findOrFail($documentImport->id);
            Gate::forUser($actor)->authorize('confirm', $lockedImport);
            Gate::forUser($actor)->authorize('create', Project::class);

            $requestedRevision = ImportPreviewRevision::query()
                ->where('document_import_id', $lockedImport->id)
                ->whereKey($previewRevisionId)
                ->lockForUpdate()
                ->first();
            $idempotencyKeyHash = hash('sha256', $idempotencyKey);

            if ($lockedImport->status === DocumentImportStatus::Confirmed) {
                if ($requestedRevision === null
                    || $requestedRevision->revision_no !== $lockedImport->confirmed_preview_revision
                    || ! is_string($lockedImport->confirmation_idempotency_key_hash)
                    || ! hash_equals(
                        $lockedImport->confirmation_idempotency_key_hash,
                        $idempotencyKeyHash,
                    )) {
                    throw new ApiProblemException(
                        'This import has already been confirmed with a different request.',
                        'idempotency_conflict',
                        Response::HTTP_CONFLICT,
                    );
                }

                $project = Project::withTrashed()->find($lockedImport->confirmed_project_id);

                if ($project === null) {
                    throw new ApiProblemException(
                        'The confirmed project is no longer available.',
                        'confirmed_project_unavailable',
                        Response::HTTP_CONFLICT,
                    );
                }

                return new ImportConfirmationResult($lockedImport, $project, false);
            }

            if ($lockedImport->status !== DocumentImportStatus::NeedsReview) {
                throw new ApiProblemException(
                    'Only an import awaiting review can be confirmed.',
                    'invalid_import_state',
                    Response::HTTP_CONFLICT,
                );
            }

            $revision = ImportPreviewRevision::query()
                ->where('document_import_id', $lockedImport->id)
                ->where('revision_no', $lockedImport->current_preview_revision)
                ->lockForUpdate()
                ->first();

            if ($revision === null
                || $requestedRevision === null
                || (int) $revision->getKey() !== (int) $requestedRevision->getKey()) {
                throw new ApiProblemException(
                    'The selected preview is stale. Reload the latest revision before confirming.',
                    'stale_preview_revision',
                    Response::HTTP_CONFLICT,
                );
            }

            $attributes = $this->payloadValidator->validateForConfirmation($revision->payload, $actor);
            $indicators = Arr::pull($attributes, 'indicators', []);
            $run = AiExtractionRun::query()
                ->where('document_import_id', $lockedImport->id)
                ->whereKey($revision->source_extraction_run_id)
                ->where('attempt_no', $lockedImport->active_extraction_attempt)
                ->where('status', AiExtractionRunStatus::Succeeded->value)
                ->lockForUpdate()
                ->first();

            if ($run === null
                || ! is_string($run->extracted_text)
                || trim($run->extracted_text) === ''
                || ! is_string($run->extracted_text_sha256)
                || ! hash_equals($run->extracted_text_sha256, hash('sha256', $run->extracted_text))) {
                throw new ApiProblemException(
                    'The verified text extraction for this preview is unavailable.',
                    'extraction_source_unavailable',
                    Response::HTTP_CONFLICT,
                );
            }

            $this->assertOriginalBlobIntegrity($lockedImport);
            $project = $this->projects->createInTransaction($actor, $attributes);

            $kpiIds = [];

            foreach ($indicators as $indicator) {
                $kpiIds[] = $project->kpis()->create([
                    'name' => $indicator['name'],
                    'target_value' => $indicator['target_value'] ?? null,
                    'unit' => $indicator['unit'] ?? null,
                ])->id;
            }

            $projectDocument = ProjectDocument::query()->create([
                'project_id' => $project->id,
                'source_import_id' => $lockedImport->id,
                'original_name' => $lockedImport->original_name,
                'path' => $lockedImport->storage_path,
                'storage_disk' => $lockedImport->storage_disk,
                'mime_type' => $lockedImport->mime_type,
                'size' => $lockedImport->size_bytes,
                'uploaded_by' => $lockedImport->uploaded_by,
                'checksum' => $lockedImport->sha256,
                'version' => 1,
                'processing_status' => 'completed',
                'processed_at' => $run->finished_at ?? now(),
            ]);

            DocumentContent::query()->create([
                'document_id' => $projectDocument->id,
                'extracted_text' => $run->extracted_text,
                'language' => $lockedImport->language,
                'processed_at' => $run->finished_at ?? now(),
            ]);

            $this->versions->registerInitialVersion($projectDocument, DocumentVersionCreatedVia::PhaseFiveConfirm, $actor);

            $lockedImport->update([
                'status' => DocumentImportStatus::Confirmed,
                'processing_stage' => null,
                'confirmed_preview_revision' => $revision->revision_no,
                'confirmation_idempotency_key_hash' => $idempotencyKeyHash,
                'confirmed_project_id' => $project->id,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'failure_stage' => null,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            if ($kpiIds !== []) {
                AuditLog::record('project.imported_kpis_created', $project, [], [
                    'source_import_id' => $lockedImport->id,
                    'kpi_ids' => $kpiIds,
                ]);
            }

            AuditLog::record('project_document.import_associated', $projectDocument, [], [
                'project_id' => $project->id,
                'source_import_id' => $lockedImport->id,
                'sha256' => $lockedImport->sha256,
            ]);
            AuditLog::record('document_import.confirmed', $lockedImport, [], [
                'project_id' => $project->id,
                'project_document_id' => $projectDocument->id,
                'preview_revision_id' => $revision->id,
                'preview_revision_no' => $revision->revision_no,
                'idempotency_key_sha256' => $idempotencyKeyHash,
            ]);

            return new ImportConfirmationResult($lockedImport->refresh(), $project, true);
        }, 3);
    }

    private function assertOriginalBlobIntegrity(DocumentImport $documentImport): void
    {
        try {
            $disk = Storage::disk($documentImport->storage_disk);
            $actualSize = $disk->size($documentImport->storage_path);
            $stream = $disk->readStream($documentImport->storage_path);
        } catch (Throwable) {
            throw new ApiProblemException(
                'The immutable original PDF is unavailable.',
                'original_document_unavailable',
                Response::HTTP_CONFLICT,
            );
        }

        if (! is_resource($stream)) {
            throw new ApiProblemException(
                'The immutable original PDF is unavailable.',
                'original_document_unavailable',
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $hash = hash_init('sha256');
            $bytesRead = hash_update_stream($hash, $stream);
            $actualHash = hash_final($hash);
        } catch (Throwable) {
            throw new ApiProblemException(
                'The immutable original PDF cannot be read.',
                'original_document_unavailable',
                Response::HTTP_CONFLICT,
            );
        } finally {
            fclose($stream);
        }

        if ($bytesRead === false
            || $bytesRead !== (int) $documentImport->size_bytes
            || (int) $actualSize !== (int) $documentImport->size_bytes
            || ! hash_equals(strtolower($documentImport->sha256), strtolower($actualHash))) {
            throw new ApiProblemException(
                'The immutable original PDF failed its integrity check.',
                'original_document_integrity_failed',
                Response::HTTP_CONFLICT,
            );
        }
    }
}
