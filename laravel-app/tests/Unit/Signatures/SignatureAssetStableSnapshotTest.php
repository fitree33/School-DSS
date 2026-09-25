<?php

namespace Tests\Unit\Signatures;

use App\Services\Signatures\PngStructureValidator;
use App\Services\Signatures\SignatureAssetStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SignatureAssetStableSnapshotTest extends TestCase
{
    public function test_read_access_allocation_hints_numeric_aliases_and_input_order_are_not_identity(): void
    {
        $initial = $this->stat();
        $fresh = array_reverse(array_replace($initial, [
            'atime' => 2000, 8 => 2000, 0 => 999, 'blocks' => 24, 'blksize' => 8192, 'rdev' => 3,
        ]), true);

        $this->assertTrue($this->same($initial, $fresh));
        $snapshot = $this->snapshot($fresh);
        $this->assertCount(9, $snapshot);
        foreach (['atime', 8, 0, 'blocks', 'blksize', 'rdev'] as $excluded) {
            $this->assertArrayNotHasKey($excluded, $snapshot);
        }
    }

    #[DataProvider('stableFields')]
    public function test_each_stable_field_change_rejects_the_snapshot(string $field): void
    {
        $initial = $this->stat();
        $fresh = $initial;
        $fresh[$field]++;

        $this->assertFalse($this->same($initial, $fresh));
    }

    #[DataProvider('stableFields')]
    public function test_matching_missing_fields_cannot_certify_consistency(string $field): void
    {
        $stat = $this->stat();
        unset($stat[$field]);

        $this->assertNull($this->snapshot($stat));
        $this->assertFalse($this->same($stat, $stat));
    }

    public static function stableFields(): array
    {
        return array_combine($fields = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'],
            array_map(static fn (string $field): array => [$field], $fields));
    }

    public function test_non_integer_metadata_is_not_coerced(): void
    {
        $stat = $this->stat();
        $stat['mtime'] = (string) $stat['mtime'];

        $this->assertNull($this->snapshot($stat));
        $this->assertFalse($this->same($stat, $stat));
    }

    public function test_zero_native_identifiers_remain_observations_not_identity_authorization(): void
    {
        // This private comparator certifies metadata consistency only. A caller
        // still needs path/lease/reference/content proofs; zeros prove no inode.
        $stat = array_replace($this->stat(), ['dev' => 0, 'ino' => 0]);
        $this->assertSame(0, $this->snapshot($stat)['dev']);
        $this->assertSame(0, $this->snapshot($stat)['ino']);
        $this->assertTrue($this->same($stat, $stat));
        $this->assertFalse($this->same($stat, array_replace($stat, ['size' => 73])));
        $this->assertFalse($this->same($stat, array_replace($stat, ['ino' => 4])));
    }

    public function test_signed_opaque_native_identifiers_are_not_discarded(): void
    {
        $stat = array_replace($this->stat(), ['dev' => -17, 'ino' => -29]);
        $this->assertTrue($this->same($stat, $stat));
        $this->assertFalse($this->same($stat, array_replace($stat, ['ino' => -30])));
    }

    private function stat(): array
    {
        return [
            'dev' => 11, 'ino' => 22, 'mode' => 0100600, 'nlink' => 1,
            'uid' => 1000, 'gid' => 1000, 'size' => 72, 'mtime' => 900, 'ctime' => 800,
            'atime' => 1000, 8 => 1000, 0 => 11, 'rdev' => 0, 'blocks' => 8, 'blksize' => 4096,
        ];
    }

    private function snapshot(array $stat): ?array
    {
        return (new ReflectionMethod(SignatureAssetStorage::class, 'stableFileSnapshot'))
            ->invoke(new SignatureAssetStorage(new PngStructureValidator), $stat);
    }

    private function same(array $first, array $second): bool
    {
        return (new ReflectionMethod(SignatureAssetStorage::class, 'sameCandidateIdentity'))
            ->invoke(new SignatureAssetStorage(new PngStructureValidator), $first, $second);
    }
}
