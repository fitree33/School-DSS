<?php

namespace App\Http\Resources\Api\V2;

use App\Enums\AiExtractionRunStatus;
use App\Models\AiExtractionRun;
use App\Models\ImportPreviewRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DocumentImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $latestRun = $this->latestRun();
        $currentPreview = $this->currentPreview();
        $successfulRun = $this->successfulRunFor($currentPreview);

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'status' => $this->status->value,
            'processing_stage' => $this->processing_stage?->value,
            'original' => [
                'name' => $this->original_name,
                'mime_type' => $this->mime_type,
                'size_bytes' => $this->size_bytes,
                'sha256' => $this->sha256,
                'download_url' => $user?->can('viewOriginal', $this->resource)
                    ? route('api.v2.imports.original', $this->resource, false)
                    : null,
            ],
            'uploader' => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ] : null),
            'uploader_department' => $this->whenLoaded('uploaderDepartment', fn () => $this->uploaderDepartment ? [
                'id' => $this->uploaderDepartment->id,
                'name' => $this->uploaderDepartment->name,
            ] : null),
            'latest_run' => $latestRun ? [
                'id' => $latestRun->id,
                'public_id' => $latestRun->public_id,
                'attempt_no' => $latestRun->attempt_no,
                'status' => $latestRun->status->value,
                'provider' => $latestRun->provider,
                'model' => $latestRun->model_name,
                'started_at' => $latestRun->started_at?->toISOString(),
                'finished_at' => $latestRun->finished_at?->toISOString(),
            ] : null,
            'original_extraction' => $successfulRun ? [
                'run_id' => $successfulRun->public_id,
                'values' => $successfulRun->normalized_result ?? [],
                'confidence' => $successfulRun->confidence ?? [],
                'warnings' => $successfulRun->warnings ?? [],
                'created_at' => $successfulRun->finished_at?->toISOString(),
            ] : null,
            'current_preview' => $currentPreview
                ? new ImportPreviewRevisionResource($currentPreview)
                : null,
            'confirmed_project' => $this->whenLoaded('confirmedProject', fn () => $this->confirmedProject ? [
                'id' => $this->confirmedProject->id,
                'name' => $this->confirmedProject->name,
                'project_code' => $this->confirmedProject->project_code,
                'url' => "/projects/{$this->confirmedProject->id}",
            ] : null),
            'confirmation' => $this->status->value === 'confirmed' ? [
                'preview_revision_no' => $this->confirmed_preview_revision,
                'confirmed_by' => $this->whenLoaded('confirmer', fn () => $this->confirmer ? [
                    'id' => $this->confirmer->id,
                    'name' => $this->confirmer->name,
                ] : null),
                'confirmed_at' => $this->confirmed_at?->toISOString(),
            ] : null,
            'failure' => $this->failure_code ? [
                'stage' => $this->failure_stage,
                'code' => $this->failure_code,
                'message' => $this->failure_message ?: 'Import processing failed.',
            ] : null,
            'abilities' => [
                'view_original' => $user?->can('viewOriginal', $this->resource) ?? false,
                'review' => $user?->can('review', $this->resource) ?? false,
                'retry' => $user?->can('retry', $this->resource) ?? false,
                'confirm' => $user?->can('confirm', $this->resource) ?? false,
            ],
            'extracted_at' => $this->extracted_at?->toISOString(),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function latestRun(): ?AiExtractionRun
    {
        if (! $this->resource->relationLoaded('extractionRuns')) {
            return null;
        }

        return $this->extractionRuns->sortByDesc('attempt_no')->first();
    }

    private function currentPreview(): ?ImportPreviewRevision
    {
        if (! $this->resource->relationLoaded('previewRevisions')) {
            return null;
        }

        return $this->previewRevisions->firstWhere('revision_no', $this->current_preview_revision);
    }

    private function successfulRunFor(?ImportPreviewRevision $revision): ?AiExtractionRun
    {
        if ($revision === null || ! $this->resource->relationLoaded('extractionRuns')) {
            return null;
        }

        return $this->extractionRuns->first(
            fn (AiExtractionRun $run): bool => $run->id === $revision->source_extraction_run_id
                && $run->status === AiExtractionRunStatus::Succeeded,
        );
    }
}
