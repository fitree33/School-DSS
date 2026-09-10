<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\DocumentImport;
use App\Services\Projects\ProjectOptionsService;
use Illuminate\Http\JsonResponse;

final class DocumentImportOptionsController extends Controller
{
    public function __invoke(ProjectOptionsService $options): JsonResponse
    {
        $this->authorize('viewAny', DocumentImport::class);

        return response()->json([
            'data' => [
                'project_options' => $options->all(),
                'constraints' => [
                    'max_bytes' => (int) config('project_imports.upload.max_bytes'),
                    'accepted_mime_types' => array_values(
                        (array) config(
                            'project_imports.upload.accepted_mime_types',
                            ['application/pdf'],
                        ),
                    ),
                ],
            ],
        ]);
    }
}
