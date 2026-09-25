<?php

namespace Tests\Unit\Signatures;

use App\Services\Signatures\SignatureReconciliationPolicy as Policy;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class SignatureAssetReconciliationStateTest extends TestCase
{
    #[DataProvider('inspectionStates')]
    public function test_only_safe_orphan_mutates(string $inspection, int $expectedDeletes, string $expectedState): void
    {
        $world = $this->world();
        $world->inspection = $inspection;

        $result = $this->apply($world);

        $this->assertSame($expectedDeletes, $world->unlinks);
        $this->assertSame($expectedState, $result['state']);
        $this->assertSame($expectedDeletes === 1, $result['removed']);
        $this->assertFalse($world->owned);
        $this->assertFalse($world->databaseProof);
        $this->assertSame($expectedDeletes === 1 ? 1 : 0, $world->resolutions);
    }

    public static function inspectionStates(): iterable
    {
        foreach ([Policy::REFERENCED, Policy::ACTIVE_OWNED, Policy::UNRESOLVED, Policy::CORRUPT, Policy::DISAPPEARED, Policy::PROTECTED_RECENT] as $state) {
            yield $state => [$state, 0, $state];
        }
        yield 'aged absence needs live database proof' => [Policy::CANDIDATE, 1, Policy::SAFE_ORPHAN];
        yield 'serialized safe state cannot authorize' => [Policy::SAFE_ORPHAN, 0, Policy::UNRESOLVED];
    }

    public function test_two_applies_same_candidate_delete_once(): void
    {
        $world = $this->world();
        $world->events = ['A.scan.K', 'B.scan.K'];
        $loser = null;

        $winner = $this->apply($world, function () use ($world, &$loser): void {
            // A owns K before B reaches acquire. B must not inspect, resolve, or mutate.
            $loser = $this->apply($world);
            $this->assertSame(Policy::ACTIVE_OWNED, $loser['state']);
            $this->assertFalse($loser['removed']);
            $this->assertSame(0, $world->unlinks);
            $this->assertTrue($world->owned);
        });
        $retry = $this->apply($world);

        $this->assertTrue($winner['removed']);
        $this->assertSame(Policy::DISAPPEARED, $retry['state']);
        $this->assertFalse($retry['removed']);
        $this->assertSame(1, $world->unlinks);
        $this->assertSame(1, $world->resolutions);
        $this->assertSame([
            'A.scan.K', 'B.scan.K', 'acquire', 'inspect', 'resolve', 'busy',
            'mutate', 'release', 'acquire', 'inspect', 'release',
        ], $world->events);
    }

    public function test_scan_result_never_authorizes_deletion(): void
    {
        $world = $this->world();
        $world->events[] = 'scan.absent';

        $result = $this->apply($world, function () use ($world): void {
            $world->referenced = true;
            $world->events[] = 'reference.committed';
        });

        $this->assertSame(Policy::REFERENCED, $result['state']);
        $this->assertFalse($result['removed']);
        $this->assertSame(0, $world->unlinks);
        $this->assertTrue($world->file);
        $this->assertFalse($world->owned);
    }

    public function test_disappearance_after_scan_is_harmless(): void
    {
        $world = $this->world();
        $world->events[] = 'scan.exists';
        $world->file = false;

        $result = $this->apply($world);

        $this->assertSame(Policy::DISAPPEARED, $result['state']);
        $this->assertSame(0, $world->resolutions);
        $this->assertSame(0, $world->unlinks);
        $this->assertFalse($result['partial_error']);
        $this->assertFalse($world->owned);
    }

    #[DataProvider('uncertainties')]
    public function test_uncertainty_retains(string $reason, bool $throws): void
    {
        $world = $this->world();
        $result = $this->apply($world, null, function (Closure $whenAbsent) use ($reason, $throws): array {
            if ($throws) {
                throw new RuntimeException($reason);
            }

            return ['state' => Policy::UNRESOLVED, 'reason' => $reason];
        });

        $this->assertSame(Policy::UNRESOLVED, $result['state']);
        $this->assertSame(0, $world->unlinks);
        $this->assertTrue($world->file);
        $this->assertTrue($world->receipt);
        $this->assertFalse($result['removed']);
        $this->assertFalse($result['partial_error']);
        $this->assertFalse($world->owned);
    }

    public static function uncertainties(): iterable
    {
        foreach (['timeout', 'deadlock', 'query-error', 'connection-uncertainty', 'ambient-transaction'] as $reason) {
            yield $reason => [$reason, false];
        }
        yield 'unexpected database exception' => ['query-error', true];
    }

    #[DataProvider('dryRunStates')]
    public function test_lock_free_dry_run_never_certifies_orphan(string $observed, string $expected): void
    {
        // Observe accepts data only: no acquire, query, lock, or mutation collaborator is invoked.
        $result = (new Policy)->observe([
            'state' => $observed,
            'removed' => true,
            'partial_error' => true,
        ]);

        $this->assertSame($expected, $result['state']);
        $this->assertNotSame(Policy::SAFE_ORPHAN, $result['state']);
        $this->assertFalse($result['removed']);
        $this->assertFalse($result['partial_error']);
    }

    public static function dryRunStates(): iterable
    {
        foreach ([Policy::REFERENCED, Policy::UNRESOLVED, Policy::CANDIDATE, Policy::CORRUPT, Policy::DISAPPEARED, Policy::PROTECTED_RECENT] as $state) {
            yield $state => [$state, $state];
        }
        yield 'ownership cannot be inferred' => [Policy::ACTIVE_OWNED, Policy::UNRESOLVED];
        yield 'absence is only a candidate' => [Policy::SAFE_ORPHAN, Policy::CANDIDATE];
    }

    #[DataProvider('receiptAges')]
    public function test_recent_receipt_semantics(int $age, int $minimumAge, string $expected): void
    {
        $now = 2000000000;
        $state = (new Policy)->ageState($now - $age, $now, $minimumAge);

        $this->assertSame($expected, $state);
        $this->assertNotSame(Policy::ACTIVE_OWNED, $state);
        $this->assertNotSame(Policy::SAFE_ORPHAN, $state);
    }

    public static function receiptAges(): iterable
    {
        yield 'future receipt remains protected' => [-1, 3600, Policy::PROTECTED_RECENT];
        yield 'recent released receipt is not active ownership' => [3599, 3600, Policy::PROTECTED_RECENT];
        yield 'one hour boundary' => [3600, 3600, Policy::CANDIDATE];
        yield 'configured value cannot shorten one hour' => [3599, 1, Policy::PROTECTED_RECENT];
        yield 'longer configured minimum is honored' => [7199, 7200, Policy::PROTECTED_RECENT];
        yield 'longer minimum boundary' => [7200, 7200, Policy::CANDIDATE];
        yield 'negative minimum cannot shorten one hour' => [3599, -1, Policy::PROTECTED_RECENT];
    }

    public function test_invalid_receipt_time_is_corrupt(): void
    {
        $this->assertSame(Policy::CORRUPT, (new Policy)->ageState(0, 2000000000));
    }

    public function test_partial_cleanup_is_not_reported_as_retained(): void
    {
        $world = $this->world();
        $result = $this->apply($world, null, null, function () use ($world): array {
            $this->assertTrue($world->owned);
            $this->assertTrue($world->databaseProof);
            $world->file = false;
            $world->unlinks++;

            return ['state' => Policy::SAFE_ORPHAN, 'removed' => true, 'partial_error' => true];
        });

        $this->assertTrue($result['removed']);
        $this->assertTrue($result['partial_error']);
        $this->assertFalse($world->file);
        $this->assertTrue($world->receipt);
        $this->assertFalse($world->owned);
    }

    public function test_exception_after_mutation_preserves_partial_cleanup_evidence(): void
    {
        $world = $this->world();
        $result = $this->apply($world, null, function (Closure $whenAbsent) use ($world): array {
            $world->databaseProof = true;
            try {
                $whenAbsent();
                throw new RuntimeException('Database lock release failed after unlink.');
            } finally {
                $world->databaseProof = false;
            }
        });

        $this->assertSame(Policy::UNRESOLVED, $result['state']);
        $this->assertTrue($result['removed']);
        $this->assertTrue($result['partial_error']);
        $this->assertSame(1, $world->unlinks);
        $this->assertFalse($world->owned);
    }

    public function test_saved_absence_callback_cannot_mutate_after_guards_are_released(): void
    {
        $world = $this->world();
        $saved = null;
        $result = $this->apply($world, null, function (Closure $whenAbsent) use (&$saved): array {
            $saved = $whenAbsent;

            return ['state' => Policy::UNRESOLVED];
        });
        $late = $saved();

        $this->assertSame(Policy::UNRESOLVED, $result['state']);
        $this->assertSame(Policy::UNRESOLVED, $late['state']);
        $this->assertSame(0, $world->unlinks);
        $this->assertTrue($world->file);
        $this->assertFalse($world->owned);
    }

    public function test_absence_callback_cannot_replay_mutation(): void
    {
        $world = $this->world();
        $result = $this->apply($world, null, function (Closure $whenAbsent) use ($world): array {
            $world->databaseProof = true;
            try {
                $first = $whenAbsent();
                $replay = $whenAbsent();
                $this->assertSame(Policy::UNRESOLVED, $replay['state']);

                return $first;
            } finally {
                $world->databaseProof = false;
            }
        });

        $this->assertTrue($result['removed']);
        $this->assertSame(1, $world->unlinks);
    }

    public function test_resolver_cannot_return_serialized_safe_state_without_live_callback(): void
    {
        $world = $this->world();
        $result = $this->apply($world, null, fn (): array => ['state' => Policy::SAFE_ORPHAN, 'removed' => true]);

        $this->assertSame(Policy::UNRESOLVED, $result['state']);
        $this->assertFalse($result['removed']);
        $this->assertSame(0, $world->unlinks);
        $this->assertTrue($world->file);
    }

    private function world(): stdClass
    {
        return (object) [
            'owned' => false,
            'file' => true,
            'receipt' => true,
            'referenced' => false,
            'databaseProof' => false,
            'inspection' => Policy::CANDIDATE,
            'resolutions' => 0,
            'unlinks' => 0,
            'events' => [],
        ];
    }

    /** Deterministic collaborators for the production policy, with explicit ownership/proof state. */
    private function apply(stdClass $world, ?Closure $beforeProbe = null, ?Closure $resolver = null, ?Closure $mutation = null): array
    {
        return (new Policy)->apply(
            function () use ($world): ?string {
                if ($world->owned) {
                    $world->events[] = 'busy';

                    return Policy::ACTIVE_OWNED;
                }
                $world->owned = true;
                $world->events[] = 'acquire';

                return null;
            },
            function () use ($world): string {
                $world->events[] = 'inspect';

                return $world->file ? $world->inspection : Policy::DISAPPEARED;
            },
            $resolver ?? function (Closure $whenAbsent) use ($world, $beforeProbe): array {
                $world->resolutions++;
                $world->events[] = 'resolve';
                $beforeProbe?->__invoke();
                if ($world->referenced) {
                    return ['state' => Policy::REFERENCED];
                }
                $world->databaseProof = true;
                try {
                    return $whenAbsent();
                } finally {
                    $world->databaseProof = false;
                }
            },
            $mutation ?? function () use ($world): array {
                if (! $world->owned || ! $world->databaseProof) {
                    throw new RuntimeException('Mutation attempted outside live guards.');
                }
                $world->events[] = 'mutate';
                if (! $world->file) {
                    return ['state' => Policy::DISAPPEARED];
                }
                $world->file = false;
                $world->receipt = false;
                $world->unlinks++;

                return ['state' => Policy::SAFE_ORPHAN, 'removed' => true];
            },
            function () use ($world): void {
                $world->events[] = 'release';
                $world->owned = false;
            },
        );
    }
}
