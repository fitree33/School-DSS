<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ImportPreviewRevision extends Model
{
    use HasFactory;

    public const SOURCE_AI = 'ai';

    public const SOURCE_USER = 'user';

    protected $fillable = [
        'document_import_id',
        'revision_no',
        'parent_revision_no',
        'source_extraction_run_id',
        'source',
        'payload',
        'validation_errors',
        'warnings',
        'field_confidence',
        'edited_by',
        'client_idempotency_key',
    ];

    protected $casts = [
        'revision_no' => 'integer',
        'parent_revision_no' => 'integer',
        'payload' => 'array',
        'validation_errors' => 'array',
        'warnings' => 'array',
        'field_confidence' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Import preview revisions are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Import preview revisions cannot be deleted.');
        });
    }

    public function documentImport(): BelongsTo
    {
        return $this->belongsTo(DocumentImport::class);
    }

    public function sourceExtractionRun(): BelongsTo
    {
        return $this->belongsTo(AiExtractionRun::class, 'source_extraction_run_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
