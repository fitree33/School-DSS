<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentSignatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'source_version_id' => $this->sourceVersion->public_id,
            'signed_version' => [
                'public_id' => $this->signedVersion->public_id,
                'revision_no' => $this->signedVersion->revision_no,
                'download_url' => route('api.v2.projects.documents.versions.download', [
                    'project' => $this->project_id, 'projectDocument' => $this->project_document_id,
                    'documentVersion' => $this->signedVersion->public_id,
                ], false),
            ],
            'signature_slot_id' => $this->project_signature_slot_id,
            'signed_at' => $this->signed_at->toISOString(),
            'before_sha256' => $this->before_sha256,
            'after_sha256' => $this->after_sha256,
        ];
    }
}
