<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SignatureAsset extends Model
{
    public const NORMALIZATION_VERSION = 'gd-png-v1';

    public const MAX_RETIREMENT_REASON_LENGTH = 500;

    public const IMMUTABLE_ATTRIBUTES = [
        'id', 'public_id', 'owner_id', 'storage_key', 'sha256', 'size_bytes',
        'width', 'height', 'mime_type', 'normalization_version', 'created_at',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'public_id', 'owner_id', 'storage_key', 'sha256', 'size_bytes', 'width', 'height',
        'mime_type', 'normalization_version', 'status', 'retired_at', 'retired_by',
        'retirement_reason', 'created_at', 'updated_at',
    ];

    protected $hidden = ['id', 'owner_id', 'storage_key', 'sha256', 'retired_by', 'retirement_reason', 'updated_at'];

    protected $attributes = [
        'status' => 'active',
        'retired_at' => null,
        'retired_by' => null,
        'retirement_reason' => null,
    ];

    protected $casts = [
        'id' => 'integer',
        'owner_id' => 'integer',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'retired_by' => 'integer',
        'retired_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $asset): void {
            if ($asset->status !== 'active' || $asset->retired_at !== null
                || $asset->retired_by !== null || $asset->retirement_reason !== null) {
                throw new LogicException('Signature assets must be created active.');
            }
        });

        static::updating(function (self $asset): void {
            if ($asset->isDirty(self::IMMUTABLE_ATTRIBUTES)) {
                throw new LogicException('Signature asset identity is immutable.');
            }

            if (! $asset->isDirty(['status', 'retired_at', 'retired_by', 'retirement_reason', 'updated_at'])) {
                return;
            }

            if ($asset->getRawOriginal('status') !== 'active' || $asset->status !== 'retired'
                || $asset->retired_at === null || $asset->retired_by !== $asset->owner_id
                || ($asset->retirement_reason !== null && mb_strlen($asset->retirement_reason) > self::MAX_RETIREMENT_REASON_LENGTH)) {
                throw new LogicException('Signature assets permit only one retirement transition.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Signature assets cannot be deleted.');
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by')->withTrashed();
    }

    /** Future signing must also authorize the owner and recheck under its transaction lock. */
    public function isEligibleForSigning(): bool
    {
        return $this->exists && $this->status === 'active';
    }
}
