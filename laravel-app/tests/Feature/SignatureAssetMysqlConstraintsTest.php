<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsSignatureAssetRows;
use Tests\Feature\Concerns\GuardsSignatureAssetMysql;
use Tests\TestCase;

class SignatureAssetMysqlConstraintsTest extends TestCase
{
    use BuildsSignatureAssetRows;
    use GuardsSignatureAssetMysql;
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the guarded disposable MySQL 8.4.11 instance.');
        }
        $this->guardSignatureAssetDatabase();
    }

    public function test_every_check_is_enforced_and_emits_its_exact_mysql_constraint_error(): void
    {
        $checks = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())->where('table_name', 'signature_assets')
            ->where('constraint_type', 'CHECK')->orderBy('constraint_name')
            ->get(['enforced as check_enforced', 'constraint_name as check_name'])
            ->pluck('check_enforced', 'check_name')->all();
        $expected = [];
        foreach (['dimensions', 'mime', 'normalization', 'public_id', 'retirement', 'sha256', 'size', 'storage_key'] as $suffix) {
            $expected['signature_assets_'.$suffix.'_check'] = 'YES';
        }
        $this->assertSame($expected, $checks);

        $row = $this->signatureAssetRow();
        foreach ([
            ['public_id', ['public_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaZ']],
            ['storage_key', ['storage_key' => str_repeat('A', 64).'.png']],
            ['sha256', ['sha256' => str_repeat('A', 64)]],
            ['size', ['size_bytes' => 0]],
            ['size', ['size_bytes' => 8388609]],
            ['dimensions', ['width' => 2048, 'height' => 513]],
            ['dimensions', ['width' => 2049]],
            ['dimensions', ['height' => 1025]],
            ['mime', ['mime_type' => 'IMAGE/PNG']],
            ['mime', ['mime_type' => 'image/png ']],
            ['normalization', ['normalization_version' => 'GD-PNG-V1']],
            ['normalization', ['normalization_version' => 'gd-png-v1 ']],
            ['retirement', ['retirement_reason' => 'reason without retirement']],
            ['retirement', ['retired_by' => $row['owner_id']]],
        ] as [$constraint, $change]) {
            $failure = $this->queryFailure(fn () => DB::table('signature_assets')->insert(array_replace($row, $change)));
            $this->assertSame(3819, (int) $failure->errorInfo[1]);
            $this->assertSame("Check constraint 'signature_assets_{$constraint}_check' is violated.", $failure->errorInfo[2]);
            $this->assertDatabaseCount('signature_assets', 0);
        }

        $asset = $this->persistedSignatureAsset();
        $failure = $this->queryFailure(fn () => DB::table('signature_assets')->where('id', $asset->id)
            ->update($this->retirementValues($asset, str_repeat('ก', 501))));
        $this->assertSame(3819, (int) $failure->errorInfo[1]);
        $this->assertSame("Check constraint 'signature_assets_retirement_check' is violated.", $failure->errorInfo[2]);
        $this->assertSame('active', $asset->fresh()->status);
    }

    public function test_real_foreign_keys_unique_errors_and_trigger_sqlstates_are_distinct(): void
    {
        $owner = User::factory()->create();
        $asset = $this->persistedSignatureAsset($owner);
        $before = $asset->getRawOriginal();
        $orphan = $this->queryFailure(fn () => DB::table('signature_assets')->insert($this->signatureAssetRow($owner, ['owner_id' => 999999999])));
        $this->assertSame('23000', $orphan->errorInfo[0]);
        $this->assertSame(1452, (int) $orphan->errorInfo[1]);
        $this->assertStringContainsString('CONSTRAINT `signature_assets_owner_fk` FOREIGN KEY (`owner_id`)', $orphan->errorInfo[2]);

        foreach (['delete', 'update'] as $operation) {
            $failure = $this->queryFailure(function () use ($owner, $operation): void {
                $query = DB::table('users')->where('id', $owner->id);
                $operation === 'delete' ? $query->delete() : $query->update(['id' => 999999999]);
            });
            $this->assertSame('23000', $failure->errorInfo[0]);
            $this->assertSame(1451, (int) $failure->errorInfo[1]);
            $this->assertStringContainsString('CONSTRAINT `signature_assets_owner_fk` FOREIGN KEY (`owner_id`)', $failure->errorInfo[2]);
        }

        foreach (['public_id', 'storage_key'] as $column) {
            $failure = $this->queryFailure(fn () => DB::table('signature_assets')->insert($this->signatureAssetRow($owner, [$column => $asset->{$column}])));
            $this->assertSame('23000', $failure->errorInfo[0]);
            $this->assertSame(1062, (int) $failure->errorInfo[1]);
            $this->assertStringEndsWith("for key 'signature_assets.signature_assets_{$column}_unique'", $failure->errorInfo[2]);
        }

        foreach ([
            [fn () => DB::table('signature_assets')->where('id', $asset->id)->update(['sha256' => str_repeat('b', 64)]), 'Signature asset identity and retirement are immutable'],
            [fn () => DB::table('signature_assets')->where('id', $asset->id)->delete(), 'Signature assets cannot be deleted'],
            [fn () => DB::table('signature_assets')->insert($this->signatureAssetRow($owner, ['status' => 'retired', 'retired_by' => $owner->id, 'retired_at' => '2026-09-14 00:00:00.000000'])), 'Signature assets must be created active'],
        ] as [$operation, $message]) {
            $failure = $this->queryFailure($operation);
            $this->assertSame('45000', $failure->errorInfo[0]);
            $this->assertSame(1644, (int) $failure->errorInfo[1]);
            $this->assertSame($message, $failure->errorInfo[2]);
        }
        $after = $asset->fresh()->getRawOriginal();

ksort($before);
ksort($after);

$this->assertSame($before, $after);
        $this->assertDatabaseCount('signature_assets', 1);
    }

    public function test_bounded_reason_accepts_five_hundred_multibyte_characters_and_retired_owner_is_restricted(): void
    {
        $asset = $this->persistedSignatureAsset();
        DB::table('signature_assets')->where('id', $asset->id)->update($this->retirementValues($asset, str_repeat('ก', 500)));
        $asset->refresh();
        $this->assertSame(500, mb_strlen($asset->retirement_reason));
        $this->assertSame($asset->owner_id, $asset->retired_by);
        $failure = $this->queryFailure(fn () => DB::table('users')->where('id', $asset->owner_id)->delete());
        $this->assertSame(1451, (int) $failure->errorInfo[1]);

        $keys = DB::table('information_schema.referential_constraints')->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', 'signature_assets')->orderBy('constraint_name')
            ->get(['constraint_name as key_name', 'delete_rule as delete_rule_value', 'update_rule as update_rule_value']);
        $this->assertSame(['signature_assets_owner_fk', 'signature_assets_retired_by_fk'], $keys->pluck('key_name')->all());
        $this->assertSame(['RESTRICT', 'RESTRICT'], $keys->pluck('delete_rule_value')->all());
        $this->assertSame(['RESTRICT', 'RESTRICT'], $keys->pluck('update_rule_value')->all());
    }

    private function queryFailure(callable $query): QueryException
    {
        try {
            $query();
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Expected a real MySQL signature asset constraint violation.');
    }
}
