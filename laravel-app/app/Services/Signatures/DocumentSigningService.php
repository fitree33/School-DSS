<?php

namespace App\Services\Signatures;

use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Exceptions\ApiProblemException;
use App\Models\AuditLog;
use App\Models\DocumentSignature;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectSignatureSlot;
use App\Models\SignatureAsset;
use App\Models\SignaturePlacement;
use App\Models\User;
use App\Services\Documents\DocumentBlobVerifier;
use App\Services\Documents\DocumentPdfPageCounter;
use App\Services\Documents\DocumentPrivateFilesystem;
use App\Services\Documents\PdfSignatureStamper;
use App\Services\Documents\SignedDocumentStorage;
use App\Services\Projects\ProjectSignatureSlotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final class DocumentSigningService
{
    public function __construct(
        private readonly DocumentBlobVerifier $verifier,
        private readonly DocumentPdfPageCounter $pages,
        private readonly SignatureAssetStorage $assets,
        private readonly PdfSignatureStamper $stamper,
        private readonly SignedDocumentStorage $storage,
        private readonly ProjectSignatureSlotService $slots,
    ) {}

    public function sign(User $actor, Project $project, ProjectDocument $document, DocumentVersion $source, ProjectSignatureSlot $slot, array $input): DocumentSignature
    {
        $this->validateInput($input);
        $this->authorizeContext($actor, $project, $document, $source, $slot);
        $stored = $workspace = $snapshot = null;
        $commitStarted = false;
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            // Matches assignment and placement: project -> actor -> slot, then
            // document/version -> asset -> placement. Keep these locks through
            // generation so saves, retirement and reassignment serialize.
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $actor = User::withTrashed()->lockForUpdate()->findOrFail($actor->id);
            $actor->load('role.permissions');
            $slot = ProjectSignatureSlot::query()->lockForUpdate()->findOrFail($slot->id);
            $document = ProjectDocument::query()->lockForUpdate()->findOrFail($document->id);
            $source = DocumentVersion::query()->lockForUpdate()->findOrFail($source->id);
            $this->authorizeContext($actor, $project, $document, $source, $slot);

            // A replay reuses a receipt only while its exact saved snapshot and
            // assignment still apply and its generated version remains current.
            $existing = DocumentSignature::query()->where('signer_id', $actor->id)
                ->where('idempotency_key', $input['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->source_document_version_id !== (int) $source->id
                    || $existing->project_signature_slot_id !== (int) $slot->id
                    || $existing->assignment_revision !== $input['assignment_revision']
                    || ! hash_equals($existing->placement_fingerprint, $input['placement_fingerprint'])) {
                    throw $this->conflict('document_signing_idempotency_conflict', 'This signing key belongs to a different request.');
                }
                $this->lockSigningState($actor, $project, $document, $source, $slot, $input, $existing);
                $existing->load(['sourceVersion', 'signedVersion']);
                // Both immutable blobs remain part of the completed snapshot.
                $this->verifier->verifyVersionToTemporaryFile($source)->close();
                $this->verifier->verifyVersionToTemporaryFile($existing->signedVersion)->close();
                DB::rollBack();

                return $existing;
            }

            [$asset, $placement] = $this->lockSigningState($actor, $project, $document, $source, $slot, $input);
            $snapshot = $this->verifier->verifyVersionToTemporaryFile($source);
            $workspace = $this->assets->beginRequest();
            $privacy = app(DocumentPrivateFilesystem::class);
            $privacy->assertCanonicalPath($workspace->directory());
            $privacy->assertPrivateFile($workspace->directory());
            $image = $workspace->directory().DIRECTORY_SEPARATOR.'signature.png';
            $output = $workspace->directory().DIRECTORY_SEPARATOR.'signed.pdf';
            $imageBytes = $this->assets->readVerified($asset);
            $handle = @fopen($image, 'x+b');
            if (! is_resource($handle)) {
                throw new ApiProblemException('The signing workspace is unavailable.', 'document_signing_storage_failed', 503);
            }
            try {
                if (! @chmod($image, 0600)) {
                    throw new ApiProblemException('The signing workspace is unavailable.', 'document_signing_storage_failed', 503);
                }
                $privacy->assertPrivateFile($image);
                if (fwrite($handle, $imageBytes) !== strlen($imageBytes) || ! fflush($handle)) {
                    throw new ApiProblemException('The signing workspace is unavailable.', 'document_signing_storage_failed', 503);
                }
            } finally {
                fclose($handle);
            }
            $rectangle = $placement->only(['page', 'x', 'y', 'width', 'height']);
            $pageCount = $this->stamper->stamp($snapshot->path, $image, $output, $rectangle);
            $privacy->assertPrivateFile($output);
            $stored = $this->storage->store($output);
            $version = new DocumentVersion([
                'project_document_id' => $document->id,
                'revision_no' => $source->revision_no + 1,
                'created_via' => DocumentVersionCreatedVia::Signature,
                'storage_disk' => $stored->disk, 'storage_path' => $stored->storagePath,
                'original_name' => $source->original_name, 'mime_type' => 'application/pdf',
                'size_bytes' => $stored->sizeBytes, 'sha256' => $stored->sha256,
                'integrity_basis' => DocumentVersionIntegrityBasis::RecordedSha256,
                'created_by' => $actor->id, 'verified_at' => now('UTC'),
            ]);
            // Read back canonical private bytes, check checksum/size and parse
            // the stored PDF before inserting either canonical database row.
            if ($this->pages->count($version) !== $pageCount || hash_equals($source->sha256, $stored->sha256)) {
                throw new ApiProblemException('The signed PDF failed verification.', 'document_signing_verification_failed', 503);
            }
            // Recheck source bytes as well as the authoritative locked state.
            $this->verifier->verifyVersionToTemporaryFile($source)->close();
            $actor->refresh()->load('role.permissions');
            $slot->refresh();
            $this->lockSigningState($actor, $project->refresh(), $document->refresh(), $source, $slot, $input);
            $version->save();
            $signature = DocumentSignature::query()->create([
                'project_id' => $project->id, 'project_document_id' => $document->id,
                'source_document_version_id' => $source->id, 'signed_document_version_id' => $version->id,
                'project_signature_slot_id' => $slot->id, 'assignment_revision' => $slot->assignment_revision,
                'signer_id' => $actor->id, 'signature_asset_id' => $asset->id,
                ...$rectangle,
                'before_sha256' => $source->sha256, 'after_sha256' => $version->sha256,
                'signed_at' => now('UTC'), 'idempotency_key' => $input['idempotency_key'],
                'placement_fingerprint' => $input['placement_fingerprint'],
            ]);
            // Reuse the existing audit log architecture, in the same transaction.
            $http = ! app()->runningInConsole();
            AuditLog::query()->create([
                'user_id' => $actor->id, 'action' => 'document.signed',
                'auditable_type' => DocumentSignature::class, 'auditable_id' => $signature->id,
                'old_values' => ['source_version_id' => $source->public_id, 'sha256' => $source->sha256],
                'new_values' => [
                    'signature_id' => $signature->public_id, 'signed_version_id' => $version->public_id,
                    'project_id' => $project->id, 'project_document_id' => $document->id,
                    'slot_id' => $slot->id, 'assignment_revision' => $slot->assignment_revision,
                    'signer_id' => $actor->id, 'signature_asset_id' => $asset->public_id,
                    'sha256' => $version->sha256, 'placement' => $rectangle,
                ],
                'ip_address' => $http ? request()->ip() : null,
                'user_agent' => $http ? request()->userAgent() : null,
            ]);
            $commitStarted = true;
            DB::commit();

            return $signature->setRelation('sourceVersion', $source)->setRelation('signedVersion', $version);
        } catch (Throwable $exception) {
            $rolledBack = false;
            try {
                if (DB::transactionLevel() > $level) {
                    DB::rollBack($level);
                    $rolledBack = true;
                }
            } catch (Throwable) {
                // An uncertain commit/rollback must retain the generated file.
            }
            if (! $commitStarted && $rolledBack && $stored !== null) {
                $this->storage->rollback($stored);
            }
            throw $exception;
        } finally {
            $snapshot?->close();
            if ($workspace !== null) $this->assets->cleanup($workspace);
        }
    }

    private function lockSigningState(User $actor, Project $project, ProjectDocument $document, DocumentVersion $source, ProjectSignatureSlot $slot, array $input, ?DocumentSignature $replay = null): array
    {
        $this->authorizeContext($actor, $project, $document, $source, $slot);
        // Identity is explicit: no administrator, role or owner bypass.
        abort_unless($slot->assigned_user_id === (int) $actor->id, 403);
        Gate::forUser($actor)->authorize('place', [SignaturePlacement::class, $project, $document, $source, $slot]);
        $this->slots->slotsFor($project);
        if ($slot->assignment_revision !== $input['assignment_revision']) {
            throw $this->conflict('signature_assignment_changed', 'The signature assignment changed. Reload the document.');
        }
        if ($replay === null && DocumentSignature::query()->where('source_document_version_id', $source->id)->where('project_signature_slot_id', $slot->id)->exists()) {
            throw $this->conflict('document_already_signed', 'This source version and slot have already been signed.');
        }
        $current = DocumentVersion::query()->where('project_document_id', $document->id)->orderByDesc('revision_no')->lockForUpdate()->firstOrFail();
        if ((int) $current->id !== (int) ($replay?->signed_document_version_id ?? $source->id)) {
            throw $this->conflict('document_signing_source_changed', 'A newer document version exists. Open it before signing.');
        }
        if ($source->mime_type !== 'application/pdf' || $source->size_bytes > config('document_signing.max_pdf_bytes') || $source->revision_no >= 4294967295) {
            throw ApiProblemException::validation(['document' => ['A supported PDF version is required.']]);
        }
        $query = SignaturePlacement::query()->where('document_version_id', $source->id)->where('project_signature_slot_id', $slot->id);
        // Project lock serializes placement saves; acquire asset before placement
        // to retain Phase 6B/6C's retirement/save lock order.
        $assetId = (clone $query)->value('signature_asset_id');
        $asset = SignatureAsset::query()->whereKey($assetId)->lockForUpdate()->first();
        $placement = $query->lockForUpdate()->first();
        if ($placement === null || $placement->updated_by !== (int) $actor->id
            || $placement->assignment_revision !== $slot->assignment_revision
            || ! hash_equals(PlacementFingerprint::for($placement), $input['placement_fingerprint'])) {
            throw $this->conflict('signature_placement_changed', 'Save and review the current placement before signing.');
        }
        if ($asset === null || (int) $asset->owner_id !== (int) $actor->id || ! $asset->isEligibleForSigning()) {
            throw $this->conflict('signature_asset_ineligible', 'An active signature asset owned by the signer is required.');
        }

        return [$asset, $placement];
    }

    private function authorizeContext(User $actor, Project $project, ProjectDocument $document, DocumentVersion $version, ProjectSignatureSlot $slot): void
    {
        abort_unless(! $actor->trashed() && $actor->is_active === true, 403);
        abort_unless((int) $document->project_id === (int) $project->id
            && (int) $version->project_document_id === (int) $document->id
            && (int) $slot->project_id === (int) $project->id, 404);
        abort_unless(Gate::forUser($actor)->allows('viewAny', [SignaturePlacement::class, $project, $document, $version]), 404);
    }

    private function validateInput(array $input): void
    {
        $keys = ['assignment_revision', 'placement_fingerprint', 'idempotency_key'];
        if (array_diff(array_keys($input), $keys) !== [] || array_diff($keys, array_keys($input)) !== []
            || ! is_int($input['assignment_revision']) || $input['assignment_revision'] < 1 || $input['assignment_revision'] > 4294967295
            || ! is_string($input['placement_fingerprint']) || preg_match('/\A[0-9a-f]{64}\z/D', $input['placement_fingerprint']) !== 1
            || ! is_string($input['idempotency_key']) || ! Str::isUuid($input['idempotency_key'])) {
            throw ApiProblemException::validation(['signing' => ['Invalid signing request.']]);
        }
    }

    private function conflict(string $code, string $message): ApiProblemException
    {
        return new ApiProblemException($message, $code, 409);
    }
}
