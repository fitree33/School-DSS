<?php

namespace App\Services\Signatures;

use App\Contracts\Signatures\SignatureImageNormalizer;
use App\Exceptions\ApiProblemException;
use App\Models\AuditLog;
use App\Models\SignatureAsset;
use App\Models\User;
use App\Policies\SignatureAssetPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SignatureAssetService
{
    public function __construct(
        private readonly SignatureAssetStorage $storage,
        private readonly SignatureImageNormalizer $normalizer,
        private readonly SignatureAssetPolicy $policy,
    ) {}

    public function listOwn(User $actor): LengthAwarePaginator
    {
        $this->assertActor($actor);

        return SignatureAsset::query()->where('owner_id', $actor->getKey())
            ->orderByDesc('id')->paginate(25);
    }

    public function own(User $actor, string $publicId, bool $lock = false): SignatureAsset
    {
        $this->assertActor($actor);
        $query = SignatureAsset::query()->where('public_id', $publicId)->where('owner_id', $actor->getKey());
        if ($lock) {
            $query->lockForUpdate();
        }
        $asset = $query->first();
        // Direct identity and policy checks deliberately do not depend on role gates.
        abort_unless($asset instanceof SignatureAsset && (int) $asset->owner_id === (int) $actor->getKey()
            && $this->policy->view($actor, $asset), 404);

        return $asset;
    }

    public function upload(User $actor, #[\SensitiveParameter] UploadedFile $upload): SignatureAsset
    {
        $this->assertActor($actor);
        $this->assertOwnTransaction();
        if (! $upload->isValid()) {
            throw ApiProblemException::validation(['image' => ['A valid PNG upload is required.']]);
        }
        if ($upload->getSize() > PngStructureValidator::MAX_UPLOAD_BYTES) {
            throw new ApiProblemException('The signature image exceeds the upload limit.', 'signature_upload_too_large', 413);
        }
        $this->normalizer->assertAvailable();
        $workspace = $this->storage->beginRequest();
        $file = null;
        $transactionStarted = false;
        $commitAttempted = false;
        try {
            $input = $this->storage->stageUpload($upload->getPathname(), $workspace);
            $normalized = $this->normalizer->normalize($input, $workspace->directory());
            $publicId = (string) Str::uuid();
            $file = $this->storage->storeNormalized($normalized, $publicId, $workspace);
            unset($normalized);
            DB::beginTransaction();
            $transactionStarted = true;
            $freshActor = User::withTrashed()->lockForUpdate()->find($actor->getKey());
            abort_unless($freshActor instanceof User && $this->policy->create($freshActor), 403);
            $asset = SignatureAsset::query()->create([
                'public_id' => $publicId,
                'owner_id' => $freshActor->getKey(),
                'storage_key' => $file->storageKey,
                'sha256' => $file->sha256,
                'size_bytes' => $file->sizeBytes,
                'width' => $file->width,
                'height' => $file->height,
                'mime_type' => 'image/png',
                'normalization_version' => SignatureAsset::NORMALIZATION_VERSION,
                'status' => 'active',
            ]);
            $this->audit('signature_asset.uploaded', $freshActor, $asset, [], ['status' => 'active']);
            // From this point any error may mean that commit succeeded on the server.
            $commitAttempted = true;
            $this->commitTransaction();
            $this->storage->preserve($file);

            return $asset;
        } catch (Throwable $exception) {
            $definiteRollback = ! $transactionStarted;
            if ($transactionStarted && DB::transactionLevel() > 0) {
                try {
                    DB::rollBack();
                    $definiteRollback = ! $commitAttempted;
                } catch (Throwable) {
                    $definiteRollback = false;
                }
            }
            if ($file !== null) {
                if ($definiteRollback && ! $commitAttempted) {
                    $this->storage->rollback($file);
                } else {
                    // A later guarded reconciliation must establish authoritative DB state.
                    $this->storage->preserve($file);
                }
            }
            if ($exception instanceof ApiProblemException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            // Never forward database bindings, file paths or original exception arguments.
            throw new ApiProblemException('The signature asset could not be saved.', 'signature_asset_unavailable', 503);
        } finally {
            $this->storage->cleanup($workspace);
        }
    }

    public function preview(User $actor, string $publicId): string
    {
        $asset = $this->own($actor, $publicId);
        $bytes = $this->storage->readVerified($asset);
        try {
            $this->audit('signature_asset.previewed', $actor, $asset, [], ['status' => $asset->status]);
        } catch (Throwable) {
            throw new ApiProblemException('The signature preview is unavailable.', 'signature_asset_unavailable', 503);
        }

        return $bytes;
    }

    public function retire(User $actor, string $publicId, ?string $reason): SignatureAsset
    {
        $this->own($actor, $publicId);
        $this->assertOwnTransaction();
        if ($reason !== null && mb_strlen($reason) > SignatureAsset::MAX_RETIREMENT_REASON_LENGTH) {
            throw ApiProblemException::validation(['reason' => ['The retirement reason is too long.']]);
        }
        try {
            return DB::transaction(function () use ($actor, $publicId, $reason): SignatureAsset {
                $freshActor = User::withTrashed()->lockForUpdate()->find($actor->getKey());
                abort_unless($freshActor instanceof User && $this->policy->viewAny($freshActor), 403);
                $asset = $this->own($freshActor, $publicId, true);
                if ($asset->status === 'retired') {
                    return $asset;
                }
                $asset->fill([
                    'status' => 'retired',
                    'retired_at' => now('UTC'),
                    'retired_by' => $freshActor->getKey(),
                    'retirement_reason' => $reason,
                ])->save();
                $this->audit('signature_asset.retired', $freshActor, $asset, ['status' => 'active'], ['status' => 'retired']);

                return $asset;
            });
        } catch (Throwable $exception) {
            if ($exception instanceof ApiProblemException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            throw new ApiProblemException('The signature asset could not be retired.', 'signature_asset_unavailable', 503);
        }
    }

    protected function commitTransaction(): void
    {
        DB::commit();
    }

    protected function audit(string $action, User $actor, SignatureAsset $asset, array $before, array $after): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->getKey(),
            'action' => $action,
            'auditable_type' => SignatureAsset::class,
            'auditable_id' => $asset->getKey(),
            'old_values' => $before ?: null,
            'new_values' => ['public_id' => $asset->public_id, ...$after],
            // Client-controlled headers and image/locator metadata are excluded.
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }

    private function assertActor(User $actor): void
    {
        abort_unless($this->policy->viewAny($actor), 403);
    }

    private function assertOwnTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Signature mutations must own their database transaction.');
        }
    }
}
