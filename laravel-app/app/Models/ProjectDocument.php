<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ProjectDocument extends Model
{
    use HasFactory;

    private const IMMUTABLE_VERSIONED_ATTRIBUTES = [
        'id',
        'project_id',
        'source_import_id',
        'original_name',
        'path',
        'storage_disk',
        'mime_type',
        'size',
        'uploaded_by',
        'checksum',
    ];

    private const IMMUTABLE_IMPORTED_ATTRIBUTES = [
        'project_id',
        'source_import_id',
        'original_name',
        'path',
        'storage_disk',
        'mime_type',
        'size',
        'uploaded_by',
        'checksum',
        'version',
    ];

    protected $fillable = [
        'project_id',
        'source_import_id',
        'original_name',
        'path',
        'storage_disk',
        'mime_type',
        'size',
        'uploaded_by',
        'checksum',
        'version',
        'processing_status',
        'processed_at',
        'processing_error',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            if ($document->isDirty(self::IMMUTABLE_VERSIONED_ATTRIBUTES)
                && $document->hasRegisteredVersions()) {
                throw new LogicException('A versioned document source identity cannot be changed.');
            }

            if ($document->isDirty('source_import_id')) {
                throw new LogicException('Imported document provenance cannot be changed.');
            }

            if ($document->getRawOriginal('source_import_id') !== null
                && $document->isDirty(self::IMMUTABLE_IMPORTED_ATTRIBUTES)) {
                throw new LogicException('An imported original document cannot be replaced.');
            }
        });

        static::deleting(function (self $document): void {
            if ($document->hasRegisteredVersions()) {
                throw new LogicException('A versioned document cannot be deleted.');
            }

            if ($document->getRawOriginal('source_import_id') !== null) {
                throw new LogicException('An imported original document cannot be deleted.');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function sourceImport(): BelongsTo
    {
        return $this->belongsTo(DocumentImport::class, 'source_import_id');
    }

    public function content(): HasOne
    {
        return $this->hasOne(DocumentContent::class, 'document_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('revision_no');
    }

    public function initialVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->where('revision_no', 1);
    }

    public function isImportedOriginal(): bool
    {
        return $this->source_import_id !== null;
    }

    private function hasRegisteredVersions(): bool
    {
        return DocumentVersion::query()
            ->where('project_document_id', $this->getRawOriginal('id'))
            ->exists();
    }
}
