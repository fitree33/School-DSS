<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreImportPreviewRevisionRequest;
use App\Http\Resources\Api\V2\ImportPreviewRevisionResource;
use App\Models\DocumentImport;
use App\Services\Imports\ImportPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class ImportPreviewRevisionController extends Controller
{
    public function __construct(private readonly ImportPreviewService $previews) {}

    public function index(
        Request $request,
        DocumentImport $documentImport,
    ): AnonymousResourceCollection {
        $this->authorize('view', $documentImport);

        return ImportPreviewRevisionResource::collection(
            $documentImport->previewRevisions()->with('editor:id,name')->get(),
        );
    }

    public function store(
        StoreImportPreviewRevisionRequest $request,
        DocumentImport $documentImport,
    ): JsonResponse {
        $this->authorize('review', $documentImport);
        $validated = $request->validated();
        $result = $this->previews->appendUserRevision(
            $request->user(),
            $documentImport,
            (int) $validated['base_revision_id'],
            $validated['payload'],
            $validated['idempotency_key'],
        );
        $result->revision->load('editor:id,name');

        return (new ImportPreviewRevisionResource($result->revision))
            ->response()
            ->setStatusCode($result->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
