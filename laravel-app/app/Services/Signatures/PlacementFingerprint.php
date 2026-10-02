<?php

namespace App\Services\Signatures;

use App\Models\SignaturePlacement;

final class PlacementFingerprint
{
    /** A conflict token, not a credential. Includes the saved row identity and exact stored rectangle. */
    public static function for(SignaturePlacement $placement): string
    {
        $snapshot = [];
        foreach (['id', 'document_version_id', 'project_signature_slot_id', 'signature_asset_id', 'assignment_revision', 'updated_by', 'page'] as $field) {
            $snapshot[$field] = (string) $placement->{$field};
        }
        foreach (['x', 'y', 'width', 'height'] as $field) {
            $snapshot[$field] = (string) (int) round($placement->{$field} * 100000000);
        }
        $snapshot['updated_at'] = $placement->updated_at?->format('Y-m-d H:i:s.u');

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
