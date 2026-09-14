<?php

namespace App\Http\Controllers\Api\V2;

use App\Enums\ProjectSignatureSlotCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SearchProjectSignatureCandidatesRequest;
use App\Http\Resources\Api\V2\ProjectSignatureCandidateResource;
use App\Models\Project;
use App\Services\Projects\ProjectSignatureCandidateService;
use App\Services\Projects\ProjectSignatureSlotService;
use Illuminate\Http\JsonResponse;

class ProjectSignatureCandidateController extends Controller
{
    public function __invoke(
        SearchProjectSignatureCandidatesRequest $request,
        Project $project,
        ProjectSignatureSlotCode $slotCode,
        ProjectSignatureCandidateService $candidates,
        ProjectSignatureSlotService $slots,
    ): JsonResponse {
        $slots->slotsFor($project);
        $filters = $request->validated();
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']);

        $results = $candidates->query($slotCode)
            ->whereRaw("users.name LIKE ? ESCAPE '!'", ["%{$escaped}%"])
            ->orderBy('name')
            ->orderBy('id')
            ->simplePaginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return ProjectSignatureCandidateResource::collection($results)
            ->response()
            ->header('Cache-Control', 'no-store');
    }
}
