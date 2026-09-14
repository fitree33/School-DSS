<?php

namespace App\Http\Controllers\Api\V2;

use App\Enums\ProjectSignatureSlotCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\UpdateProjectSignatureAssignmentRequest;
use App\Http\Resources\Api\V2\ProjectSignatureSlotResource;
use App\Models\Project;
use App\Services\Projects\ProjectSignatureSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectSignatureSlotController extends Controller
{
    public function __construct(private readonly ProjectSignatureSlotService $slots) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless($request->user()->can('viewSignatures', $project), 404);

        return ProjectSignatureSlotResource::collection($this->slots->slotsFor($project))
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    public function updateAssignment(
        UpdateProjectSignatureAssignmentRequest $request,
        Project $project,
        ProjectSignatureSlotCode $slotCode,
    ): JsonResponse {
        $attributes = $request->validated();
        $slot = $this->slots->updateAssignment(
            $request->user(),
            $project,
            $slotCode,
            $attributes['assigned_user_id'],
            $attributes['assignment_revision'],
        );

        return (new ProjectSignatureSlotResource($slot))
            ->response()
            ->header('Cache-Control', 'no-store');
    }
}
