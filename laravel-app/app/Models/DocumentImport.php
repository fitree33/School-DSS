<?php

namespace App\Models;

use App\Enums\DocumentImportProcessingStage;
use App\Enums\DocumentImportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use LogicException;

class DocumentImport extends Model
{
    use HasFactory;

    private const IMMUTABLE_SOURCE_ATTRIBUTES = [
        'public_id',
        'uploaded_by',
        'uploader_department_id',
        'original_name',
        'storage_disk',
        'storage_path',
        'mime_type',
        'size_bytes',
        'sha256',
    ];

    protected $fillable = [
        'public_id',
        'uploaded_by',
        'uploader_department_id',
        'status',
        'processing_stage',
        'original_name',
        'storage_disk',
        'storage_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'page_count',
        'language',
        'active_extraction_attempt',
        'current_preview_revision',
        'confirmed_preview_revision',
        'confirmation_idempotency_key_hash',
        'confirmed_project_id',
        'confirmed_by',
        'extracted_at',
        'confirmed_at',
        'failure_stage',
        'failure_code',
        'failure_message',
    ];

    protected $casts = [
        'status' => DocumentImportStatus::class,
        'processing_stage' => DocumentImportProcessingStage::class,
        'size_bytes' => 'integer',
        'page_count' => 'integer',
        'active_extraction_attempt' => 'integer',
        'current_preview_revision' => 'integer',
        'confirmed_preview_revision' => 'integer',
        'extracted_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $documentImport): void {
            $documentImport->public_id ??= (string) Str::uuid();
        });

        static::updating(function (self $documentImport): void {
            $originalStatus = DocumentImportStatus::tryFrom(
                (string) $documentImport->getRawOriginal('status'),
            );

            if ($originalStatus === DocumentImportStatus::Confirmed && $documentImport->isDirty()) {
                throw new LogicException('A confirmed document import is immutable provenance.');
            }

            if ($documentImport->isDirty('status')) {
                $nextStatus = $documentImport->status instanceof DocumentImportStatus
                    ? $documentImport->status
                    : DocumentImportStatus::tryFrom((string) $documentImport->status);

                if ($nextStatus === null || ! $originalStatus?->canTransitionTo($nextStatus)) {
                    throw new LogicException('The document import status transition is invalid.');
                }
            }

            if ($documentImport->isDirty(self::IMMUTABLE_SOURCE_ATTRIBUTES)) {
                throw new LogicException('The imported original blob metadata is immutable.');
            }
        });

        static::deleting(function (self $documentImport): void {
            if ($documentImport->getRawOriginal('confirmed_project_id') !== null
                || $documentImport->projectDocument()->exists()) {
                throw new LogicException('A confirmed document import is immutable provenance and cannot be deleted.');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function uploaderDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'uploader_department_id');
    }

    public function confirmedProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'confirmed_project_id')->withTrashed();
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by')->withTrashed();
    }

    public function extractionRuns(): HasMany
    {
        return $this->hasMany(AiExtractionRun::class)->orderBy('attempt_no');
    }

    public function previewRevisions(): HasMany
    {
        return $this->hasMany(ImportPreviewRevision::class)->orderBy('revision_no');
    }

    public function projectDocument(): HasOne
    {
        return $this->hasOne(ProjectDocument::class, 'source_import_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('imports.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where('uploaded_by', $user->id);

            if ($user->department_id && $user->hasPermission('imports.view_department')) {
                $visible->orWhere('uploader_department_id', $user->department_id);
            }
        });
    }
}
