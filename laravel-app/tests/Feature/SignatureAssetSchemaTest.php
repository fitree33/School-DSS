<?php

namespace Tests\Feature;

use App\Models\SignatureAsset;
use App\Models\User;
use App\Policies\SignatureAssetPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\BuildsSignatureAssetRows;
use Tests\Feature\Concerns\GuardsSignatureAssetMysql;
use Tests\TestCase;

class SignatureAssetSchemaTest extends TestCase
{
    use BuildsSignatureAssetRows;
    use GuardsSignatureAssetMysql, RefreshDatabase {
        GuardsSignatureAssetMysql::beforeRefreshingDatabase insteadof RefreshDatabase;
    }

    public function test_schema_has_exact_columns_unique_identity_and_restrict_foreign_keys(): void
    {
        $this->assertSame([
            'id', 'public_id', 'owner_id', 'storage_key', 'sha256', 'size_bytes', 'width', 'height',
            'mime_type', 'normalization_version', 'status', 'retired_at', 'retired_by',
            'retirement_reason', 'created_at', 'updated_at',
        ], Schema::getColumnListing('signature_assets'));

        $indexes = collect(Schema::getIndexes('signature_assets'));
        foreach (['public_id', 'storage_key'] as $column) {
            $index = $indexes->first(fn ($index) => $index['unique'] && $index['columns'] === [$column]);
            $this->assertNotNull($index);
            if (DB::getDriverName() === 'mysql') {
                $this->assertSame('signature_assets_'.$column.'_unique', $index['name']);
            }
        }
        $this->assertFalse($indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === ['sha256']));

        $keys = collect(Schema::getForeignKeys('signature_assets'));
        foreach (['owner_id', 'retired_by'] as $column) {
            $key = $keys->first(fn ($key) => $key['columns'] === [$column]);
            $this->assertNotNull($key);
            $this->assertSame('users', $key['foreign_table']);
            $this->assertSame(['id'], $key['foreign_columns']);
            $this->assertSame('restrict', strtolower($key['on_delete']));
            $this->assertSame('restrict', strtolower($key['on_update']));
        }
    }

    public function test_limits_formats_types_and_initial_retirement_state_are_enforced_in_database(): void
    {
        $owner = User::factory()->create();
        $valid = $this->signatureAssetRow($owner);
        $invalid = [
            'invalid public id' => ['public_id' => 'not-a-uuid'],
            'uppercase public id' => ['public_id' => 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA'],
            'invalid key' => ['storage_key' => '../'.str_repeat('a', 61).'.png'],
            'uppercase key' => ['storage_key' => str_repeat('A', 64).'.png'],
            'invalid hash' => ['sha256' => str_repeat('z', 64)],
            'uppercase hash' => ['sha256' => str_repeat('A', 64)],
            'empty size' => ['size_bytes' => 0],
            'size overflow' => ['size_bytes' => 8388609],
            'empty width' => ['width' => 0],
            'width overflow' => ['width' => 2049],
            'empty height' => ['height' => 0],
            'height overflow' => ['height' => 1025],
            'pixel overflow' => ['width' => 2048, 'height' => 513],
            'other MIME' => ['mime_type' => 'image/jpeg'],
            'case MIME' => ['mime_type' => 'IMAGE/PNG'],
            'spaced MIME' => ['mime_type' => 'image/png '],
            'other normalization' => ['normalization_version' => 'gd-png-v2'],
            'case normalization' => ['normalization_version' => 'GD-PNG-V1'],
            'unknown status' => ['status' => 'deleted'],
            'uppercase status' => ['status' => 'ACTIVE'],
            'initial retired' => ['status' => 'retired', 'retired_at' => $valid['created_at'], 'retired_by' => $owner->id],
            'active retired at' => ['retired_at' => $valid['created_at']],
            'active retired by' => ['retired_by' => $owner->id],
            'active reason' => ['retirement_reason' => 'reason'],
            'orphan owner' => ['owner_id' => 999999999],
        ];
        if (DB::getDriverName() === 'sqlite') {
            $invalid += [
                'fractional size' => ['size_bytes' => 1.5],
                'fractional width' => ['width' => 1.5],
                'fractional height' => ['height' => 1.5],
            ];
        }

        foreach ($invalid as $label => $overrides) {
            try {
                DB::table('signature_assets')->insert(array_replace($valid, $overrides));
                $this->fail('Database accepted '.$label.'.');
            } catch (QueryException) {
                $this->assertDatabaseCount('signature_assets', 0);
            }
        }

        $first = $this->persistedSignatureAsset($owner, ['width' => 2048, 'height' => 512, 'size_bytes' => 8388608]);
        $second = $this->persistedSignatureAsset($owner, ['width' => 1024, 'height' => 1024, 'size_bytes' => 1]);
        $this->assertSame($first->sha256, $second->sha256);
        $this->assertNotSame($first->storage_key, $second->storage_key);
        $this->assertDatabaseCount('signature_assets', 2);
    }

    public function test_owner_hard_delete_and_identity_update_are_restricted_in_active_and_retired_states(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $asset = $this->persistedSignatureAsset($owner);

        foreach (['active', 'retired'] as $status) {
            if ($status === 'retired') {
                $asset->forceFill($this->retirementValues($asset))->save();
            }
            $before = $asset->fresh()->getRawOriginal();

            foreach (['delete', 'update'] as $operation) {
                try {
                    $query = DB::table('users')->where('id', $owner->id);
                    $operation === 'delete' ? $query->delete() : $query->update(['id' => 999999999]);
                    $this->fail('Database accepted referenced owner '.$operation.'.');
                } catch (QueryException) {
                    $this->assertSame($before, $asset->fresh()->getRawOriginal());
                    $this->assertDatabaseHas('users', ['id' => $owner->id]);
                }
            }

            $owner->delete();
            $this->assertTrue($asset->fresh()->owner->trashed());
            $this->assertFalse((new SignatureAssetPolicy)->view($owner, $asset));
            $owner->restore();
            $owner->forceFill(['is_active' => false])->save();
            $this->assertFalse((new SignatureAssetPolicy)->preview($owner, $asset));
            $owner->forceFill(['is_active' => true])->save();
            $this->assertTrue((new SignatureAssetPolicy)->view($owner, $asset));
            $this->assertSame($before, $asset->fresh()->getRawOriginal());
        }
    }

    public function test_model_serialization_hides_integrity_locator_and_internal_actor_metadata(): void
    {
        $asset = $this->persistedSignatureAsset();
        $this->assertSame(SignatureAsset::NORMALIZATION_VERSION, $asset->normalization_version);
        $this->assertTrue($asset->isEligibleForSigning());
        foreach (['id', 'owner_id', 'storage_key', 'sha256', 'retired_by', 'retirement_reason', 'updated_at'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $asset->toArray());
        }

        $asset->forceFill($this->retirementValues($asset, 'Changed signature'))->save();
        $this->assertFalse($asset->fresh()->isEligibleForSigning());
        $this->assertSame($asset->owner_id, $asset->fresh()->retiredBy->id);
    }
}
