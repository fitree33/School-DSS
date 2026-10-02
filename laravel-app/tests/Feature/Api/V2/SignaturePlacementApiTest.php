<?php

namespace Tests\Feature\Api\V2;

use App\Contracts\Imports\ProcessRunner;
use App\Enums\DocumentImportStatus;
use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Models\AuditLog;
use App\Models\DocumentImport;
use App\Models\DocumentVersion;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\ProjectSignatureSlot;
use App\Models\Role;
use App\Models\SignatureAsset;
use App\Models\User;
use App\Services\Imports\ProcessResult;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\BuildsProjectSignatureSlots;
use Tests\Feature\Concerns\BuildsSignatureAssetRows;
use Tests\TestCase;
use Tests\Feature\Concerns\UsesPrivateSignatureStorage;

class SignaturePlacementApiTest extends TestCase
{
    use BuildsProjectSignatureSlots;
    use BuildsSignatureAssetRows;
    use RefreshDatabase;
    use UsesPrivateSignatureStorage;

    private const PDF = "%PDF-1.4\nPlacement source fixture\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectSignatures();
        Storage::fake('placement-source');
        Storage::fake('placement-temporary');
        $this->protectSignatureFixtureDirectory(rtrim(Storage::disk('placement-temporary')->path(''), '/\\'));
        config()->set('filesystems.disks.placement-source', [
            'driver' => 'local',
            'root' => Storage::disk('placement-source')->path(''),
            'visibility' => 'private',
        ]);
        config()->set('document_versions.allowed_disks', ['placement-source']);
        config()->set('document_versions.temporary_directory', Storage::disk('placement-temporary')->path(''));
        $this->bindPageCount();
    }

    public function test_assigned_signer_can_load_create_update_and_reset_a_draft_without_signing(): void
    {
        [$owner, $project, $version, $slot, $asset] = $this->fixture();
        $foreign = $this->persistedSignatureAsset($this->signatureUser());
        $retired = $this->persistedSignatureAsset($owner);
        $retired->update($this->retirementValues($retired));
        $beforeVersions = DocumentVersion::query()->get()->map->getRawOriginal()->all();
        $beforeAudits = AuditLog::query()->get()->map->getRawOriginal()->all();
        $url = $this->url($version);

        $context = $this->actingAs($owner)->getJson($url)->assertOk()
            ->assertJsonPath('data.version.public_id', $version->public_id)
            ->assertJsonPath('data.version.page_count', 2)
            ->assertJsonCount(4, 'data.slots')
            ->assertJsonCount(1, 'data.assets')
            ->assertJsonPath('data.assets.0.public_id', $asset->public_id)
            ->assertJsonPath('data.slots.0.can_sign', true)
            ->assertJsonPath('data.slots.0.placement', null);
        $this->assertSame(['project_proposer', 'related_approver', 'deputy_director', 'director'], array_column($context->json('data.slots'), 'slot_code'));
        foreach ([$version->storage_path, $asset->storage_key, $foreign->public_id, $retired->public_id] as $private) {
            $this->assertStringNotContainsString($private, $context->getContent());
        }

        $created = $this->putJson($url.'/'.$slot->id, $this->payload($asset))->assertSuccessful()
            ->assertJsonPath('data.signature_asset_id', $asset->public_id)
            ->assertJsonPath('data.signature_slot_id', $slot->id)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.stale', false);
        $id = $created->json('data.id');
        $this->assertDatabaseCount('signature_placements', 1);
        $this->putJson($url.'/'.$slot->id, $this->payload($asset, ['page' => 2, 'x' => 0.55, 'y' => 0.70]))
            ->assertSuccessful()->assertJsonPath('data.id', $id)->assertJsonPath('data.page', 2);
        $this->getJson($url)->assertOk()->assertJsonPath('data.slots.0.placement.id', $id)
            ->assertJsonPath('data.slots.0.placement.page', 2)
            ->assertJsonPath('data.slots.0.placement.x', 0.55)
            ->assertJsonPath('data.slots.0.placement.y', 0.70);
        $this->assertDatabaseCount('signature_placements', 1);

        $this->deleteJson($url.'/'.$slot->id)->assertSuccessful();
        $this->deleteJson($url.'/'.$slot->id)->assertSuccessful();
        $this->getJson($url)->assertOk()->assertJsonPath('data.slots.0.placement', null);
        $this->assertDatabaseCount('signature_placements', 0);
        $this->assertDatabaseCount('document_signatures', 0);
        $this->assertSame($beforeVersions, DocumentVersion::query()->get()->map->getRawOriginal()->all());
        $this->assertSame($beforeAudits, AuditLog::query()->get()->map->getRawOriginal()->all());
        $this->assertSame(self::PDF, Storage::disk('placement-source')->get($version->storage_path));
        $this->assertSame([], Storage::disk('placement-temporary')->allFiles());
    }

    public function test_authentication_and_project_visibility_are_required_before_reading_bytes(): void
    {
        [$owner, , $version, $slot, $asset] = $this->fixture();
        Storage::disk('placement-source')->delete($version->storage_path);
        $url = $this->url($version);
        $this->getJson($url)->assertUnauthorized();
        $this->putJson($url.'/'.$slot->id, $this->payload($asset))->assertUnauthorized();
        $this->deleteJson($url.'/'.$slot->id)->assertUnauthorized();

        $outsider = $this->signatureUser();
        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->putJson($url.'/'.$slot->id, $this->payload($asset))->assertNotFound();
        $this->deleteJson($url.'/'.$slot->id)->assertNotFound();
        $owner->update(['is_active' => false]);
        $this->actingAs($owner)->getJson($url)->assertForbidden();
        $this->assertDatabaseCount('signature_placements', 0);
    }

    #[DataProvider('wrongSignerRoles')]
    public function test_project_owner_or_privileged_role_cannot_bypass_assigned_signer(string $roleCode): void
    {
        [, $project, $version, $slot] = $this->fixture();
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => 'Fixture '.$roleCode]);
        if ($roleCode === 'admin') {
            $role->permissions()->syncWithoutDetaching(Permission::query()->pluck('id')->all());
        }
        $actor = $this->signatureUser($roleCode);
        $this->shareProject($project, $actor);
        $asset = $this->persistedSignatureAsset($actor);
        $url = $this->url($version);
        $this->actingAs($actor)->getJson($url)->assertOk()->assertJsonPath('data.slots.0.can_sign', false);
        $this->putJson($url.'/'.$slot->id, $this->payload($asset))->assertForbidden();
        $this->deleteJson($url.'/'.$slot->id)->assertForbidden();
        $this->assertDatabaseCount('signature_placements', 0);
    }

    public static function wrongSignerRoles(): array
    {
        return [['teacher'], ['director'], ['admin']];
    }

    public function test_foreign_and_retired_assets_cannot_create_or_replace_a_draft(): void
    {
        [$owner, , $version, $slot, $asset] = $this->fixture();
        $foreign = $this->persistedSignatureAsset($this->signatureUser());
        $retired = $this->persistedSignatureAsset($owner);
        $retired->update($this->retirementValues($retired));
        $url = $this->url($version).'/'.$slot->id;
        $this->actingAs($owner)->putJson($url, $this->payload($asset))->assertSuccessful();
        $before = DB::table('signature_placements')->first();

        $this->putJson($url, $this->payload($foreign))->assertNotFound();
        $this->putJson($url, $this->payload($asset, ['signature_asset_id' => (string) Str::uuid()]))->assertNotFound();
        $this->putJson($url, $this->payload($retired))->assertUnprocessable();
        $this->assertEquals($before, DB::table('signature_placements')->first());
    }

    #[DataProvider('invalidPlacements')]
    public function test_invalid_page_coordinates_or_client_metadata_are_rejected(array $overrides): void
    {
        [$owner, , $version, $slot, $asset] = $this->fixture();
        $this->actingAs($owner)->putJson($this->url($version).'/'.$slot->id, $this->payload($asset, $overrides))
            ->assertUnprocessable();
        $this->assertDatabaseCount('signature_placements', 0);
    }

    public static function invalidPlacements(): array
    {
        return [
            'page zero' => [['page' => 0]],
            'page fractional' => [['page' => 1.5]],
            'page above verified count' => [['page' => 3]],
            'negative x' => [['x' => -0.001]],
            'x above one' => [['x' => 1.001]],
            'negative y' => [['y' => -0.001]],
            'y above one' => [['y' => 1.001]],
            'zero width' => [['width' => 0]],
            'width above one' => [['width' => 1.001]],
            'zero height' => [['height' => 0]],
            'height above one' => [['height' => 1.001]],
            'rectangle crosses right edge' => [['x' => 0.95, 'width' => 0.10]],
            'rectangle crosses bottom edge' => [['y' => 0.99, 'height' => 0.05]],
            'non numeric coordinate' => [['x' => 'NaN']],
            'client page count' => [['page_count' => 999]],
            'injected project' => [['project_id' => 999]],
        ];
    }

    public function test_nested_routes_reject_cross_project_document_version_and_slot(): void
    {
        [$owner, $project, $version, $slot, $asset] = $this->fixture();
        $otherProject = $this->signatureProject($owner);
        $otherVersion = $this->versionFixture($otherProject, $owner);
        $secondDocumentVersion = $this->versionFixture($project, $owner);
        $foreignSlot = $otherProject->signatureSlots()->firstOrFail();
        $root = "/api/v2/projects/{$project->id}/documents/{$version->project_document_id}/versions";
        $badUrls = [
            "/api/v2/projects/{$otherProject->id}/documents/{$version->project_document_id}/versions/{$version->public_id}/placements",
            $root."/{$otherVersion->public_id}/placements",
            $root."/{$secondDocumentVersion->public_id}/placements",
            $root.'/'.Str::uuid().'/placements',
        ];
        $this->actingAs($owner);
        foreach ($badUrls as $url) {
            $this->getJson($url)->assertNotFound();
            $this->putJson($url.'/'.$slot->id, $this->payload($asset))->assertNotFound();
        }
        $this->putJson($this->url($version).'/'.$foreignSlot->id, $this->payload($asset))->assertNotFound();
        $this->deleteJson($this->url($version).'/'.$foreignSlot->id)->assertNotFound();
        $this->assertDatabaseCount('signature_placements', 0);
    }

    public function test_import_access_is_required_even_when_the_shared_project_signer_is_assigned(): void
    {
        [$owner, $project, , $slot] = $this->fixture();
        $signer = $this->signatureUser();
        $this->shareProject($project, $signer);
        $slot->update(['assigned_user_id' => $signer->id, 'assignment_revision' => 2]);
        $version = $this->versionFixture($project, $owner, imported: true);
        $asset = $this->persistedSignatureAsset($signer);
        $this->assertTrue($signer->can('view', $project));
        $this->assertFalse($signer->can('download', $version));
        $this->actingAs($signer)->getJson($this->url($version))->assertNotFound();
        $this->putJson($this->url($version).'/'.$slot->id, $this->payload($asset))->assertNotFound();
        $this->deleteJson($this->url($version).'/'.$slot->id)->assertNotFound();
        $this->assertDatabaseCount('signature_placements', 0);
    }

    public function test_reassignment_marks_old_draft_stale_and_new_signer_must_select_own_asset(): void
    {
        [$owner, $project, $version, $slot, $asset] = $this->fixture();
        $url = $this->url($version);
        $this->actingAs($owner)->putJson($url.'/'.$slot->id, $this->payload($asset))->assertSuccessful();
        $signer = $this->signatureUser();
        $this->shareProject($project, $signer);
        $slot->update(['assigned_user_id' => $signer->id, 'assignment_revision' => 2]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.slots.0.can_sign', false)
            ->assertJsonPath('data.slots.0.placement.stale', true);
        $this->putJson($url.'/'.$slot->id, $this->payload($asset))->assertForbidden();
        $this->deleteJson($url.'/'.$slot->id)->assertForbidden();

        $newAsset = $this->persistedSignatureAsset($signer);
        $beforeReplacement = DB::table('signature_placements')->first();
        $newContext = $this->actingAs($signer)->getJson($url)->assertOk()
            ->assertJsonPath('data.slots.0.placement', null)
            ->assertJsonCount(1, 'data.assets')
            ->assertJsonPath('data.assets.0.public_id', $newAsset->public_id);
        $this->assertStringNotContainsString($asset->public_id, $newContext->getContent());
        $this->deleteJson($url.'/'.$slot->id)->assertNoContent();
        $this->assertEquals($beforeReplacement, DB::table('signature_placements')->first());
        $this->actingAs($signer)->putJson($url.'/'.$slot->id, $this->payload($newAsset))->assertConflict()
            ->assertJsonPath('code', 'signature_assignment_changed');
        $this->putJson($url.'/'.$slot->id, $this->payload($asset, ['assignment_revision' => 2]))->assertNotFound();
        $this->putJson($url.'/'.$slot->id, $this->payload($newAsset, ['assignment_revision' => 2]))->assertSuccessful()
            ->assertJsonPath('data.signature_asset_id', $newAsset->public_id)
            ->assertJsonPath('data.assignment_revision', 2)
            ->assertJsonPath('data.stale', false);
        $this->assertDatabaseCount('signature_placements', 1);
        $this->assertDatabaseCount('document_versions', 1);
        $this->assertDatabaseCount('document_signatures', 0);
        $oldContext = $this->actingAs($owner)->getJson($url)->assertOk()
            ->assertJsonPath('data.slots.0.placement', null);
        $this->assertStringNotContainsString($newAsset->public_id, $oldContext->getContent());
    }

    #[DataProvider('changesDuringInspection')]
    public function test_save_rechecks_authorization_and_revision_after_pdf_inspection(string $change, int $status): void
    {
        [$owner, , $version, $slot, $asset] = $this->fixture();
        $replacement = $this->signatureUser();
        $this->bindPageCount(function () use ($change, $owner, $slot, $asset, $replacement): void {
            match ($change) {
                'revision' => $slot->update(['assignment_revision' => 2]),
                'assignee' => $slot->update(['assigned_user_id' => $replacement->id, 'assignment_revision' => 2]),
                'inactive actor' => $owner->fresh()->update(['is_active' => false]),
                'retired asset' => $asset->update($this->retirementValues($asset)),
            };
        });

        $response = $this->actingAs($owner)->putJson($this->url($version).'/'.$slot->id, $this->payload($asset))
            ->assertStatus($status);
        if ($change === 'revision') {
            $response->assertJsonPath('code', 'signature_assignment_changed');
        }
        $this->assertDatabaseCount('signature_placements', 0);
        $this->assertSame([], Storage::disk('placement-temporary')->allFiles());
    }

    public static function changesDuringInspection(): array
    {
        return [
            'assignment revision changed' => ['revision', 409],
            'assigned signer changed' => ['assignee', 403],
            'actor became inactive' => ['inactive actor', 404],
            'asset was retired' => ['retired asset', 422],
        ];
    }

    public function test_retired_asset_and_now_ineligible_assignee_cannot_reuse_a_saved_draft(): void
    {
        [$owner, , $version, $slot, $asset] = $this->fixture();
        $url = $this->url($version);
        $this->actingAs($owner)->putJson($url.'/'.$slot->id, $this->payload($asset))->assertSuccessful();
        $asset->update($this->retirementValues($asset));
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.assets')->assertJsonPath('data.slots.0.placement.stale', true);
        $this->putJson($url.'/'.$slot->id, $this->payload($asset))->assertUnprocessable();

        $role = Role::query()->create(['code' => 'ineligible_fixture', 'name' => 'Ineligible']);
        $owner->update(['role_id' => $role->id]);
        $this->actingAs($owner->fresh())->getJson($url)->assertOk()->assertJsonPath('data.slots.0.can_sign', false);
        $this->putJson($url.'/'.$slot->id, $this->payload($asset))->assertForbidden();
    }

    public function test_changed_pdf_bytes_fail_integrity_before_page_count_or_any_draft_write(): void
    {
        [$owner, , $version, $slot, $asset] = $this->fixture();
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->never())->method('run');
        $this->app->instance(ProcessRunner::class, $runner);
        Storage::disk('placement-source')->put($version->storage_path, str_replace('source', 'change', self::PDF));
        $this->actingAs($owner)->getJson($this->url($version))->assertConflict()->assertJsonPath('code', 'document_version_integrity_failed');
        $this->putJson($this->url($version).'/'.$slot->id, $this->payload($asset))->assertConflict();
        $this->assertDatabaseCount('signature_placements', 0);
        $this->assertSame([], Storage::disk('placement-temporary')->allFiles());
    }

    /** @return array{User, Project, DocumentVersion, ProjectSignatureSlot, SignatureAsset} */
    private function fixture(): array
    {
        $owner = $this->signatureUser();
        $project = $this->signatureProject($owner);
        $version = $this->versionFixture($project, $owner);
        $slot = $project->signatureSlots()->where('slot_code', 'project_proposer')->firstOrFail();
        $slot->update(['assigned_user_id' => $owner->id, 'assigned_by' => $owner->id, 'assigned_at' => now(), 'assignment_revision' => 1]);

        return [$owner, $project, $version, $slot, $this->persistedSignatureAsset($owner)];
    }

    private function versionFixture(Project $project, User $owner, bool $imported = false): DocumentVersion
    {
        $path = 'originals/'.Str::uuid().'.pdf';
        Storage::disk('placement-source')->put($path, self::PDF);
        $sourceImport = $imported ? DocumentImport::query()->create([
            'uploaded_by' => $owner->id,
            'uploader_department_id' => $owner->department_id,
            'status' => DocumentImportStatus::Confirmed,
            'confirmed_project_id' => $project->id,
            'confirmed_by' => $owner->id,
            'confirmed_at' => now(),
            'storage_disk' => 'placement-source',
            'storage_path' => $path,
            'original_name' => 'placement.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen(self::PDF),
            'sha256' => hash('sha256', self::PDF),
            'page_count' => 99,
        ]) : null;
        $document = $project->documents()->create([
            'source_import_id' => $sourceImport?->id,
            'original_name' => 'placement.pdf', 'path' => $path, 'storage_disk' => 'placement-source',
            'mime_type' => 'application/pdf', 'size' => strlen(self::PDF), 'uploaded_by' => $owner->id,
            'checksum' => hash('sha256', self::PDF), 'version' => 1,
        ]);

        return $document->versions()->create([
            'revision_no' => 1, 'created_via' => DocumentVersionCreatedVia::Backfill,
            'storage_disk' => 'placement-source', 'storage_path' => $path,
            'original_name' => $document->original_name, 'mime_type' => 'application/pdf',
            'size_bytes' => strlen(self::PDF), 'sha256' => hash('sha256', self::PDF),
            'integrity_basis' => DocumentVersionIntegrityBasis::RecordedSha256,
            'created_by' => null, 'verified_at' => now(),
        ])->refresh();
    }

    private function payload(SignatureAsset $asset, array $overrides = []): array
    {
        return array_replace(['signature_asset_id' => $asset->public_id, 'assignment_revision' => 1, 'page' => 1, 'x' => 0.1, 'y' => 0.2, 'width' => 0.2, 'height' => 0.1], $overrides);
    }

    private function url(DocumentVersion $version): string
    {
        return "/api/v2/projects/{$version->document->project_id}/documents/{$version->project_document_id}/versions/{$version->public_id}/placements";
    }

    private function shareProject(Project $project, User $viewer): void
    {
        ProjectAccess::query()->create(['project_id' => $project->id, 'user_id' => $viewer->id, 'can_view' => true, 'can_edit' => false, 'granted_by' => $project->user_id]);
    }

    private function bindPageCount(?Closure $afterInspection = null): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->method('run')->willReturnCallback(function (array $command, float $timeout, int $maxBytes) use ($afterInspection): ProcessResult {
            $this->assertCount(2, $command);
            $this->assertSame(self::PDF, file_get_contents($command[1]));
            $this->assertStringStartsWith(str_replace('\\', '/', Storage::disk('placement-temporary')->path('')), str_replace('\\', '/', $command[1]));
            $this->assertGreaterThan(0, $timeout);
            $this->assertLessThanOrEqual(65536, $maxBytes);
            $afterInspection?->__invoke();

            return new ProcessResult(0, "Pages: 2\nEncrypted: no\n", '');
        });
        $this->app->instance(ProcessRunner::class, $runner);
    }
}
