<?php

namespace Tests\Feature;

use App\Enums\DocumentImportStatus;
use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Models\AuditLog;
use App\Models\DocumentImport;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Services\Documents\DocumentBlobVerifier;
use App\Services\Documents\DocumentVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\V2\Concerns\BuildsPhaseFiveImports;
use Tests\TestCase;

class DocumentVersionBackfillTest extends TestCase
{
    use BuildsPhaseFiveImports;
    use RefreshDatabase;

    private const SOURCE_IDENTITY_FIELDS = [
        'project_id', 'source_import_id', 'original_name', 'path', 'storage_disk',
        'mime_type', 'size', 'uploaded_by', 'checksum',
    ];

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhaseFiveImports();
        Storage::fake('local');
        Storage::fake('project-imports');
        config()->set('filesystems.default', 'local');
        $this->owner = $this->phaseFiveUser();
        $this->project = Project::query()->create([
            'name' => 'Legacy version foundation',
            'objective' => 'Observe only the file that exists today.',
            'budget' => 0,
            'actual_spent' => 0,
            'user_id' => $this->owner->id,
            'department_id' => $this->importDepartment->id,
            'project_category_id' => $this->importCategory->id,
            'academic_year_id' => $this->importAcademicYear->id,
            'fiscal_year_id' => $this->importFiscalYear->id,
            'project_status_id' => ProjectStatus::query()->where('code', 'draft')->value('id'),
        ]);
    }

    public function test_default_dry_run_verifies_without_writing_any_history_or_copying_original(): void
    {
        $document = $this->legacyDocument();
        $documentBefore = $document->refresh()->only(self::SOURCE_IDENTITY_FIELDS);
        $files = Storage::disk('local')->allFiles();
        $audits = AuditLog::query()->count();

        $this->artisan('documents:backfill-versions')
            ->expectsOutput('Mode: dry-run (no writes)')
            ->expectsOutput("Document {$document->id}: would_register")
            ->assertSuccessful();

        $this->assertDatabaseCount('document_versions', 0);
        $this->assertDatabaseCount('document_contents', 0);
        $this->assertDatabaseCount('audit_logs', $audits);
        $this->assertSame($documentBefore, $document->refresh()->only(self::SOURCE_IDENTITY_FIELDS));
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    #[DataProvider('legacyFormats')]
    public function test_pdf_and_non_pdf_baselines_preserve_original_bytes_without_fake_history(
        string $name,
        string $mime,
        string $bytes,
    ): void {
        $document = $this->legacyDocument([
            'original_name' => $name,
            'mime_type' => $mime,
            'version' => 7,
            'created_at' => '2020-01-01 00:00:00',
        ], $bytes);
        $files = Storage::disk('local')->allFiles();
        $original = $document->refresh()->only(self::SOURCE_IDENTITY_FIELDS);

        $this->artisan('documents:backfill-versions', ['--apply' => true])->assertSuccessful();
        $baseline = DocumentVersion::query()->sole();

        $this->assertSame(1, $baseline->revision_no);
        $this->assertSame(DocumentVersionCreatedVia::Backfill, $baseline->created_via);
        $this->assertSame(DocumentVersionIntegrityBasis::RecordedSha256, $baseline->integrity_basis);
        $this->assertNull($baseline->created_by);
        $this->assertSame($document->path, $baseline->storage_path);
        $this->assertSame('local', $baseline->storage_disk);
        $this->assertSame($name, $baseline->original_name);
        $this->assertSame(strlen($bytes), $baseline->size_bytes);
        $this->assertSame(hash('sha256', $bytes), $baseline->sha256);
        $this->assertTrue($baseline->created_at->greaterThan($document->created_at));
        $this->assertSame($original, $document->refresh()->only(self::SOURCE_IDENTITY_FIELDS));
        $this->assertSame(7, $document->version);
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame($bytes, Storage::disk('local')->get($document->path));
        $this->assertDatabaseCount('document_contents', 0);
    }

    public static function legacyFormats(): array
    {
        return [
            'pdf' => ['legacy.pdf', 'application/pdf', "%PDF-1.4\nLegacy PDF baseline\n%%EOF\n"],
            'text' => ['legacy.txt', 'text/plain', "Legacy non-PDF text document.\n"],
            'doc' => ['legacy.doc', 'application/msword', hex2bin('d0cf11e0a1b11ae1').str_repeat("\0", 512)],
            'docx' => ['legacy.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', "PK\x03\x04".str_repeat("\0", 64)],
        ];
    }

    public function test_missing_legacy_checksum_is_observed_not_retrospectively_verified(): void
    {
        $document = $this->legacyDocument(['checksum' => null, 'size' => null]);

        $this->artisan('documents:backfill-versions', ['--apply' => true])->assertSuccessful();

        $version = DocumentVersion::query()->sole();
        $this->assertSame(DocumentVersionIntegrityBasis::ObservedSha256, $version->integrity_basis);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($document->path)), $version->sha256);
        $this->assertNull($document->refresh()->checksum);
        $this->assertNull($document->size);
    }

    public function test_reruns_preserve_baseline_identity_actor_and_timestamps_and_verify_existing_bytes(): void
    {
        $document = $this->legacyDocument();
        $service = app(DocumentVersionService::class);
        $baseline = $service->registerInitialVersion($document, DocumentVersionCreatedVia::LegacyUpload, $this->owner);
        $original = $baseline->refresh()->getRawOriginal();
        $this->travel(1)->day();

        $this->artisan('documents:backfill-versions', ['--apply' => true])
            ->expectsOutput("Document {$document->id}: already_registered")->assertSuccessful();
        $this->artisan('documents:backfill-versions')
            ->expectsOutput("Document {$document->id}: already_registered")->assertSuccessful();

        $this->assertSame($original, $baseline->refresh()->getRawOriginal());
        $this->assertDatabaseCount('document_versions', 1);
        Storage::disk('local')->put($document->path, str_repeat('x', (int) $document->size));

        $this->artisan('documents:backfill-versions', ['--apply' => true])->assertFailed();
        $this->assertSame($original, $baseline->refresh()->getRawOriginal());
        $this->assertDatabaseCount('document_versions', 1);
    }

    #[DataProvider('invalidLegacyMetadata')]
    public function test_missing_mismatched_or_ambiguous_sources_fail_without_repairing_document(array $overrides, bool $remove): void
    {
        $document = $this->legacyDocument($overrides);

        if ($remove) {
            Storage::disk('local')->delete($document->path);
        }

        $before = $document->refresh()->only(self::SOURCE_IDENTITY_FIELDS);
        $files = Storage::disk('local')->allFiles();
        $this->artisan('documents:backfill-versions', ['--apply' => true])->assertFailed();

        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame($before, $document->refresh()->only(self::SOURCE_IDENTITY_FIELDS));
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public static function invalidLegacyMetadata(): array
    {
        return [
            'missing' => [[], true],
            'malformed hash' => [['checksum' => 'not-a-hash'], false],
            'mismatched hash' => [['checksum' => str_repeat('0', 64)], false],
            'mismatched size' => [['size' => 1], false],
            'null disk' => [['storage_disk' => null], false],
            'unknown disk' => [['storage_disk' => 'does-not-exist'], false],
            'traversal' => [['path' => '../outside.pdf'], false],
            'absolute' => [['path' => 'C:/private/original.pdf'], false],
        ];
    }

    public function test_private_manifest_resolves_null_disk_without_repairing_legacy_metadata(): void
    {
        $document = $this->legacyDocument(['storage_disk' => null, 'checksum' => null]);
        $manifest = $this->writeManifest([$document->id => [
            'storage_disk' => 'local',
            'storage_path' => $document->path,
            'sha256' => hash('sha256', Storage::disk('local')->get($document->path)),
            'size_bytes' => (int) $document->size,
        ]]);

        try {
            $this->artisan('documents:backfill-versions', ['--apply' => true, '--manifest' => $manifest])->assertSuccessful();
            $this->artisan('documents:backfill-versions', ['--apply' => true, '--manifest' => $manifest])
                ->expectsOutput("Document {$document->id}: already_registered")->assertSuccessful();
        } finally {
            unlink($manifest);
        }

        $baseline = DocumentVersion::query()->sole();
        $this->assertSame('local', $baseline->storage_disk);
        $this->assertSame(DocumentVersionIntegrityBasis::ObservedSha256, $baseline->integrity_basis);
        $this->assertNull($document->refresh()->storage_disk);
        $this->assertNull($document->checksum);
    }

    public function test_manifest_cannot_repoint_an_existing_original(): void
    {
        $document = $this->legacyDocument(['storage_disk' => null]);
        Storage::disk('local')->put('documents/other.pdf', Storage::disk('local')->get($document->path));
        $manifest = $this->writeManifest([$document->id => [
            'storage_disk' => 'local',
            'storage_path' => 'documents/other.pdf',
            'sha256' => $document->checksum,
            'size_bytes' => (int) $document->size,
        ]]);

        try {
            $this->artisan('documents:backfill-versions', ['--apply' => true, '--manifest' => $manifest])->assertFailed();
        } finally {
            unlink($manifest);
        }

        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_manifest_cannot_assign_orphaned_import_storage_to_a_legacy_document(): void
    {
        $document = $this->legacyDocument(['storage_disk' => null]);
        $bytes = Storage::disk('local')->get($document->path);
        Storage::disk('project-imports')->put($document->path, $bytes);
        $manifest = $this->writeManifest([$document->id => [
            'storage_disk' => 'project-imports',
            'storage_path' => $document->path,
            'sha256' => $document->checksum,
            'size_bytes' => (int) $document->size,
        ]]);

        try {
            $this->artisan('documents:backfill-versions', ['--manifest' => $manifest])->assertFailed();
            $this->artisan('documents:backfill-versions', ['--apply' => true, '--manifest' => $manifest])->assertFailed();
        } finally {
            unlink($manifest);
        }

        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame($bytes, Storage::disk('project-imports')->get($document->path));
        $this->assertNull($document->refresh()->storage_disk);
    }

    public function test_batch_failure_does_not_undo_success_or_create_partial_rows_and_can_resume(): void
    {
        $first = $this->legacyDocument();
        $failed = $this->legacyDocument();
        $last = $this->legacyDocument();
        $missingBytes = Storage::disk('local')->get($failed->path);
        Storage::disk('local')->delete($failed->path);

        $this->artisan('documents:backfill-versions', ['--apply' => true, '--chunk' => 1])
            ->expectsOutput('would_register=0, registered=2, already_registered=0, failed=1')->assertFailed();
        $this->assertDatabaseCount('document_versions', 2);
        $this->assertDatabaseMissing('document_versions', ['project_document_id' => $failed->id]);
        $firstBefore = $first->initialVersion->getRawOriginal();
        $lastBefore = $last->initialVersion->getRawOriginal();
        Storage::disk('local')->put($failed->path, $missingBytes);

        $this->artisan('documents:backfill-versions', ['--apply' => true, '--chunk' => 1])
            ->expectsOutput('would_register=0, registered=1, already_registered=2, failed=0')->assertSuccessful();
        $this->assertDatabaseCount('document_versions', 3);
        $this->assertSame($firstBefore, $first->initialVersion->refresh()->getRawOriginal());
        $this->assertSame($lastBefore, $last->initialVersion->refresh()->getRawOriginal());
    }

    public function test_imported_pdf_backfill_requires_confirmed_matching_provenance_and_reuses_original(): void
    {
        [$import, $document] = $this->importedDocument();
        $importFields = [
            'public_id', 'uploaded_by', 'uploader_department_id', 'original_name',
            'storage_disk', 'storage_path', 'mime_type', 'size_bytes', 'sha256',
        ];
        $importBefore = $import->refresh()->only($importFields);
        $documentBefore = $document->refresh()->only(self::SOURCE_IDENTITY_FIELDS);
        $files = Storage::disk('project-imports')->allFiles();

        $this->artisan('documents:backfill-versions', ['--apply' => true])->assertSuccessful();

        $baseline = DocumentVersion::query()->sole();
        $this->assertSame($import->storage_disk, $baseline->storage_disk);
        $this->assertSame($import->storage_path, $baseline->storage_path);
        $this->assertSame($import->sha256, $baseline->sha256);
        $this->assertSame(1, $baseline->revision_no);
        $this->assertSame($importBefore, $import->refresh()->only($importFields));
        $this->assertSame($documentBefore, $document->refresh()->only(self::SOURCE_IDENTITY_FIELDS));
        $this->assertSame($files, Storage::disk('project-imports')->allFiles());
        $this->assertSame($document->path, $baseline->storage_path);
    }

    #[DataProvider('invalidImportProvenance')]
    public function test_import_provenance_conflicts_never_create_versions(array $importOverrides, array $documentOverrides): void
    {
        $this->importedDocument($importOverrides, $documentOverrides);

        $this->artisan('documents:backfill-versions', ['--apply' => true])->assertFailed();

        $this->assertDatabaseCount('document_versions', 0);
    }

    public static function invalidImportProvenance(): array
    {
        return [
            'unconfirmed' => [['status' => DocumentImportStatus::NeedsReview, 'confirmed_project_id' => null], []],
            'missing project' => [['confirmed_project_id' => null], []],
            'name conflict' => [[], ['original_name' => 'different.pdf']],
            'hash conflict' => [[], ['checksum' => str_repeat('0', 64)]],
            'size conflict' => [[], ['size' => 1]],
            'mime conflict' => [[], ['mime_type' => 'text/plain']],
            'disk conflict' => [[], ['storage_disk' => 'local']],
            'path conflict' => [[], ['path' => 'originals/different.pdf']],
        ];
    }

    public function test_import_uploader_conflict_is_rejected_before_blob_verification(): void
    {
        $otherUploader = User::factory()->create();
        [, $document] = $this->importedDocument([], ['uploaded_by' => $otherUploader->id]);
        $this->mock(DocumentBlobVerifier::class)->shouldNotReceive('verifyDocument');

        $this->artisan('documents:backfill-versions', ['--apply' => true])
            ->expectsOutput("Document {$document->id}: failed (document_version_provenance_invalid)")
            ->assertFailed();

        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame($otherUploader->id, $document->refresh()->uploaded_by);
    }

    public function test_import_identity_conflict_is_rejected_before_blob_verification(): void
    {
        [, $document] = $this->importedDocument([], ['original_name' => 'another.pdf']);
        $this->mock(DocumentBlobVerifier::class)->shouldNotReceive('verifyDocument');

        $this->artisan('documents:backfill-versions', ['--apply' => true])
            ->expectsOutput("Document {$document->id}: failed (document_version_provenance_invalid)")
            ->assertFailed();

        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_matching_metadata_for_a_different_private_object_does_not_establish_import_identity(): void
    {
        $otherBytes = "%PDF-1.4\nDifferent private source\n%%EOF\n";
        [, $document] = $this->importedDocument([], [
            'path' => 'originals/another.pdf',
            'size' => strlen($otherBytes),
            'checksum' => hash('sha256', $otherBytes),
        ]);
        Storage::disk('project-imports')->put($document->path, $otherBytes);

        $this->artisan('documents:backfill-versions', ['--apply' => true])->assertFailed();

        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame($otherBytes, Storage::disk('project-imports')->get($document->path));
    }

    public function test_existing_conflicting_baseline_is_never_overwritten_or_renumbered(): void
    {
        $document = $this->legacyDocument();
        $baseline = DocumentVersion::query()->create([
            'public_id' => (string) Str::uuid(),
            'project_document_id' => $document->id,
            'revision_no' => 1,
            'created_via' => DocumentVersionCreatedVia::Backfill,
            'storage_disk' => 'local',
            'storage_path' => $document->path,
            'original_name' => $document->original_name,
            'mime_type' => 'application/pdf',
            'size_bytes' => $document->size,
            'sha256' => str_repeat('0', 64),
            'integrity_basis' => DocumentVersionIntegrityBasis::RecordedSha256,
            'created_by' => null,
            'verified_at' => now(),
            'created_at' => now(),
        ]);
        $before = $baseline->refresh()->getRawOriginal();

        $this->artisan('documents:backfill-versions', ['--apply' => true])
            ->expectsOutput("Document {$document->id}: failed (document_version_baseline_conflict)")->assertFailed();
        $this->artisan('documents:backfill-versions')->assertFailed();

        $this->assertSame($before, $baseline->refresh()->getRawOriginal());
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_document_selection_reports_missing_ids_and_does_not_process_other_documents(): void
    {
        $selected = $this->legacyDocument();
        $unselected = $this->legacyDocument();

        $this->artisan('documents:backfill-versions', [
            '--apply' => true,
            '--document-id' => [(string) $selected->id, '999999'],
        ])->expectsOutput('Document 999999: failed (document_not_found)')->assertFailed();

        $this->assertDatabaseHas('document_versions', ['project_document_id' => $selected->id]);
        $this->assertDatabaseMissing('document_versions', ['project_document_id' => $unselected->id]);
    }

    public function test_invalid_manifest_is_rejected_before_any_write(): void
    {
        $document = $this->legacyDocument();
        $manifest = $this->writeManifest([$document->id => ['storage_disk' => 'local']]);

        try {
            $this->artisan('documents:backfill-versions', ['--apply' => true, '--manifest' => $manifest])->assertFailed();
        } finally {
            unlink($manifest);
        }

        $this->assertDatabaseCount('document_versions', 0);
    }

    private function legacyDocument(array $overrides = [], string $bytes = "%PDF-1.4\nPhase six baseline\n%%EOF\n"): ProjectDocument
    {
        $path = 'documents/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $bytes);

        return ProjectDocument::query()->create(array_merge([
            'project_id' => $this->project->id,
            'original_name' => 'legacy.pdf',
            'path' => $path,
            'storage_disk' => 'local',
            'mime_type' => 'application/pdf',
            'size' => strlen($bytes),
            'uploaded_by' => $this->owner->id,
            'checksum' => hash('sha256', $bytes),
            'version' => 1,
        ], $overrides));
    }

    /** @return array{DocumentImport, ProjectDocument} */
    private function importedDocument(array $importOverrides = [], array $documentOverrides = []): array
    {
        $import = $this->createDocumentImport($this->owner, DocumentImportStatus::Confirmed, array_merge([
            'confirmed_project_id' => $this->project->id,
            'confirmed_by' => $this->owner->id,
            'confirmed_at' => now(),
            'confirmed_preview_revision' => 3,
        ], $importOverrides));
        $document = ProjectDocument::query()->create(array_merge([
            'project_id' => $this->project->id,
            'source_import_id' => $import->id,
            'original_name' => $import->original_name,
            'path' => $import->storage_path,
            'storage_disk' => $import->storage_disk,
            'mime_type' => $import->mime_type,
            'size' => $import->size_bytes,
            'uploaded_by' => $import->uploaded_by,
            'checksum' => $import->sha256,
            'version' => 1,
        ], $documentOverrides));

        return [$import, $document];
    }

    private function writeManifest(array $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phase6a-manifest-');
        file_put_contents($path, json_encode((object) $manifest, JSON_THROW_ON_ERROR));

        return $path;
    }
}
