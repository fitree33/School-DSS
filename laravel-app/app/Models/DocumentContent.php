<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DocumentContent extends Model
{
    protected $fillable = [
        'document_id',
        'extracted_text',
        'language',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $content): void {
            if ($content->isImportedOriginalContent()) {
                throw new LogicException('Imported document text is immutable provenance.');
            }

            if ($content->isDirty('document_id')
                && ProjectDocument::query()->whereKey($content->document_id)->whereNotNull('source_import_id')->exists()) {
                throw new LogicException('Imported document text cannot be replaced.');
            }
        });

        static::deleting(function (self $content): void {
            if ($content->isImportedOriginalContent()) {
                throw new LogicException('Imported document text cannot be deleted.');
            }
        });
    }

    private function isImportedOriginalContent(): bool
    {
        return ProjectDocument::query()
            ->whereKey($this->getRawOriginal('document_id'))
            ->whereNotNull('source_import_id')
            ->exists();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ProjectDocument::class, 'document_id');
    }
}
