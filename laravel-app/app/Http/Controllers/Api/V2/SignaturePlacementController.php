<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SaveSignaturePlacementRequest;
use App\Http\Resources\Api\V2\ProjectSignatureSlotResource;
use App\Http\Resources\Api\V2\SignatureAssetResource;
use App\Http\Resources\Api\V2\SignaturePlacementResource;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectSignatureSlot;
use App\Models\SignaturePlacement;
use App\Services\Signatures\SignaturePlacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SignaturePlacementController extends Controller
{
    public function __construct(private readonly SignaturePlacementService $placements) {}

    public function index(Request $request, Project $project, ProjectDocument $projectDocument, DocumentVersion $documentVersion): JsonResponse
    {
        $context = $this->placements->context($request->user(), $project, $projectDocument, $documentVersion);
        $slots = $context['slots']->map(function (ProjectSignatureSlot $slot) use ($request, $project, $projectDocument, $documentVersion, $context): array {
            $placement = $context['placements']->get($slot->id);

            return [
                ...(new ProjectSignatureSlotResource($slot))->resolve($request),
                'can_sign' => $request->user()->can('place', [SignaturePlacement::class, $project, $projectDocument, $documentVersion, $slot]),
                'placement' => $placement === null ? null : (new SignaturePlacementResource($placement))->resolve($request),
            ];
        });

        return response()->json(['data' => [
            'document' => ['id' => (int) $projectDocument->id, 'original_name' => $documentVersion->original_name],
            'version' => [
                'public_id' => $documentVersion->public_id,
                'revision_no' => $documentVersion->revision_no,
                'download_url' => route('api.v2.projects.documents.versions.download', [
                    'project' => $project->id,
                    'projectDocument' => $projectDocument->id,
                    'documentVersion' => $documentVersion->public_id,
                ], false),
                'page_count' => $context['page_count'],
            ],
            'slots' => $slots,
            'assets' => SignatureAssetResource::collection($context['assets'])->resolve($request),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function update(
        SaveSignaturePlacementRequest $request,
        Project $project,
        ProjectDocument $projectDocument,
        DocumentVersion $documentVersion,
        ProjectSignatureSlot $projectSignatureSlot,
    ): JsonResponse {
        $placement = $this->placements->save($request->user(), $project, $projectDocument, $documentVersion, $projectSignatureSlot, $request->validated());

        return (new SignaturePlacementResource($placement))->response()->header('Cache-Control', 'private, no-store');
    }

    public function destroy(
        Request $request,
        Project $project,
        ProjectDocument $projectDocument,
        DocumentVersion $documentVersion,
        ProjectSignatureSlot $projectSignatureSlot,
    ): Response {
        $this->placements->reset($request->user(), $project, $projectDocument, $documentVersion, $projectSignatureSlot);

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }
}
