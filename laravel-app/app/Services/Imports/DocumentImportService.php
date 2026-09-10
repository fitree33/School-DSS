<?php

namespace App\Services\Imports;

use App\Enums\AiExtractionRunStatus;
use App\Enums\DocumentImportProcessingStage;
use App\Enums\DocumentImportStatus;
use App\Exceptions\ApiProblemException;
use App\Jobs\ProcessDocumentImport;
use App\Models\AiExtractionRun;
use App\Models\AuditLog;
use App\Models\DocumentImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class DocumentImportService
{
    public function upload(User $actor, UploadedFile $file): DocumentImport
    {
        Gate::forUser($actor)->authorize('create', DocumentImport::class);
        $this->ensureAsynchronousProductionQueue();

        $maxBytes = max(1, (int) config('project_imports.upload.max_bytes'));
        $size = (int) ($file->getSize() ?: 0);
        $mimeType = (string) $file->getMimeType();
        $allowedMimeTypes = (array) config(
            'project_imports.upload.accepted_mime_types',
            ['application/pdf'],
        );

        if ($size < 1 || $size > $maxBytes || ! in_array($mimeType, $allowedMimeTypes, true)) {
            throw ApiProblemException::validation([
                'document' => ['The uploaded PDF does not meet the configured type or size constraints.'],
            ]);
        }

        $sourcePath = $file->getRealPath();

        if (! is_string($sourcePath) || ! is_file($sourcePath)) {
            throw new RuntimeException('The uploaded PDF is unavailable.');
        }

        $prefix = file_get_contents($sourcePath, false, null, 0, 5);

        if ($prefix !== '%PDF-') {
            throw ApiProblemException::validation([
                'document' => ['The uploaded document is not a valid PDF file.'],
            ]);
        }

        $disk = (string) config('project_imports.disk', 'project-imports');
        $storagePath = sprintf(
            'originals/%s/%s.pdf',
            now()->format('Y/m'),
            (string) Str::uuid(),
        );
        $stream = fopen($sourcePath, 'rb');

        if (! is_resource($stream)) {
            throw new RuntimeException('The uploaded PDF cannot be read.');
        }

        try {
            $stored = Storage::disk($disk)->put($storagePath, $stream);
        } finally {
            fclose($stream);
        }

        if (! $stored) {
            throw new RuntimeException('The uploaded PDF could not be stored.');
        }

        try {
            $documentImport = DB::transaction(function () use (
                $actor,
                $disk,
                $file,
                $mimeType,
                $size,
                $sourcePath,
                $storagePath,
            ): DocumentImport {
                $documentImport = DocumentImport::query()->create([
                    'uploaded_by' => $actor->id,
                    'uploader_department_id' => $actor->department_id,
                    'status' => DocumentImportStatus::Uploaded,
                    'processing_stage' => null,
                    'original_name' => $this->displayName($file->getClientOriginalName()),
                    'storage_disk' => $disk,
                    'storage_path' => $storagePath,
                    'mime_type' => $mimeType,
                    'size_bytes' => $size,
                    'sha256' => hash_file('sha256', $sourcePath),
                ]);

                AuditLog::record('document_import.uploaded', $documentImport, [], [
                    'public_id' => $documentImport->public_id,
                    'mime_type' => $documentImport->mime_type,
                    'size_bytes' => $documentImport->size_bytes,
                    'sha256' => $documentImport->sha256,
                ]);

                return $documentImport;
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storagePath);

            throw $exception;
        }

        $this->dispatchProcessing($documentImport);

        return $documentImport->refresh();
    }

    public function retry(User $actor, DocumentImport $documentImport): DocumentImport
    {
        Gate::forUser($actor)->authorize('retry', $documentImport);
        $this->ensureAsynchronousProductionQueue();

        $documentImport = DB::transaction(function () use ($actor, $documentImport): DocumentImport {
            $lockedImport = DocumentImport::query()->lockForUpdate()->findOrFail($documentImport->id);
            Gate::forUser($actor)->authorize('retry', $lockedImport);

            // Attempt one belongs to the original job, including failures before it claims a run.
            $attempt = max(1, (int) $lockedImport->extractionRuns()->max('attempt_no')) + 1;
            AiExtractionRun::query()->create([
                'document_import_id' => $lockedImport->id,
                'attempt_no' => $attempt,
                'provider' => (string) config('project_imports.provider.driver', 'n8n'),
                'model_name' => config('project_imports.provider.model_name'),
                'schema_version' => (string) config('project_imports.provider.schema_version', 'project-import.v1'),
                'prompt_version' => config('project_imports.provider.prompt_version'),
                'status' => AiExtractionRunStatus::Queued,
            ]);

            $lockedImport->update([
                'status' => DocumentImportStatus::Processing,
                'processing_stage' => DocumentImportProcessingStage::Validating,
                'active_extraction_attempt' => $attempt,
                'failure_stage' => null,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            AuditLog::record('document_import.retry_requested', $lockedImport, [], [
                'public_id' => $lockedImport->public_id,
            ]);

            return $lockedImport;
        }, 3);

        $this->dispatchProcessing($documentImport);

        return $documentImport->refresh();
    }

    private function dispatchProcessing(DocumentImport $documentImport): void
    {
        $attempt = $documentImport->active_extraction_attempt;

        try {
            ProcessDocumentImport::dispatch($documentImport->id, $attempt)
                ->onQueue((string) config('project_imports.processing.queue', 'document-imports'));
        } catch (Throwable $exception) {
            DB::transaction(function () use ($attempt, $documentImport): void {
                $lockedImport = DocumentImport::query()->lockForUpdate()->find($documentImport->id);

                if ($lockedImport === null
                    || $lockedImport->active_extraction_attempt !== $attempt
                    || ! in_array($lockedImport->status, [DocumentImportStatus::Uploaded, DocumentImportStatus::Processing], true)) {
                    return;
                }

                if ($attempt !== null) {
                    $run = $lockedImport->extractionRuns()->where('attempt_no', $attempt)->lockForUpdate()->first();

                    if ($run?->status !== AiExtractionRunStatus::Queued) {
                        return;
                    }

                    $run->update([
                        'status' => AiExtractionRunStatus::Failed,
                        'finished_at' => now(),
                        'failure_code' => 'IMPORT_QUEUE_DISPATCH_FAILED',
                        'failure_message' => 'The import could not be queued for processing.',
                    ]);
                }

                $lockedImport->update([
                    'status' => DocumentImportStatus::Failed,
                    'processing_stage' => null,
                    'failure_stage' => 'queue',
                    'failure_code' => 'IMPORT_QUEUE_DISPATCH_FAILED',
                    'failure_message' => 'The import could not be queued for processing.',
                ]);
            }, 3);

            throw new ApiProblemException(
                'The import was stored but could not be queued. You can retry it safely.',
                'import_queue_unavailable',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }

    private function ensureAsynchronousProductionQueue(): void
    {
        if (app()->environment('production') && Queue::getDefaultDriver() === 'sync') {
            throw new ApiProblemException(
                'Project imports require an asynchronous queue in production.',
                'import_queue_unavailable',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }

    private function displayName(string $name): string
    {
        $basename = basename(str_replace('\\', '/', $name));
        $withoutControls = preg_replace('/[\x00-\x1F\x7F]/u', '', $basename) ?: 'document.pdf';

        return Str::limit(trim($withoutControls), 255, '');
    }
}
