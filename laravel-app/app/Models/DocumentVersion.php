<?php

namespace App\Models;

use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class DocumentVersion extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'public_id',
        'project_document_id',
        'revision_no',
        'created_via',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'integrity_basis',
        'created_by',
        'verified_at',
        'created_at',
    ];

    protected $hidden = ['storage_disk', 'storage_path'];

    protected $casts = [
        'revision_no' => 'integer',
        'size_bytes' => 'integer',
        'created_via' => DocumentVersionCreatedVia::class,
        'integrity_basis' => DocumentVersionIntegrityBasis::class,
        'verified_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $version->public_id ??= (string) Str::uuid();
        });

        static::updating(function (): never {
            throw new LogicException('Document versions are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Document versions cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ProjectDocument::class, 'project_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
