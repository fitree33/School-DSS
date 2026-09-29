<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mutable placement draft only; this record is not a signing event. */
class SignaturePlacement extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'document_version_id', 'project_signature_slot_id', 'signature_asset_id',
        'assignment_revision', 'page', 'x', 'y', 'width', 'height', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'id' => 'integer', 'document_version_id' => 'integer', 'project_signature_slot_id' => 'integer',
        'signature_asset_id' => 'integer', 'assignment_revision' => 'integer', 'page' => 'integer',
        'x' => 'float', 'y' => 'float', 'width' => 'float', 'height' => 'float',
        'created_by' => 'integer', 'updated_by' => 'integer',
        'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
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
