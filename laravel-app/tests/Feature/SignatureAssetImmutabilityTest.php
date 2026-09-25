<?php

namespace Tests\Feature;

use App\Models\SignatureAsset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Concerns\BuildsSignatureAssetRows;
use Tests\Feature\Concerns\GuardsSignatureAssetMysql;
use Tests\TestCase;

class SignatureAssetImmutabilityTest extends TestCase
{
    use BuildsSignatureAssetRows;
    use GuardsSignatureAssetMysql, RefreshDatabase {
        GuardsSignatureAssetMysql::beforeRefreshingDatabase insteadof RefreshDatabase;
    }

    public function test_every_identity_field_is_immutable_through_model_query_builder_and_raw_sql(): void
    {
        $asset = $this->persistedSignatureAsset();
        $otherOwner = User::factory()->create();
        $before = $asset->fresh()->getRawOriginal();
        $changes = [
            'id' => 999999999,
            'public_id' => (string) Str::uuid(),
            'owner_id' => $otherOwner->id,
            'storage_key' => str_repeat('b', 64).'.png',
            'sha256' => str_repeat('b', 64),
            'size_bytes' => 124,
            'width' => 13,
            'height' => 9,
            'mime_type' => 'image/jpeg',
            'normalization_version' => 'gd-png-v2',
            'created_at' => '2026-09-13 00:00:00.000000',
        ];
        $this->assertSame(SignatureAsset::IMMUTABLE_ATTRIBUTES, array_keys($changes));

        foreach ($changes as $column => $value) {
            try {
                $asset->fresh()->forceFill([$column => $value])->save();
                $this->fail('Model accepted immutable '.$column.'.');
            } catch (LogicException $exception) {
                $this->assertSame('Signature asset identity is immutable.', $exception->getMessage());
            }

            foreach ([
                fn () => DB::table('signature_assets')->where('id', $asset->id)->update([$column => $value]),
                // Column names above are static fixtures, never input.
                fn () => DB::update("UPDATE signature_assets SET {$column} = ? WHERE id = ?", [$value, $asset->id]),
            ] as $write) {
                $this->assertDatabaseRejects($write, 'Signature asset identity and retirement are immutable');
            }
            $this->assertSame($before, $asset->fresh()->getRawOriginal());
        }
    }

    public function test_case_only_identity_changes_and_combined_retirement_identity_rewrite_are_rejected(): void
    {
        $asset = $this->persistedSignatureAsset(overrides: [
            'public_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'storage_key' => str_repeat('a', 64).'.png',
        ]);
        $before = $asset->fresh()->getRawOriginal();

        foreach (['public_id', 'storage_key', 'sha256', 'mime_type', 'normalization_version'] as $column) {
            $value = strtoupper($asset->getRawOriginal($column));
            $this->assertDatabaseRejects(fn () => DB::table('signature_assets')->where('id', $asset->id)->update([$column => $value]));
        }
        $this->assertDatabaseRejects(fn () => DB::table('signature_assets')->where('id', $asset->id)
            ->update($this->retirementValues($asset) + ['sha256' => str_repeat('c', 64)]));
        $this->assertSame($before, $asset->fresh()->getRawOriginal());
    }

    public function test_model_and_database_forbid_delete_replace_and_mutating_upsert(): void
    {
        $asset = $this->persistedSignatureAsset();
        $before = $asset->fresh()->getRawOriginal();
        try {
            $asset->delete();
            $this->fail('Model accepted deletion.');
        } catch (LogicException $exception) {
            $this->assertSame('Signature assets cannot be deleted.', $exception->getMessage());
        }
        $this->assertDatabaseRejects(fn () => DB::table('signature_assets')->where('id', $asset->id)->delete(), 'Signature assets cannot be deleted');

        foreach (['id', 'public_id', 'storage_key'] as $collision) {
            $replacement = $this->signatureAssetRow($asset->owner, [$collision => $asset->getRawOriginal($collision)]);
            $columns = implode(', ', array_keys($replacement));
            $placeholders = implode(', ', array_fill(0, count($replacement), '?'));
            $operation = DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE';
            $this->assertDatabaseRejects(fn () => DB::insert("{$operation} INTO signature_assets ({$columns}) VALUES ({$placeholders})", array_values($replacement)));
            $this->assertSame($before, $asset->fresh()->getRawOriginal());
        }

        $replacement = $this->signatureAssetRow($asset->owner, ['public_id' => $asset->public_id, 'sha256' => str_repeat('b', 64)]);
        $this->assertDatabaseRejects(fn () => DB::table('signature_assets')->upsert([$replacement], ['public_id'], ['sha256', 'storage_key']));
        $this->assertSame($before, $asset->fresh()->getRawOriginal());
        $this->assertDatabaseCount('signature_assets', 1);
    }

    public function test_retirement_requires_owner_metadata_and_is_permanent_including_reason_and_timestamps(): void
    {
        $asset = $this->persistedSignatureAsset();
        $before = $asset->fresh()->getRawOriginal();
        $retirement = $this->retirementValues($asset, str_repeat('ก', 500));

        foreach ([
            ['retired_at' => null],
            ['retired_by' => null],
            ['retired_by' => User::factory()->create()->id],
            ['retirement_reason' => str_repeat('ก', 501)],
        ] as $overrides) {
            $this->assertDatabaseRejects(fn () => DB::table('signature_assets')->where('id', $asset->id)->update(array_replace($retirement, $overrides)));
            $this->assertSame($before, $asset->fresh()->getRawOriginal());
        }

        DB::table('signature_assets')->where('id', $asset->id)->update($retirement);
        $retired = $asset->fresh()->getRawOriginal();
        $this->assertFalse($asset->fresh()->isEligibleForSigning());

        foreach ([
            ['status' => 'active', 'retired_at' => null, 'retired_by' => null, 'retirement_reason' => null],
            ['retired_at' => '2026-09-15 00:00:00.000000'],
            ['retired_by' => null],
            ['retirement_reason' => null],
            ['retirement_reason' => 'changed'],
            ['updated_at' => '2026-09-15 00:00:00.000000'],
        ] as $change) {
            $this->assertDatabaseRejects(fn () => DB::table('signature_assets')->where('id', $asset->id)->update($change), 'Signature asset identity and retirement are immutable');
            try {
                $asset->fresh()->forceFill($change)->save();
                $this->fail('Model accepted a second retirement transition.');
            } catch (LogicException $exception) {
                $this->assertSame('Signature assets permit only one retirement transition.', $exception->getMessage());
            }
            $this->assertSame($retired, $asset->fresh()->getRawOriginal());
        }

        DB::table('signature_assets')->where('id', $asset->id)->update($retirement);
        $this->assertSame($retired, $asset->fresh()->getRawOriginal());
    }

    public function test_initial_retired_rows_and_active_metadata_mutations_are_refused(): void
    {
        $owner = User::factory()->create();
        try {
            $this->persistedSignatureAsset($owner, ['status' => 'retired', 'retired_by' => $owner->id, 'retired_at' => now()]);
            $this->fail('Model accepted initially retired asset.');
        } catch (LogicException $exception) {
            $this->assertSame('Signature assets must be created active.', $exception->getMessage());
        }

        $asset = $this->persistedSignatureAsset($owner);
        foreach ([
            ['retired_at' => now()->format('Y-m-d H:i:s.u')],
            ['retired_by' => $owner->id],
            ['retirement_reason' => 'changed'],
            ['updated_at' => '2026-09-15 00:00:00.000000'],
        ] as $change) {
            $this->assertDatabaseRejects(fn () => DB::table('signature_assets')->where('id', $asset->id)->update($change), 'Signature asset identity and retirement are immutable');
        }
        $this->assertSame('active', $asset->fresh()->status);
    }

    private function assertDatabaseRejects(callable $write, ?string $message = null): void
    {
        try {
            $write();
            $this->fail('Database accepted protected signature asset mutation.');
        } catch (QueryException $exception) {
            if ($message !== null) {
                $this->assertStringContainsString($message, $exception->errorInfo[2]);
            }
        }
    }
}
