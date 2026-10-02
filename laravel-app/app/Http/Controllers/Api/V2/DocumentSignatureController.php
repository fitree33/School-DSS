<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SignDocumentRequest;
use App\Http\Resources\Api\V2\DocumentSignatureResource;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectSignatureSlot;
use App\Services\Signatures\DocumentSigningService;
use Illuminate\Http\JsonResponse;

final class DocumentSignatureController extends Controller
{
    public function __invoke(SignDocumentRequest $request, Project $project, ProjectDocument $projectDocument, DocumentVersion $documentVersion, ProjectSignatureSlot $projectSignatureSlot, DocumentSigningService $signing): JsonResponse
    {
        $signature = $signing->sign($request->user(), $project, $projectDocument, $documentVersion, $projectSignatureSlot, $request->validated());

        return (new DocumentSignatureResource($signature))->response()
            ->setStatusCode($signature->wasRecentlyCreated ? 201 : 200)
            ->header('Cache-Control', 'private, no-store');
    }
}
