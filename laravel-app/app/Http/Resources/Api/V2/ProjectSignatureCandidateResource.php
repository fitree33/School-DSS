<?php

namespace App\Http\Resources\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectSignatureCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'role' => $this->role ? [
                'code' => $this->role->code,
                'name' => $this->role->name,
            ] : null,
            'department' => $this->department ? [
                'id' => (int) $this->department->id,
                'name' => $this->department->name,
            ] : null,
        ];
    }
}
