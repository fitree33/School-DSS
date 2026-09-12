<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Services\Documents\DocumentVersionDownloadService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentVersionDownloadController extends Controller
{
    public function __construct(private readonly DocumentVersionDownloadService $downloads) {}

    public function __invoke(
        Project $project,
        ProjectDocument $projectDocument,
        DocumentVersion $documentVersion,
    ): StreamedResponse {
        abort_unless(
            (int) $projectDocument->project_id === (int) $project->id
                && (int) $documentVersion->project_document_id === (int) $projectDocument->id,
            404,
        );

        $this->authorize('download', [$documentVersion, $projectDocument, $project]);

        return $this->downloads->download($documentVersion);
    }
}
