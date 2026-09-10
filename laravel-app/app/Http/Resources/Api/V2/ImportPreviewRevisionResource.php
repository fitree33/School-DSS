<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ImportPreviewRevisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'revision_no' => $this->revision_no,
            'source' => $this->source,
            'payload' => $this->payload,
            'validation_errors' => $this->validation_errors ?? [],
            'warnings' => $this->warnings ?? [],
            'confidence' => $this->field_confidence ?? [],
            'edited_by' => $this->whenLoaded('editor', fn () => $this->editor ? [
                'id' => $this->editor->id,
                'name' => $this->editor->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
