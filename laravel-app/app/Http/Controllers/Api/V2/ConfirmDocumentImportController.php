<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ConfirmDocumentImportRequest;
use App\Http\Resources\Api\V2\DocumentImportResource;
use App\Models\DocumentImport;
use App\Services\Imports\ImportConfirmationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ConfirmDocumentImportController extends Controller
{
    public function __construct(private readonly ImportConfirmationService $confirmations) {}

    public function __invoke(
        ConfirmDocumentImportRequest $request,
        DocumentImport $documentImport,
    ): JsonResponse {
        $this->authorize('confirm', $documentImport);
        $validated = $request->validated();
        $result = $this->confirmations->confirm(
            $request->user(),
            $documentImport,
            (int) $validated['preview_revision_id'],
            $validated['idempotency_key'],
        );
        $result->documentImport->load([
            'uploader:id,name',
            'uploaderDepartment:id,name',
            'confirmedProject:id,name,project_code',
            'confirmer:id,name',
            'extractionRuns',
            'previewRevisions.editor:id,name',
        ]);
        $project = [
            'id' => $result->project->id,
            'name' => $result->project->name,
            'project_code' => $result->project->project_code,
            'url' => "/projects/{$result->project->id}",
        ];

        return response()->json([
            'data' => [
                'document_import' => (new DocumentImportResource($result->documentImport))->resolve($request),
                'project' => $project,
            ],
        ], $result->created ? Response::HTTP_CREATED : Response::HTTP_OK, [
            'Location' => route('api.v2.projects.show', $result->project),
        ]);
    }
}
