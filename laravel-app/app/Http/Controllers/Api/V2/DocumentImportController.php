<?php

namespace App\Http\Controllers\Api\V2;

use App\Exceptions\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\DocumentImportIndexRequest;
use App\Http\Requests\Api\V2\StoreDocumentImportRequest;
use App\Http\Resources\Api\V2\DocumentImportResource;
use App\Models\DocumentImport;
use App\Services\Imports\DocumentImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentImportController extends Controller
{
    public function __construct(private readonly DocumentImportService $imports) {}

    public function index(DocumentImportIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = DocumentImport::query()
            ->visibleTo($request->user())
            ->with($this->relations())
            ->when(
                $filters['status'] ?? null,
                fn ($builder, string $status) => $builder->where('status', $status),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return DocumentImportResource::collection(
            $query->paginate($filters['per_page'] ?? 15)->withQueryString(),
        );
    }

    public function store(StoreDocumentImportRequest $request): JsonResponse
    {
        $documentImport = $this->imports->upload(
            $request->user(),
            $request->file('document'),
        );
        $documentImport->load($this->relations());

        return (new DocumentImportResource($documentImport))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', route('api.v2.imports.show', $documentImport));
    }

    public function show(DocumentImport $documentImport): DocumentImportResource
    {
        $this->authorize('view', $documentImport);

        return new DocumentImportResource($documentImport->load($this->relations()));
    }

    public function original(
        Request $request,
        DocumentImport $documentImport,
    ): StreamedResponse {
        $this->authorize('viewOriginal', $documentImport);

        $disk = Storage::disk($documentImport->storage_disk);

        if (! $disk->exists($documentImport->storage_path)) {
            throw new ApiProblemException(
                'The original PDF is unavailable.',
                'original_document_unavailable',
                Response::HTTP_CONFLICT,
            );
        }

        return $disk->download(
            $documentImport->storage_path,
            $documentImport->original_name,
            [
                'Content-Type' => 'application/pdf',
                'Content-Security-Policy' => "sandbox; default-src 'none'",
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    public function retry(Request $request, DocumentImport $documentImport): DocumentImportResource
    {
        $this->authorize('retry', $documentImport);
        $documentImport = $this->imports->retry($request->user(), $documentImport);

        return new DocumentImportResource($documentImport->load($this->relations()));
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'uploader:id,name',
            'uploaderDepartment:id,name',
            'confirmedProject:id,name,project_code',
            'confirmer:id,name',
            'extractionRuns',
            'previewRevisions.editor:id,name',
        ];
    }
}
