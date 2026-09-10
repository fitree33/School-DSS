<?php

namespace App\Models;

use App\Enums\AiExtractionRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class AiExtractionRun extends Model
{
    use HasFactory;

    private const IMMUTABLE_IDENTITY_ATTRIBUTES = [
        'public_id',
        'document_import_id',
        'attempt_no',
        'provider',
        'schema_version',
    ];

    private const LATE_CALLBACK_ATTRIBUTES = [
        'status',
        'provider_event_id',
        'callback_digest',
        'callback_received_at',
        'finished_at',
    ];

    protected $fillable = [
        'public_id',
        'document_import_id',
        'attempt_no',
        'provider',
        'model_name',
        'schema_version',
        'prompt_version',
        'status',
        'extracted_text',
        'extracted_text_sha256',
        'raw_result',
        'normalized_result',
        'confidence',
        'warnings',
        'provider_job_id',
        'provider_event_id',
        'callback_digest',
        'started_at',
        'dispatched_at',
        'callback_received_at',
        'finished_at',
        'failure_code',
        'failure_message',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'status' => AiExtractionRunStatus::class,
        'raw_result' => 'array',
        'normalized_result' => 'array',
        'confidence' => 'array',
        'warnings' => 'array',
        'started_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'callback_received_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->public_id ??= (string) Str::uuid();
        });

        static::updating(function (self $run): void {
            if ($run->isDirty(self::IMMUTABLE_IDENTITY_ATTRIBUTES)) {
                throw new LogicException('AI extraction run identity cannot be changed.');
            }

            if (($run->getRawOriginal('extracted_text') !== null
                    || $run->getRawOriginal('extracted_text_sha256') !== null)
                && $run->isDirty(['extracted_text', 'extracted_text_sha256'])) {
                throw new LogicException('Verified extraction text and its digest are immutable.');
            }

            $originalStatus = AiExtractionRunStatus::tryFrom((string) $run->getRawOriginal('status'));

            if ($run->isDirty('status') && ! $originalStatus?->canTransitionTo($run->status)) {
                throw new LogicException('Invalid AI extraction run status transition.');
            }

            if ($originalStatus?->isTerminal()
                && ! $run->isAllowedLateCallbackSupersession($originalStatus)) {
                throw new LogicException('Completed AI extraction runs are immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('AI extraction runs cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function documentImport(): BelongsTo
    {
        return $this->belongsTo(DocumentImport::class);
    }

    public function previewRevisions(): HasMany
    {
        return $this->hasMany(ImportPreviewRevision::class, 'source_extraction_run_id');
    }

    private function isAllowedLateCallbackSupersession(AiExtractionRunStatus $originalStatus): bool
    {
        $nextStatus = $this->status instanceof AiExtractionRunStatus
            ? $this->status
            : AiExtractionRunStatus::tryFrom((string) $this->status);
        $dirtyAttributes = array_keys($this->getDirty());

        return $originalStatus === AiExtractionRunStatus::Failed
            && $nextStatus === AiExtractionRunStatus::Superseded
            && $this->getRawOriginal('provider_event_id') === null
            && array_diff($dirtyAttributes, self::LATE_CALLBACK_ATTRIBUTES) === [];
    }
}
