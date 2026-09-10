<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\ProjectOptionsService;
use Illuminate\Http\JsonResponse;

class ProjectOptionsController extends Controller
{
    public function __invoke(ProjectOptionsService $options): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        return response()->json(['data' => $options->all()]);
    }
}
