<?php

namespace App\Services\Documents;

use App\DTOs\Documents\VerifiedDocumentBlob;
use App\Enums\DocumentImportStatus;
use App\Enums\DocumentVersionCreatedVia;
use App\Exceptions\ApiProblemException;
use App\Models\DocumentImport;
use App\Models\DocumentVersion;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class DocumentVersionService
{
    public function __construct(private readonly DocumentBlobVerifier $verifier) {}

    /**
     * Register the one available source as revision 1. The legacy `version`
     * scalar is deliberately neither read nor changed.
     *
     * @param  array<string, mixed>|null  $mapping
     */
    public function registerInitialVersion(
        ProjectDocument $document,
        DocumentVersionCreatedVia $createdVia,
        ?User $actor = null,
        ?array $mapping = null,
    ): DocumentVersion {
        $this->assertRegistrationContext($document, $createdVia, $actor);

        return DB::transaction(function () use ($document, $createdVia, $actor, $mapping): DocumentVersion {
            // Match confirmation's lock order. In particular, backfill must
            // never hold the document lock while waiting for its import lock.
            $sourceId = ProjectDocument::query()->whereKey($document->getKey())->value('source_import_id');
            $source = $sourceId === null ? null : DocumentImport::query()->lockForUpdate()->find($sourceId);
            $locked = ProjectDocument::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($locked->source_import_id != $sourceId) {
                throw $this->provenanceConflict();
            }

            $this->assertRegistrationContext($locked, $createdVia, $actor);
            $this->assertImportState($locked, $source, $createdVia);
            $locked->setRelation('sourceImport', $source);
            $blob = $this->verifier->verifyDocument($locked, $mapping);
            $existing = $this->initialVersion($locked, lock: true);

            if ($existing !== null) {
                $this->assertSameBaseline($existing, $blob);

                return $existing;
            }

            try {
                return DocumentVersion::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'project_document_id' => $locked->getKey(),
                    'revision_no' => 1,
                    'created_via' => $createdVia,
                    'storage_disk' => $blob->storageDisk,
                    'storage_path' => $blob->storagePath,
                    'original_name' => $blob->originalName,
                    'mime_type' => $blob->mimeType,
                    'size_bytes' => $blob->sizeBytes,
                    'sha256' => $blob->sha256,
                    'integrity_basis' => $blob->integrityBasis,
                    'created_by' => $actor?->getKey(),
                    'verified_at' => $blob->verifiedAt,
                    'created_at' => now(),
                ]);
            } catch (QueryException $exception) {
                // The unique constraint is the final guard against another
                // writer that did not take the parent row lock. Never update
                // the winning row or turn a failed insert into revision 2.
                $existing = $this->initialVersion($locked, lock: true);

                if ($existing === null || ! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                $this->assertSameBaseline($existing, $blob);

                return $existing;
            }
        }, 3);
    }

    /** @param array<string, mixed>|null $mapping */
    public function previewInitialVersion(ProjectDocument $document, ?array $mapping = null): VerifiedDocumentBlob
    {
        $fresh = ProjectDocument::query()->findOrFail($document->getKey());
        $source = $fresh->source_import_id === null ? null : $fresh->sourceImport;
        $this->assertImportState($fresh, $source, DocumentVersionCreatedVia::Backfill);
        $blob = $this->verifier->verifyDocument($fresh, $mapping);
        $existing = $this->initialVersion($fresh);

        if ($existing !== null) {
            $this->assertSameBaseline($existing, $blob);
        }

        return $blob;
    }

    private function assertRegistrationContext(
        ProjectDocument $document,
        DocumentVersionCreatedVia $createdVia,
        ?User $actor,
    ): void {
        if (! $document->exists
            || ($createdVia === DocumentVersionCreatedVia::Backfill && $actor !== null)
            || ($createdVia !== DocumentVersionCreatedVia::Backfill && (! $actor?->exists))) {
            throw new LogicException('A persisted document and the correct registration actor are required.');
        }

        if (($createdVia === DocumentVersionCreatedVia::PhaseFiveConfirm && ! $document->isImportedOriginal())
            || ($createdVia === DocumentVersionCreatedVia::LegacyUpload && $document->isImportedOriginal())) {
            throw new LogicException('The registration source does not match the document provenance.');
        }

        if ($createdVia === DocumentVersionCreatedVia::PhaseFiveConfirm && DB::transactionLevel() === 0) {
            throw new LogicException('Confirmation registration requires the canonical transaction.');
        }
    }

    private function assertImportState(
        ProjectDocument $document,
        ?DocumentImport $source,
        DocumentVersionCreatedVia $createdVia,
    ): void {
        if (! $document->isImportedOriginal()) {
            return;
        }

        if ($source === null) {
            throw $this->provenanceConflict();
        }

        // An import association is an exact source identity, not merely a
        // relationship to any confirmed import in the same project.
        foreach ([
            'storage_disk' => 'storage_disk',
            'path' => 'storage_path',
            'original_name' => 'original_name',
            'mime_type' => 'mime_type',
            'checksum' => 'sha256',
        ] as $documentAttribute => $importAttribute) {
            if ($document->{$documentAttribute} !== $source->{$importAttribute}) {
                throw $this->provenanceConflict();
            }
        }

        if ((string) $document->size !== (string) $source->size_bytes
            || (string) $document->uploaded_by !== (string) $source->uploaded_by) {
            throw $this->provenanceConflict();
        }

        if ($source->status === DocumentImportStatus::Confirmed) {
            if ((int) $source->confirmed_project_id !== (int) $document->project_id) {
                throw $this->provenanceConflict();
            }

            return;
        }

        // Confirmation creates the canonical document before setting the
        // terminal import state, all inside the same outer transaction.
        if ($createdVia !== DocumentVersionCreatedVia::PhaseFiveConfirm
            || $source->status !== DocumentImportStatus::NeedsReview
            || $source->confirmed_project_id !== null
            || DB::transactionLevel() < 2) {
            throw $this->provenanceConflict();
        }
    }

    private function initialVersion(ProjectDocument $document, bool $lock = false): ?DocumentVersion
    {
        $query = DocumentVersion::query()->where('project_document_id', $document->getKey());

        if ($lock) {
            $query->lockForUpdate();
        }

        $versions = $query->get();

        if ($versions->count() > 1 || ($versions->isNotEmpty() && (int) $versions->first()->revision_no !== 1)) {
            throw $this->baselineConflict();
        }

        return $versions->first();
    }

    private function assertSameBaseline(DocumentVersion $version, VerifiedDocumentBlob $blob): void
    {
        if ($version->storage_disk !== $blob->storageDisk
            || $version->storage_path !== $blob->storagePath
            || $version->original_name !== $blob->originalName
            || $version->mime_type !== $blob->mimeType
            || (int) $version->size_bytes !== $blob->sizeBytes
            || ! hash_equals($version->sha256, $blob->sha256)
            || $version->integrity_basis !== $blob->integrityBasis) {
            throw $this->baselineConflict();
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && in_array((int) ($exception->errorInfo[1] ?? 0), [19, 1062], true);
    }

    private function provenanceConflict(): ApiProblemException
    {
        return new ApiProblemException(
            'The document import provenance is inconsistent or not confirmed.',
            'document_version_provenance_invalid',
            409,
        );
    }

    private function baselineConflict(): ApiProblemException
    {
        return new ApiProblemException(
            'The registered initial version conflicts with the verified source.',
            'document_version_baseline_conflict',
            409,
        );
    }
}
