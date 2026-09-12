<?php

namespace Tests\Feature\Concerns;

use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectDocument;
use App\Models\ProjectStatus;
use App\Models\User;
use Illuminate\Support\Str;

trait BuildsDocumentVersions
{
    protected function versionedDocument(array $documentOverrides = [], array $versionOverrides = []): DocumentVersion
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'name' => 'Version foundation project',
            'objective' => 'Keep verified original identity.',
            'user_id' => $owner->id,
            'department_id' => Department::query()->firstOrCreate(['name' => 'Versions'])->id,
            'project_category_id' => ProjectCategory::query()->firstOrCreate(['name' => 'Versions'])->id,
            'academic_year_id' => AcademicYear::query()->firstOrCreate(['year' => 2570])->id,
            'project_status_id' => ProjectStatus::query()->firstOrCreate(['name' => 'Version draft'])->id,
        ]);
        $document = ProjectDocument::query()->create(array_replace([
            'project_id' => $project->id,
            'original_name' => 'original.txt',
            'path' => 'documents/'.Str::uuid().'.txt',
            'storage_disk' => 'local',
            'mime_type' => 'text/plain',
            'size' => 8,
            'uploaded_by' => $owner->id,
            'checksum' => hash('sha256', 'original'),
            'version' => 7,
        ], $documentOverrides));

        return DocumentVersion::query()->create(array_replace([
            'project_document_id' => $document->id,
            'revision_no' => 1,
            'created_via' => DocumentVersionCreatedVia::Backfill,
            'storage_disk' => $document->storage_disk,
            'storage_path' => $document->path,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size,
            'sha256' => $document->checksum,
            'integrity_basis' => DocumentVersionIntegrityBasis::RecordedSha256,
            'created_by' => null,
            'verified_at' => now(),
        ], $versionOverrides))->refresh();
    }
}
