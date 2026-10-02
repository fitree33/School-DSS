<?php

namespace Tests\Feature\Api\V2;

use App\Contracts\Imports\ProcessRunner;
use App\DTOs\Documents\StoredSignedDocument;
use App\DTOs\Signatures\OwnedSignatureWorkspace;
use App\Enums\DocumentImportStatus;
use App\Enums\DocumentVersionCreatedVia;
use App\Enums\DocumentVersionIntegrityBasis;
use App\Exceptions\ApiProblemException;
use App\Models\AuditLog;
use App\Models\DocumentImport;
use App\Models\DocumentSignature;
use App\Models\DocumentVersion;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectAccess;
use App\Models\ProjectSignatureSlot;
use App\Models\Role;
use App\Models\SignatureAsset;
use App\Models\SignaturePlacement;
use App\Models\User;
use App\Services\Documents\SignedDocumentStorage;
use App\Services\Imports\ProcessResult;
use App\Services\Signatures\PlacementFingerprint;
use App\Services\Signatures\SignatureAssetStorage;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\BuildsProjectSignatureSlots;
use Tests\Feature\Concerns\BuildsSignatureAssetRows;
use Tests\Feature\Concerns\UsesPrivateSignatureStorage;
use Tests\Support\SignaturePngFixture;
use Tests\TestCase;

/** API/transaction tests; actual renderer and host privacy have separate integration coverage. */
class DocumentSignatureApiTest extends TestCase
{
    use BuildsProjectSignatureSlots;
    use BuildsSignatureAssetRows;
    use RefreshDatabase;
    use UsesPrivateSignatureStorage;

    private const PDF = "%PDF-1.4\nSigning source fixture\n%%EOF\n";

    private int $generations = 0;
    private ?Closure $duringGeneration = null;
    private ?Closure $afterStore = null;
    private bool $generationFails = false;
    private bool $storageFails = false;
    private array $stampCommands = [];

    protected function beforeRefreshingDatabase(): void
    {
        $connection = config('database.default');
        $configuration = config("database.connections.{$connection}");
        $this->assertEmpty($configuration['url'] ?? null);
        $this->assertEmpty($configuration['unix_socket'] ?? null);
        if ($connection === 'sqlite') {
            $this->assertSame(':memory:', $configuration['database']);

            return;
        }
        $this->assertSame('mysql', $connection);
        $this->assertSame('127.0.0.1', $configuration['host']);
        $this->assertSame('33084', (string) $configuration['port']);
        $this->assertSame('school_dss_foundation_test', $configuration['database']);
        $this->assertSame('1', getenv('ALLOW_MYSQL_FOUNDATION_TESTS'));
        $this->assertTrue(RefreshDatabaseState::$migrated, 'MySQL requires the guarded incremental bootstrap; this suite never rebuilds MySQL schemas.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjectSignatures();
        foreach (['signing-source', 'signing-output', 'signing-temporary', 'signing-workspace'] as $disk) {
            Storage::fake($disk);
            config()->set("filesystems.disks.{$disk}", [
                'driver' => 'local', 'root' => Storage::disk($disk)->path(''), 'visibility' => 'private',
            ]);
            $this->protectSignatureFixtureDirectory(rtrim(Storage::disk($disk)->path(''), '/\\'));
        }
        config()->set('document_versions.allowed_disks', ['signing-source', 'signing-output']);
        config()->set('document_versions.temporary_directory', Storage::disk('signing-temporary')->path(''));
        config()->set('document_signing.storage_disk', 'signing-output');
        $this->bindSigningAdapters();
    }

    public function test_explicit_sign_creates_verified_new_version_immutable_evidence_and_success_audit(): void
    {
        [$owner, $project, $source, $slot, $asset, $placement] = $this->fixture();
        $before = $source->getRawOriginal();
        $this->assertDatabaseCount('document_signatures', 0);
        $this->assertDatabaseCount('document_versions', 1);
        $this->assertSame(0, $this->generations, 'Saving placement must not stamp a document.');

        $response = $this->actingAs($owner)->postJson($this->url($source, $slot), $this->requestPayload($placement))
            ->assertCreated()->assertJsonPath('data.source_version_id', $source->public_id)
            ->assertJsonPath('data.signed_version.revision_no', 2);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $signed = DocumentVersion::query()->where('public_id', $response->json('data.signed_version.public_id'))->firstOrFail();
        $evidence = DocumentSignature::query()->firstOrFail();
        $bytes = Storage::disk($signed->storage_disk)->get($signed->storage_path);
        $this->assertSame($before, $source->refresh()->getRawOriginal());
        $this->assertSame(self::PDF, Storage::disk($source->storage_disk)->get($source->storage_path));
        $this->assertSame(hash('sha256', self::PDF), $evidence->before_sha256);
        $this->assertSame(hash('sha256', $bytes), $evidence->after_sha256);
        $this->assertSame($signed->sha256, $evidence->after_sha256);
        $this->assertNotSame($evidence->before_sha256, $evidence->after_sha256);
        $this->assertSame(strlen($bytes), $signed->size_bytes);
        $this->assertSame(DocumentVersionCreatedVia::Signature, $signed->created_via);
        $this->assertSame([
            $project->id, $source->project_document_id, $source->id, $signed->id,
            $slot->id, 1, $owner->id, $asset->id, 2,
        ], array_values($evidence->only([
            'project_id', 'project_document_id', 'source_document_version_id', 'signed_document_version_id',
            'project_signature_slot_id', 'assignment_revision', 'signer_id', 'signature_asset_id', 'page',
        ])));
        $this->assertSame(['0.15000000', '0.30000000', '0.25000000', '0.10000000'], array_values($evidence->only(['x', 'y', 'width', 'height'])));
        $this->assertNotNull($evidence->signed_at);
        $this->assertSame(['2', '0.15', '0.3', '0.25', '0.1'], array_slice($this->stampCommands[0], 6));
        $audit = AuditLog::query()->where('action', 'document.signed')->sole();
        $this->assertSame($evidence->id, $audit->auditable_id);
        $this->assertSame($source->sha256, $audit->old_values['sha256']);
        $this->assertSame($signed->sha256, $audit->new_values['sha256']);
        $this->assertSame($evidence->public_id, $audit->new_values['signature_id']);
        foreach ([$source->storage_path, $signed->storage_path, $asset->storage_key, base64_encode(SignaturePngFixture::rgba())] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, $audit->toJson());
        }
        $this->assertSame($bytes, $this->get($response->json('data.signed_version.download_url'))->assertOk()->streamedContent());
        $this->assertDatabaseCount('document_versions', 2);
        $this->assertDatabaseCount('document_signatures', 1);
        $this->assertTemporaryFilesEmpty();
    }

    public function test_same_user_can_sign_multiple_eligible_slots_on_successive_latest_versions(): void
    {
        [$owner, $project, $source, $slot, $asset, $placement] = $this->fixture();
        $first = $this->actingAs($owner)->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertCreated();
        $signed = DocumentVersion::query()->where('public_id', $first->json('data.signed_version.public_id'))->firstOrFail();
        $secondSlot = $project->signatureSlots()->where('slot_code', 'related_approver')->firstOrFail();
        $secondSlot->update(['assigned_user_id' => $owner->id, 'assignment_revision' => 1, 'assigned_by' => $owner->id, 'assigned_at' => now()]);
        $secondPlacement = $this->savePlacement($owner, $signed, $secondSlot, $asset);
        $this->postJson($this->url($signed, $secondSlot), $this->requestPayload($secondPlacement))->assertCreated()
            ->assertJsonPath('data.signed_version.revision_no', 3);
        $this->assertDatabaseCount('document_signatures', 2);
        $this->assertDatabaseCount('document_versions', 3);
        $this->assertSame(2, $this->generations);
    }

    public function test_authentication_visibility_and_active_account_are_required_before_generation(): void
    {
        [$owner, , $source, $slot, , $placement] = $this->fixture();
        auth()->forgetGuards();
        $this->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertUnauthorized();
        $this->actingAs($this->signatureUser())->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertNotFound();
        $owner->update(['is_active' => false]);
        $this->actingAs($owner)->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertForbidden();
        $this->assertNoCanonicalSign();
        $this->assertSame(0, $this->generations);
    }

    #[DataProvider('privilegedRoles')]
    public function test_project_owner_admin_and_director_have_no_assigned_signer_bypass(string $roleCode): void
    {
        [$owner, , $source, $slot, , $placement] = $this->fixture();
        $role = Role::query()->firstOrCreate(['code' => $roleCode], ['name' => 'Fixture '.$roleCode]);
        if ($roleCode === 'admin') $role->permissions()->syncWithoutDetaching(Permission::query()->pluck('id')->all());
        $owner->update(['role_id' => $role->id]);
        $slot->update(['assigned_user_id' => $this->signatureUser()->id, 'assignment_revision' => 2]);
        $this->actingAs($owner->fresh())->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertForbidden();
        $this->assertNoCanonicalSign();
    }

    public static function privilegedRoles(): array
    {
        return ['owner' => ['teacher'], 'director owner' => ['director'], 'admin owner' => ['admin']];
    }

    public function test_import_access_is_required_even_for_a_project_shared_assigned_signer(): void
    {
        [$owner, $project, , $slot] = $this->fixture();
        $signer = $this->signatureUser();
        $this->shareProject($project, $signer);
        $source = $this->versionFixture($project, $owner, imported: true);
        $slot->update(['assigned_user_id' => $signer->id, 'assignment_revision' => 2]);
        $this->assertTrue($signer->can('view', $project));
        $this->assertFalse($signer->can('download', $source));
        $this->actingAs($signer)->postJson($this->url($source, $slot), [
            'assignment_revision' => 2, 'placement_fingerprint' => str_repeat('a', 64), 'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertNoCanonicalSign(2);
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_requests_cannot_override_server_authoritative_snapshot(array $overrides): void
    {
        [$owner, , $source, $slot, , $placement] = $this->fixture();
        $this->actingAs($owner)->postJson($this->url($source, $slot), array_replace($this->requestPayload($placement), $overrides))->assertUnprocessable();
        $this->assertNoCanonicalSign();
    }

    public static function invalidRequests(): array
    {
        return [
            'missing key' => [['idempotency_key' => null]], 'invalid key' => [['idempotency_key' => 'duplicate']],
            'revision string' => [['assignment_revision' => '1']], 'revision zero' => [['assignment_revision' => 0]],
            'revision fraction' => [['assignment_revision' => 1.5]], 'revision overflow' => [['assignment_revision' => 4294967296]],
            'fingerprint missing' => [['placement_fingerprint' => null]], 'fingerprint uppercase' => [['placement_fingerprint' => str_repeat('A', 64)]],
            'client coordinates' => [['x' => 0.9]], 'client asset' => [['signature_asset_id' => 999]],
            'client source checksum' => [['before_sha256' => str_repeat('b', 64)]], 'client output path' => [['storage_path' => 'public/forged.pdf']],
        ];
    }

    #[DataProvider('staleStates')]
    public function test_stale_assignment_placement_and_ineligible_assets_are_rejected(string $change, int $status, string $code): void
    {
        [$owner, , $source, $slot, $asset, $placement] = $this->fixture();
        $payload = $this->requestPayload($placement);
        $this->changeState($change, $owner, $source, $slot, $asset, $placement);
        // Use the changed draft's fingerprint to prove asset ownership is independently checked.
        if ($change === 'foreign asset') $payload['placement_fingerprint'] = PlacementFingerprint::for($placement->refresh());
        $response = $this->actingAs($owner->fresh())->postJson($this->url($source, $slot), $payload)->assertStatus($status);
        if ($code !== '') $response->assertJsonPath('code', $code);
        $this->assertNoCanonicalSign();
        $this->assertSame(0, $this->generations);
    }

    public static function staleStates(): array
    {
        return [
            'assignment revision' => ['revision', 409, 'signature_assignment_changed'],
            'different signer' => ['assignee', 403, ''],
            'edited placement' => ['placement', 409, 'signature_placement_changed'],
            'missing placement' => ['missing placement', 409, 'signature_placement_changed'],
            'retired asset' => ['retired asset', 409, 'signature_asset_ineligible'],
            'foreign asset' => ['foreign asset', 409, 'signature_asset_ineligible'],
        ];
    }

    public function test_nested_project_document_version_and_slot_boundary_attacks_are_rejected(): void
    {
        [$owner, $project, $source, $slot, , $placement] = $this->fixture();
        $otherProject = $this->signatureProject($owner);
        $otherSource = $this->versionFixture($otherProject, $owner);
        $sameProjectOtherDocument = $this->versionFixture($project, $owner);
        $foreignSlot = $otherProject->signatureSlots()->firstOrFail();
        $root = "/api/v2/projects/{$project->id}/documents/{$source->project_document_id}/versions";
        foreach ([
            "/api/v2/projects/{$otherProject->id}/documents/{$source->project_document_id}/versions/{$source->public_id}/signatures/{$slot->id}",
            $root."/{$otherSource->public_id}/signatures/{$slot->id}",
            $root."/{$sameProjectOtherDocument->public_id}/signatures/{$slot->id}",
            $root.'/'.Str::uuid()."/signatures/{$slot->id}",
            $this->url($source, $foreignSlot),
        ] as $url) {
            $this->actingAs($owner)->postJson($url, $this->requestPayload($placement))->assertNotFound();
        }
        $this->assertNoCanonicalSign(3);
    }

    public function test_exact_retry_reuses_receipt_and_different_key_cannot_duplicate_the_sign(): void
    {
        [$owner, , $source, $slot, , $placement] = $this->fixture();
        $payload = $this->requestPayload($placement);
        $first = $this->actingAs($owner)->postJson($this->url($source, $slot), $payload)->assertCreated()->json('data');
        $this->postJson($this->url($source, $slot), $payload)->assertOk()->assertExactJson(['data' => $first]);
        $this->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertConflict();
        $this->postJson($this->url($source, $slot), array_replace($payload, ['placement_fingerprint' => str_repeat('f', 64)]))
            ->assertConflict()->assertJsonPath('code', 'document_signing_idempotency_conflict');
        $this->assertDatabaseCount('document_signatures', 1);
        $this->assertDatabaseCount('document_versions', 2);
        $this->assertSame(1, AuditLog::query()->where('action', 'document.signed')->count());
        $this->assertSame(1, $this->generations);
    }

    #[DataProvider('changedReplayStates')]
    public function test_retry_rejects_changed_authoritative_snapshot(string $change, int $status): void
    {
        [$owner, , $source, $slot, $asset, $placement] = $this->fixture();
        $payload = $this->requestPayload($placement);
        $this->actingAs($owner)->postJson($this->url($source, $slot), $payload)->assertCreated();
        $this->changeState($change, $owner, $source, $slot, $asset, $placement);
        $this->postJson($this->url($source, $slot), $payload)->assertStatus($status);
        $this->assertDatabaseCount('document_signatures', 1);
        $this->assertDatabaseCount('document_versions', 2);
        $this->assertSame(1, $this->generations);
        $this->assertSame(1, AuditLog::query()->where('action', 'document.signed')->count());
    }

    public static function changedReplayStates(): array
    {
        return [['revision', 409], ['assignee', 403], ['placement', 409], ['retired asset', 409], ['missing placement', 409], ['inactive actor', 403], ['foreign asset', 409]];
    }

    #[DataProvider('corruptedReplayVersions')]
    public function test_retry_rejects_changed_source_or_generated_bytes_without_new_canonical_state(string $versionKind): void
    {
        [$owner, , $source, $slot, , $placement] = $this->fixture();
        $payload = $this->requestPayload($placement);
        $this->actingAs($owner)->postJson($this->url($source, $slot), $payload)->assertCreated();
        $signature = DocumentSignature::query()->sole();
        $before = $signature->getRawOriginal();
        $version = $versionKind === 'source' ? $source : $signature->signedVersion;
        $bytes = Storage::disk($version->storage_disk)->get($version->storage_path);
        $changedBytes = str_replace('%%EOF', '%%BAD', $bytes);
        $this->assertSame(strlen($bytes), strlen($changedBytes));
        $this->assertNotSame($version->sha256, hash('sha256', $changedBytes));
        Storage::disk($version->storage_disk)->put($version->storage_path, $changedBytes);

        $this->postJson($this->url($source, $slot), $payload)->assertConflict()
            ->assertJsonPath('code', 'document_version_integrity_failed');

        $this->assertSame($before, $signature->refresh()->getRawOriginal());
        $this->assertDatabaseCount('document_signatures', 1);
        $this->assertDatabaseCount('document_versions', 2);
        $this->assertSame(1, AuditLog::query()->where('action', 'document.signed')->count());
        $this->assertSame(1, $this->generations);
        $this->assertTemporaryFilesEmpty();
    }

    public static function corruptedReplayVersions(): array
    {
        return ['source bytes' => ['source'], 'generated bytes' => ['generated']];
    }

    public function test_replay_conflicts_after_a_later_signature_supersedes_its_result(): void
    {
        [$owner, $project, $source, $slot, $asset, $placement] = $this->fixture();
        $payload = $this->requestPayload($placement);
        $first = $this->actingAs($owner)->postJson($this->url($source, $slot), $payload)->assertCreated();
        $signed = DocumentVersion::query()->where('public_id', $first->json('data.signed_version.public_id'))->firstOrFail();
        $nextSlot = $project->signatureSlots()->where('slot_code', 'related_approver')->firstOrFail();
        $nextSlot->update(['assigned_user_id' => $owner->id, 'assignment_revision' => 1, 'assigned_by' => $owner->id, 'assigned_at' => now()]);
        $nextPlacement = $this->savePlacement($owner, $signed, $nextSlot, $asset);
        $this->postJson($this->url($signed, $nextSlot), $this->requestPayload($nextPlacement))->assertCreated();
        $this->postJson($this->url($source, $slot), $payload)->assertConflict();
        $this->assertDatabaseCount('document_versions', 3);
        $this->assertDatabaseCount('document_signatures', 2);
    }

    public function test_an_unsigned_slot_cannot_sign_a_superseded_source(): void
    {
        [$owner, $project, $source, $slot, $asset, $placement] = $this->fixture();
        $nextSlot = $project->signatureSlots()->where('slot_code', 'related_approver')->firstOrFail();
        $nextSlot->update(['assigned_user_id' => $owner->id, 'assignment_revision' => 1, 'assigned_by' => $owner->id, 'assigned_at' => now()]);
        $nextPlacement = $this->savePlacement($owner, $source, $nextSlot, $asset);
        $this->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertCreated();
        $this->postJson($this->url($source, $nextSlot), $this->requestPayload($nextPlacement))->assertConflict()
            ->assertJsonPath('code', 'document_signing_source_changed');
        $this->assertDatabaseCount('document_signatures', 1);
    }

    #[DataProvider('changesDuringGeneration')]
    public function test_generation_is_followed_by_an_authoritative_state_and_source_recheck(string $change, int $status): void
    {
        [$owner, , $source, $slot, $asset, $placement] = $this->fixture();
        $this->duringGeneration = fn () => $this->changeState($change, $owner, $source, $slot, $asset, $placement);
        $this->actingAs($owner)->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertStatus($status);
        $this->assertSame(1, $this->generations);
        $this->assertNoCanonicalSign();
    }

    public static function changesDuringGeneration(): array
    {
        return [['revision', 409], ['assignee', 403], ['placement', 409], ['retired asset', 409], ['inactive actor', 403], ['source bytes', 409]];
    }

    #[DataProvider('failures')]
    public function test_generation_storage_and_database_failures_leave_no_canonical_state_or_success_audit(string $failure, int $status): void
    {
        [$owner, , $source, $slot, , $placement] = $this->fixture();
        $event = null;
        if ($failure === 'generation') $this->generationFails = true;
        if ($failure === 'storage') $this->storageFails = true;
        if ($failure === 'stored checksum') $this->afterStore = static fn (StoredSignedDocument $file) => file_put_contents($file->path, str_replace('Generated', 'Corrupted', file_get_contents($file->path)));
        if (in_array($failure, ['version insert', 'evidence insert', 'audit insert'], true)) {
            $class = match ($failure) { 'version insert' => DocumentVersion::class, 'evidence insert' => DocumentSignature::class, default => AuditLog::class };
            $event = 'eloquent.creating: '.$class;
            Event::listen($event, static function (): never { throw new RuntimeException('Injected database write failure.'); });
        }
        try {
            $this->actingAs($owner)->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertStatus($status);
        } finally {
            if ($event !== null) Event::forget($event);
        }
        // A replaced/corrupted file must not be deleted by an ownership-limited rollback.
        $this->assertNoCanonicalSign(expectNoOutput: $failure !== 'stored checksum');
    }

    public static function failures(): array
    {
        return [['generation', 503], ['storage', 503], ['stored checksum', 409], ['version insert', 500], ['evidence insert', 500], ['audit insert', 500]];
    }

    public function test_changed_source_bytes_are_rejected_before_generation(): void
    {
        [$owner, , $source, $slot, , $placement] = $this->fixture();
        Storage::disk($source->storage_disk)->put($source->storage_path, str_replace('source', 'change', self::PDF));
        $this->actingAs($owner)->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertConflict()
            ->assertJsonPath('code', 'document_version_integrity_failed');
        $this->assertSame(0, $this->generations);
        $this->assertNoCanonicalSign();
    }

    public function test_signed_evidence_survives_draft_deletion_and_rejects_model_and_database_rewrites(): void
    {
        [$owner, , $source, $slot, , $placement] = $this->fixture();
        $this->actingAs($owner)->postJson($this->url($source, $slot), $this->requestPayload($placement))->assertCreated();
        $signature = DocumentSignature::query()->sole();
        $before = $signature->getRawOriginal();
        $placement->delete();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $signature->fill(['page' => 1])->save() : $signature->delete();
                $this->fail('Immutable evidence was changed by its model.');
            } catch (LogicException) {
                $this->assertSame($before, $signature->fresh()->getRawOriginal());
            }
            try {
                $query = DB::table('document_signatures')->where('id', $signature->id);
                $operation === 'update' ? $query->update(['page' => 1]) : $query->delete();
                $this->fail('Immutable evidence was changed by a raw query.');
            } catch (QueryException) {
                $this->assertSame($before, $signature->fresh()->getRawOriginal());
            }
        }
        $this->assertDatabaseCount('signature_placements', 0);
        $this->assertDatabaseCount('document_signatures', 1);
    }

    private function fixture(): array
    {
        $owner = $this->signatureUser();
        $project = $this->signatureProject($owner);
        $source = $this->versionFixture($project, $owner);
        $slot = $project->signatureSlots()->where('slot_code', 'project_proposer')->firstOrFail();
        $slot->update(['assigned_user_id' => $owner->id, 'assignment_revision' => 1, 'assigned_by' => $owner->id, 'assigned_at' => now()]);
        $asset = $this->persistedSignatureAsset($owner);
        $placement = $this->savePlacement($owner, $source, $slot, $asset);

        return [$owner, $project, $source, $slot, $asset, $placement];
    }

    private function versionFixture(Project $project, User $owner, bool $imported = false): DocumentVersion
    {
        $path = 'originals/'.Str::uuid().'.pdf';
        Storage::disk('signing-source')->put($path, self::PDF);
        $import = $imported ? DocumentImport::query()->create([
            'uploaded_by' => $owner->id, 'uploader_department_id' => $owner->department_id,
            'status' => DocumentImportStatus::Confirmed, 'confirmed_project_id' => $project->id,
            'confirmed_by' => $owner->id, 'confirmed_at' => now(), 'storage_disk' => 'signing-source',
            'storage_path' => $path, 'original_name' => 'signing.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => strlen(self::PDF), 'sha256' => hash('sha256', self::PDF), 'page_count' => 2,
        ]) : null;
        $document = $project->documents()->create([
            'source_import_id' => $import?->id, 'original_name' => 'signing.pdf', 'path' => $path,
            'storage_disk' => 'signing-source', 'mime_type' => 'application/pdf', 'size' => strlen(self::PDF),
            'uploaded_by' => $owner->id, 'checksum' => hash('sha256', self::PDF), 'version' => 1,
        ]);

        return $document->versions()->create([
            'revision_no' => 1, 'created_via' => DocumentVersionCreatedVia::Backfill,
            'storage_disk' => 'signing-source', 'storage_path' => $path, 'original_name' => 'signing.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => strlen(self::PDF), 'sha256' => hash('sha256', self::PDF),
            'integrity_basis' => DocumentVersionIntegrityBasis::RecordedSha256, 'created_by' => null, 'verified_at' => now(),
        ])->refresh();
    }

    private function savePlacement(User $actor, DocumentVersion $source, ProjectSignatureSlot $slot, SignatureAsset $asset): SignaturePlacement
    {
        $this->actingAs($actor)->putJson(str_replace('/signatures/', '/placements/', $this->url($source, $slot)), [
            'signature_asset_id' => $asset->public_id, 'assignment_revision' => $slot->assignment_revision,
            'page' => 2, 'x' => 0.15, 'y' => 0.30, 'width' => 0.25, 'height' => 0.10,
        ])->assertSuccessful();

        return SignaturePlacement::query()->where('document_version_id', $source->id)->where('project_signature_slot_id', $slot->id)->sole();
    }

    private function requestPayload(SignaturePlacement $placement): array
    {
        return ['assignment_revision' => $placement->assignment_revision, 'placement_fingerprint' => PlacementFingerprint::for($placement), 'idempotency_key' => (string) Str::uuid()];
    }

    private function url(DocumentVersion $source, ProjectSignatureSlot $slot): string
    {
        return "/api/v2/projects/{$source->document->project_id}/documents/{$source->project_document_id}/versions/{$source->public_id}/signatures/{$slot->id}";
    }

    private function shareProject(Project $project, User $actor): void
    {
        ProjectAccess::query()->create(['project_id' => $project->id, 'user_id' => $actor->id, 'can_view' => true, 'can_edit' => false, 'granted_by' => $project->user_id]);
    }

    private function changeState(string $change, User $actor, DocumentVersion $source, ProjectSignatureSlot $slot, SignatureAsset $asset, SignaturePlacement $placement): void
    {
        match ($change) {
            'revision' => $slot->update(['assignment_revision' => 2]),
            'assignee' => $slot->update(['assigned_user_id' => $this->signatureUser()->id, 'assignment_revision' => 2]),
            'placement' => $placement->update(['x' => 0.3]),
            'missing placement' => $placement->delete(),
            'retired asset' => $asset->update($this->retirementValues($asset)),
            'foreign asset' => $placement->update(['signature_asset_id' => $this->persistedSignatureAsset($this->signatureUser())->id]),
            'inactive actor' => $actor->fresh()->update(['is_active' => false]),
            'source bytes' => Storage::disk($source->storage_disk)->put($source->storage_path, str_replace('source', 'change', self::PDF)),
            default => throw new RuntimeException('Unknown fixture change.'),
        };
    }

    private function assertNoCanonicalSign(int $sourceCount = 1, bool $expectNoOutput = true): void
    {
        $this->assertDatabaseCount('document_signatures', 0);
        $this->assertDatabaseCount('document_versions', $sourceCount);
        $this->assertSame(0, AuditLog::query()->where('action', 'document.signed')->count());
        if ($expectNoOutput) $this->assertSame([], Storage::disk('signing-output')->allFiles());
        $this->assertTemporaryFilesEmpty();
    }

    private function assertTemporaryFilesEmpty(): void
    {
        $this->assertSame([], Storage::disk('signing-temporary')->allFiles());
        $this->assertSame([], Storage::disk('signing-workspace')->allFiles());
        $this->assertSame([], Storage::disk('signing-workspace')->allDirectories());
    }

    private function bindSigningAdapters(): void
    {
        $assets = $this->createStub(SignatureAssetStorage::class);
        $assets->method('beginRequest')->willReturnCallback(function (): OwnedSignatureWorkspace {
            $directory = Storage::disk('signing-workspace')->path((string) Str::uuid());
            mkdir($directory, 0700);

            return new OwnedSignatureWorkspace($directory, null);
        });
        $assets->method('readVerified')->willReturn(SignaturePngFixture::rgba());
        $assets->method('cleanup')->willReturnCallback(static function (OwnedSignatureWorkspace $workspace): void {
            foreach (['signature.png', 'signed.pdf'] as $name) {
                $path = $workspace->directory().DIRECTORY_SEPARATOR.$name;
                if (is_file($path)) unlink($path);
            }
            rmdir($workspace->directory());
        });
        $this->app->instance(SignatureAssetStorage::class, $assets);

        $storage = $this->getStubBuilder(SignedDocumentStorage::class)->disableOriginalConstructor()->onlyMethods(['store'])->getStub();
        $storage->method('store')->willReturnCallback(function (string $generatedPath): StoredSignedDocument {
            if ($this->storageFails) throw new ApiProblemException('Fixture storage failure.', 'document_signing_storage_failed', 503);
            $relative = Str::uuid().'.pdf';
            $path = Storage::disk('signing-output')->path($relative);
            $bytes = file_get_contents($generatedPath);
            file_put_contents($path, $bytes);
            chmod($path, 0600);
            $file = new StoredSignedDocument('signing-output', $relative, $path, strlen($bytes), hash('sha256', $bytes), lstat($path));
            $this->afterStore?->__invoke($file);

            return $file;
        });
        $this->app->instance(SignedDocumentStorage::class, $storage);

        $runner = $this->createStub(ProcessRunner::class);
        $runner->method('run')->willReturnCallback(function (array $command): ProcessResult {
            if (count($command) === 2) return new ProcessResult(0, "Pages: 2\nEncrypted: no\n", '');
            $this->assertCount(11, $command);
            $this->assertSame(SignaturePngFixture::rgba(), file_get_contents($command[4]));
            $this->stampCommands[] = $command;
            $this->generations++;
            $this->duringGeneration?->__invoke();
            if ($this->generationFails) return new ProcessResult(1, '', 'Private worker detail.');
            file_put_contents($command[5], "%PDF-1.4\nGenerated signature {$this->generations}\n%%EOF\n");
            chmod($command[5], 0600);

            return new ProcessResult(0, '{"page_count":2}', '');
        });
        $this->app->instance(ProcessRunner::class, $runner);
    }
}
