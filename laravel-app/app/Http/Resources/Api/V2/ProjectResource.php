<?php

namespace App\Http\Resources\Api\V2;

use App\Models\ProjectDocument;
use App\Models\ProjectEvaluation;
use App\Models\ProjectKpi;
use App\Services\Budgets\BudgetMetricsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $latestResult = $this->resource->relationLoaded('latestEvaluationResult')
            ? $this->latestEvaluationResult
            : null;
        $latestEvaluation = $latestResult?->relationLoaded('evaluation')
            ? $latestResult->evaluation
            : null;
        $canViewEvaluations = $user?->can('viewAny', ProjectEvaluation::class) === true
            && ($user?->can('view', $this->resource) ?? false);
        $canViewLatestEvaluation = false;

        if ($canViewEvaluations && $latestEvaluation !== null) {
            $latestEvaluation->setRelation('project', $this->resource);
            $canViewLatestEvaluation = $user?->can('view', $latestEvaluation) ?? false;
            $latestEvaluation->unsetRelation('project');
            $latestEvaluation->setRelation('result', $latestResult);
        }

        return [
            'id' => $this->id,
            'project_code' => $this->project_code,
            'name' => $this->name,
            'objective' => $this->objective,
            'description' => $this->description,
            'rationale' => $this->rationale,
            'target_group' => $this->target_group,
            'strategy' => $this->strategy,
            'key_points' => $this->key_points,
            'budget' => $this->budget,
            'actual_spent' => $this->actual_spent,
            'budget_metrics' => app(BudgetMetricsService::class)->project(
                $this->budget,
                $this->actual_spent,
            ),
            'budget_source' => $this->budget_source,
            'responsible_person' => $this->responsible_person,
            'monitor_person' => $this->monitor_person,
            'evaluation_method' => $this->evaluation_method,
            'evaluation_tools' => $this->evaluation_tools,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'kpis' => $this->whenLoaded('kpis', fn () => $this->kpis->map(fn (ProjectKpi $kpi): array => [
                'id' => $kpi->id,
                'name' => $kpi->name,
                'target_value' => $kpi->target_value,
                'actual_value' => $kpi->actual_value,
                'unit' => $kpi->unit,
            ])),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(function (ProjectDocument $document) use ($user): array {
                $sourceImport = $document->relationLoaded('sourceImport') ? $document->sourceImport : null;
                $initialVersion = $document->relationLoaded('initialVersion') ? $document->initialVersion : null;
                $canDownloadVersion = $initialVersion !== null
                    && $user?->can('download', [$initialVersion, $document, $this->resource]);

                return [
                    'id' => $document->id,
                    'original_name' => $document->original_name,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => $document->size !== null ? (int) $document->size : null,
                    'source_import_id' => $document->source_import_id !== null ? (int) $document->source_import_id : null,
                    'download_url' => $sourceImport !== null && $user?->can('viewOriginal', $sourceImport)
                        ? route('api.v2.imports.original', $sourceImport, false)
                        : null,
                    'initial_version' => $canDownloadVersion ? [
                        'public_id' => $initialVersion->public_id,
                        'revision_no' => (int) $initialVersion->revision_no,
                        'download_url' => route('api.v2.projects.documents.versions.download', [
                            'project' => $this->id,
                            'projectDocument' => $document->id,
                            'documentVersion' => $initialVersion->public_id,
                        ], false),
                    ] : null,
                ];
            })),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ] : null),
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'academic_year' => $this->whenLoaded('academicYear', fn () => $this->academicYear ? [
                'id' => $this->academicYear->id,
                'year' => $this->academicYear->year,
            ] : null),
            'fiscal_year' => $this->whenLoaded('fiscalYear', fn () => $this->fiscalYear ? [
                'id' => $this->fiscalYear->id,
                'year' => $this->fiscalYear->year,
                'is_active' => $this->fiscalYear->is_active,
                'is_locked' => $this->fiscalYear->is_locked,
            ] : null),
            'school_plan' => $this->whenLoaded('schoolPlan', fn () => $this->schoolPlan ? [
                'id' => $this->schoolPlan->id,
                'code' => $this->schoolPlan->code,
                'name' => $this->schoolPlan->name,
            ] : null),
            'approval_status' => $this->whenLoaded('status', fn () => $this->status ? [
                'id' => $this->status->id,
                'code' => $this->status->code,
                'name' => $this->status->display_name,
                'color' => $this->status->color,
            ] : null),
            'execution_status' => $this->whenLoaded('executionStatus', fn () => $this->executionStatus ? [
                'id' => $this->executionStatus->id,
                'code' => $this->executionStatus->code,
                'name' => $this->executionStatus->name,
                'color' => $this->executionStatus->color,
                'is_terminal' => (bool) $this->executionStatus->is_terminal,
            ] : null),
            'evaluation_status' => $this->whenLoaded('evaluationStatus', fn () => $this->evaluationStatus ? [
                'id' => $this->evaluationStatus->id,
                'code' => $this->evaluationStatus->code,
                'name' => $this->evaluationStatus->name,
                'color' => $this->evaluationStatus->color,
                'is_terminal' => (bool) $this->evaluationStatus->is_terminal,
            ] : null),
            'latest_evaluation' => $this->when(
                $canViewLatestEvaluation,
                fn () => new ProjectEvaluationResource($latestEvaluation),
            ),
            'abilities' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
                'evaluate' => $user?->can('evaluate', $this->resource) ?? false,
                'view_evaluations' => $canViewEvaluations,
                'create_evaluation' => $user?->can(
                    'create',
                    [ProjectEvaluation::class, $this->resource],
                ) ?? false,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
