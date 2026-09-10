<?php

namespace App\Services\Imports;

use App\DTOs\Imports\PreviewRevisionResult;
use App\Exceptions\ApiProblemException;
use App\Models\AuditLog;
use App\Models\DocumentImport;
use App\Models\ImportPreviewRevision;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class ImportPreviewService
{
    public function __construct(private readonly ProjectImportPayloadValidator $payloadValidator) {}

    public function appendUserRevision(
        User $actor,
        DocumentImport $documentImport,
        int $baseRevisionId,
        array $payload,
        string $idempotencyKey,
    ): PreviewRevisionResult {
        return DB::transaction(function () use (
            $actor,
            $baseRevisionId,
            $documentImport,
            $idempotencyKey,
            $payload,
        ): PreviewRevisionResult {
            $lockedImport = DocumentImport::query()->lockForUpdate()->findOrFail($documentImport->id);
            Gate::forUser($actor)->authorize('review', $lockedImport);

            $knownPayload = Arr::only($payload, ProjectImportPayloadValidator::ALLOWED_FIELDS);
            $unknownFields = array_values(array_diff(array_keys($payload), ProjectImportPayloadValidator::ALLOWED_FIELDS));

            if ($unknownFields !== []) {
                throw ApiProblemException::validation([
                    '_payload' => ['The preview contains unsupported fields: '.implode(', ', $unknownFields).'.'],
                ]);
            }

            $baseRevision = ImportPreviewRevision::query()
                ->where('document_import_id', $lockedImport->id)
                ->whereKey($baseRevisionId)
                ->first();
            $currentRevision = ImportPreviewRevision::query()
                ->where('document_import_id', $lockedImport->id)
                ->where('revision_no', $lockedImport->current_preview_revision)
                ->first();

            $existing = ImportPreviewRevision::query()
                ->where('document_import_id', $lockedImport->id)
                ->where('client_idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if ($baseRevision !== null
                    && $existing->parent_revision_no === $baseRevision->revision_no
                    && $this->payloadsMatch($existing->payload, $knownPayload)) {
                    return new PreviewRevisionResult($existing, false);
                }

                throw new ApiProblemException(
                    'The idempotency key has already been used for a different preview revision.',
                    'idempotency_conflict',
                    Response::HTTP_CONFLICT,
                );
            }

            if ($baseRevision === null || $currentRevision === null || $baseRevision->isNot($currentRevision)) {
                throw new ApiProblemException(
                    'The preview changed after this edit began. Reload the latest revision and try again.',
                    'stale_preview_revision',
                    Response::HTTP_CONFLICT,
                );
            }

            $revision = ImportPreviewRevision::query()->create([
                'document_import_id' => $lockedImport->id,
                'revision_no' => $baseRevision->revision_no + 1,
                'parent_revision_no' => $baseRevision->revision_no,
                'source_extraction_run_id' => $baseRevision->source_extraction_run_id,
                'source' => ImportPreviewRevision::SOURCE_USER,
                'payload' => $knownPayload,
                'validation_errors' => $this->payloadValidator->errors($knownPayload, $actor),
                'warnings' => $baseRevision->warnings ?? [],
                'field_confidence' => [],
                'edited_by' => $actor->id,
                'client_idempotency_key' => $idempotencyKey,
            ]);

            $lockedImport->update([
                'current_preview_revision' => $revision->revision_no,
            ]);

            AuditLog::record('document_import.preview_revised', $lockedImport, [], [
                'revision_id' => $revision->id,
                'revision_no' => $revision->revision_no,
                'validation_error_fields' => array_keys($revision->validation_errors ?? []),
            ]);

            return new PreviewRevisionResult($revision, true);
        }, 3);
    }

    private function payloadsMatch(array $left, array $right): bool
    {
        return $this->canonicalize($left) === $this->canonicalize($right);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
