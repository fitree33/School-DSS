<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/** Permanent evidence of one successful signing; never a placement draft. */
class DocumentSignature extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'public_id', 'project_id', 'project_document_id', 'source_document_version_id',
        'signed_document_version_id', 'project_signature_slot_id', 'assignment_revision',
        'signer_id', 'signature_asset_id', 'page', 'x', 'y', 'width', 'height',
        'before_sha256', 'after_sha256', 'signed_at', 'idempotency_key', 'placement_fingerprint',
    ];

    protected $hidden = ['idempotency_key', 'placement_fingerprint'];

    protected $casts = [
        'project_id' => 'integer', 'project_document_id' => 'integer',
        'source_document_version_id' => 'integer', 'signed_document_version_id' => 'integer',
        'project_signature_slot_id' => 'integer', 'assignment_revision' => 'integer',
        'signer_id' => 'integer', 'signature_asset_id' => 'integer', 'page' => 'integer',
        'x' => 'decimal:8', 'y' => 'decimal:8', 'width' => 'decimal:8', 'height' => 'decimal:8',
        'signed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $signature): void {
            $signature->public_id ??= (string) Str::uuid();
        });
        static::updating(function (): never {
            throw new LogicException('Document signature evidence is immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Document signature evidence cannot be deleted.');
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

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'source_document_version_id');
    }

    public function signedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'signed_document_version_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_id')->withTrashed();
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ProjectSignatureSlot::class, 'project_signature_slot_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(SignatureAsset::class, 'signature_asset_id');
    }
}
