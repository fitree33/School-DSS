<?php

namespace Tests\Feature\Api\V2\Concerns;

use App\Enums\AiExtractionRunStatus;
use App\Enums\DocumentImportStatus;
use App\Models\AcademicYear;
use App\Models\AiExtractionRun;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\DocumentImport;
use App\Models\FiscalYear;
use App\Models\ImportPreviewRevision;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectDocument;
use App\Models\Role;
use App\Models\SchoolPlan;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\ProjectStatusSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait BuildsPhaseFiveImports
{
    protected Department $importDepartment;

    protected Department $otherImportDepartment;

    protected ProjectCategory $importCategory;

    protected AcademicYear $importAcademicYear;

    protected FiscalYear $importFiscalYear;

    protected SchoolPlan $importSchoolPlan;

    protected function setUpPhaseFiveImports(): void
    {
        $this->seed(ProjectStatusSeeder::class);
        $this->seed(AuthorizationSeeder::class);

        $this->importDepartment = Department::query()->create(['name' => 'Phase 5 Academic Affairs']);
        $this->otherImportDepartment = Department::query()->create(['name' => 'Phase 5 Student Affairs']);
        $this->importCategory = ProjectCategory::query()->create(['name' => 'Phase 5 Development']);
        $this->importAcademicYear = AcademicYear::query()->create([
            'year' => 2570,
            'is_active' => true,
        ]);
        $this->importFiscalYear = FiscalYear::query()->create([
            'year' => 2570,
            'start_date' => '2026-10-01',
            'end_date' => '2027-09-30',
            'is_active' => true,
            'is_locked' => false,
        ]);
        $this->importSchoolPlan = SchoolPlan::query()->create([
            'fiscal_year_id' => $this->importFiscalYear->id,
            'code' => 'P5-PLAN-01',
            'name' => 'Phase 5 School Plan',
            'is_active' => true,
        ]);
    }

    protected function phaseFiveUser(
        string $roleCode = 'teacher',
        ?Department $department = null,
        array $overrides = [],
    ): User {
        return User::factory()->create(array_merge([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'department_id' => ($department ?? $this->importDepartment)->id,
            'is_active' => true,
        ], $overrides));
    }

    protected function createDocumentImport(
        User $uploader,
        DocumentImportStatus $status = DocumentImportStatus::Uploaded,
        array $overrides = [],
        string $contents = "%PDF-1.4\nPhase five source document\n%%EOF\n",
    ): DocumentImport {
        $disk = $overrides['storage_disk'] ?? 'project-imports';
        $path = $overrides['storage_path'] ?? 'originals/tests/'.Str::uuid().'.pdf';

        Storage::disk($disk)->put($path, $contents);

        return DocumentImport::query()->create(array_merge([
            'uploaded_by' => $uploader->id,
            'uploader_department_id' => $uploader->department_id,
            'status' => $status,
            'processing_stage' => null,
            'original_name' => 'phase-five-project.pdf',
            'storage_disk' => $disk,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ], $overrides));
    }

    protected function createExtractionRun(
        DocumentImport $documentImport,
        int $attemptNo = 1,
        AiExtractionRunStatus $status = AiExtractionRunStatus::Processing,
        array $overrides = [],
    ): AiExtractionRun {
        return AiExtractionRun::query()->create(array_merge([
            'document_import_id' => $documentImport->id,
            'attempt_no' => $attemptNo,
            'provider' => 'n8n',
            'schema_version' => 'project-import.v1',
            'status' => $status,
            'started_at' => now(),
        ], $overrides));
    }

    protected function createPreviewRevision(
        DocumentImport $documentImport,
        AiExtractionRun $run,
        int $revisionNo = 1,
        array $overrides = [],
    ): ImportPreviewRevision {
        return ImportPreviewRevision::query()->create(array_merge([
            'document_import_id' => $documentImport->id,
            'revision_no' => $revisionNo,
            'parent_revision_no' => $revisionNo > 1 ? $revisionNo - 1 : null,
            'source_extraction_run_id' => $run->id,
            'source' => ImportPreviewRevision::SOURCE_AI,
            'payload' => $this->validImportPreviewPayload(),
            'validation_errors' => [],
            'warnings' => [],
            'field_confidence' => ['name' => 0.95],
        ], $overrides));
    }

    /**
     * @return array{DocumentImport, AiExtractionRun, ImportPreviewRevision}
     */
    protected function createReviewableImport(
        User $uploader,
        int $revisionNo = 1,
        array $importOverrides = [],
        array $revisionOverrides = [],
    ): array {
        $documentImport = $this->createDocumentImport(
            $uploader,
            DocumentImportStatus::NeedsReview,
            array_merge([
                'active_extraction_attempt' => 1,
                'current_preview_revision' => $revisionNo,
                'page_count' => 1,
                'extracted_at' => now(),
            ], $importOverrides),
        );
        $run = $this->createExtractionRun(
            $documentImport,
            1,
            AiExtractionRunStatus::Succeeded,
            [
                'extracted_text' => 'Verified text extracted directly from the PDF text layer.',
                'extracted_text_sha256' => hash(
                    'sha256',
                    'Verified text extracted directly from the PDF text layer.',
                ),
                'normalized_result' => $this->validImportPreviewPayload(),
                'finished_at' => now(),
            ],
        );
        $revision = $this->createPreviewRevision(
            $documentImport,
            $run,
            $revisionNo,
            $revisionOverrides,
        );

        return [$documentImport, $run, $revision];
    }

    /** @return array<string, mixed> */
    protected function validImportPreviewPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Phase 5 imported project',
            'objective' => 'Verify that canonical project data is created only by confirmation.',
            'key_points' => 'Review all extracted values before confirming.',
            'budget' => '1500.00',
            'responsible_person' => 'Phase 5 owner',
            'monitor_person' => 'Phase 5 monitor',
            'evaluation_method' => 'Review evidence',
            'evaluation_tools' => 'Project checklist',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'department_id' => $this->importDepartment->id,
            'project_category_id' => $this->importCategory->id,
            'academic_year_id' => $this->importAcademicYear->id,
            'fiscal_year_id' => $this->importFiscalYear->id,
            'school_plan_id' => $this->importSchoolPlan->id,
            'indicators' => [
                [
                    'name' => 'Completion',
                    'target_value' => '100.00',
                    'unit' => 'percent',
                ],
                [
                    'name' => 'Participants',
                    'target_value' => '25',
                    'unit' => 'people',
                ],
            ],
        ], $overrides);
    }

    protected function assertNoCanonicalImportWrites(): void
    {
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_kpis', 0);
        $this->assertDatabaseCount('project_documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertDatabaseCount('document_contents', 0);
        $this->assertDatabaseCount('project_access', 0);
        $this->assertDatabaseCount('project_status_histories', 0);
        $this->assertDatabaseCount('project_execution_status_histories', 0);
        $this->assertSame(0, AuditLog::query()
            ->whereIn('auditable_type', [Project::class, ProjectDocument::class])
            ->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'document_import.confirmed']);
    }
}
