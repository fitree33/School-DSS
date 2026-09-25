<?php

namespace App\Services\Signatures;

use Closure;
use Throwable;

/** Candidate transitions; ownership and database proofs never escape as reusable capabilities. */
final class SignatureReconciliationPolicy
{
    public const REFERENCED = 'referenced';

    public const ACTIVE_OWNED = 'active-owned';

    public const UNRESOLVED = 'ambiguous/unresolved';

    public const CANDIDATE = 'aged-unreferenced-candidate';

    public const SAFE_ORPHAN = 'safe-orphan';

    public const CORRUPT = 'corrupt';

    public const DISAPPEARED = 'disappeared';

    public const PROTECTED_RECENT = 'protected-recent';

    /**
     * Acquire returns null only while this invocation owns the existing candidate lease.
     * Inspect must validate the current filesystem state, independently of the scan.
     * Resolve calls its argument only under a fresh, authoritative absence proof.
     * Mutate must revalidate once more before unlink, while both guards remain held.
     * Returned states are reports of this invocation, never deletion authorization.
     */
    public function apply(Closure $acquire, Closure $inspect, Closure $resolve, Closure $mutate, Closure $release): array
    {
        $acquired = false;
        $resolutionOpen = false;
        $invoked = false;
        $mutationAttempted = false;
        $mutationResult = null;
        $outcome = $this->outcome(self::UNRESOLVED);

        try {
            $ownership = $acquire();
            if ($ownership !== null) {
                $outcome = $this->retained($ownership);
            } else {
                $acquired = true;
                $outcome = $this->retained($inspect());
                if ($outcome['state'] === self::CANDIDATE) {
                    $resolutionOpen = true;
                    try {
                        $outcome = $this->outcome($resolve(function () use (&$resolutionOpen, &$invoked, &$mutationAttempted, &$mutationResult, $mutate): array {
                            if (! $resolutionOpen || $invoked) {
                                return $this->outcome(self::UNRESOLVED);
                            }
                            $invoked = true;

                            // Safe-orphan exists only on this synchronous path, under both guards.
                            $mutationAttempted = true;
                            $mutationResult = $this->outcome($mutate());

                            return $mutationResult;
                        }));
                    } finally {
                        $resolutionOpen = false;
                    }

                    if (! $invoked) {
                        $outcome = $this->retained($outcome);
                    } elseif ($mutationResult === null) {
                        // A callback may throw after unlink. Never describe that as a harmless retain.
                        $outcome['state'] = self::UNRESOLVED;
                        $outcome['partial_error'] = true;
                    } elseif ($mutationResult['removed']) {
                        $outcome['removed'] = true;
                        $outcome['partial_error'] = $outcome['partial_error']
                            || $mutationResult['partial_error']
                            || $outcome['state'] !== self::SAFE_ORPHAN;
                    } else {
                        $outcome['partial_error'] = $outcome['partial_error'] || $mutationResult['partial_error'];
                    }
                }
            }
        } catch (Throwable) {
            $outcome = $this->outcome([
                'state' => self::UNRESOLVED,
                'removed' => $mutationResult['removed'] ?? false,
                'partial_error' => $mutationAttempted,
            ]);
        } finally {
            $resolutionOpen = false;
            if ($acquired) {
                try {
                    $release();
                } catch (Throwable) {
                    $outcome['state'] = self::UNRESOLVED;
                    $outcome['partial_error'] = $outcome['partial_error'] || $mutationAttempted;
                }
            }
        }

        return $outcome;
    }

    /** Lock-free observations cannot certify ownership, absence, or a safe orphan. */
    public function observe(string|array $observation): array
    {
        $outcome = $this->outcome($observation);
        $outcome['state'] = match ($outcome['state']) {
            self::SAFE_ORPHAN => self::CANDIDATE,
            self::ACTIVE_OWNED => self::UNRESOLVED,
            default => $outcome['state'],
        };
        $outcome['removed'] = false;
        $outcome['partial_error'] = false;

        return $outcome;
    }

    public function ageState(int $createdAt, int $now, int $minimumAgeSeconds = 3600): string
    {
        if ($createdAt <= 0 || $now <= 0) {
            return self::CORRUPT;
        }

        return $now - $createdAt >= max(3600, $minimumAgeSeconds)
            ? self::CANDIDATE
            : self::PROTECTED_RECENT;
    }

    private function retained(string|array $value): array
    {
        $outcome = $this->outcome($value);
        if ($outcome['state'] === self::SAFE_ORPHAN) {
            $outcome['state'] = self::UNRESOLVED;
        }
        $outcome['removed'] = false;
        $outcome['partial_error'] = false;

        return $outcome;
    }

    private function outcome(string|array $value): array
    {
        $outcome = is_string($value) ? ['state' => $value] : $value;
        $outcome += [
            'state' => self::UNRESOLVED,
            'identity_mismatch' => false,
            'removed' => false,
            'partial_error' => false,
        ];
        if (! in_array($outcome['state'], [
            self::REFERENCED, self::ACTIVE_OWNED, self::UNRESOLVED, self::CANDIDATE,
            self::SAFE_ORPHAN, self::CORRUPT, self::DISAPPEARED, self::PROTECTED_RECENT,
        ], true)) {
            $outcome['state'] = self::UNRESOLVED;
        }

        return $outcome;
    }
}
