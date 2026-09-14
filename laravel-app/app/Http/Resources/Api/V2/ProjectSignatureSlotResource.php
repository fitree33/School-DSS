<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Projects\ProjectSignatureCandidateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectSignatureSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignee = $this->assignee;
        $status = app(ProjectSignatureCandidateService::class)->status(
            $assignee,
            $this->slot_code,
            $this->assigned_user_id,
        );

        return [
            'id' => (int) $this->id,
            'project_id' => (int) $this->project_id,
            'slot_code' => $this->slot_code->value,
            'slot_no' => (int) $this->slot_no,
            'assigned_user_id' => $this->assigned_user_id,
            'assignment_revision' => (int) $this->assignment_revision,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'assignee_status' => $status,
            'assignee' => in_array($status, ['unassigned', 'soft_deleted', 'missing'], true)
                ? null : new ProjectSignatureCandidateResource($assignee),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
