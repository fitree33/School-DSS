<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function toCents(int|float|string|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $normalized = is_float($amount)
            ? number_format($amount, 2, '.', '')
            : trim((string) $amount);

        if (! preg_match(
            '/^([+-]?)(?:(\d+)(?:\.(\d{0,2}))?|\.(\d{1,2}))$/',
            $normalized,
            $matches,
            PREG_UNMATCHED_AS_NULL,
        )) {
            throw new InvalidArgumentException("Invalid monetary amount [{$normalized}].");
        }

        $whole = (int) ($matches[2] ?? 0);
        $fraction = str_pad($matches[3] ?? $matches[4] ?? '', 2, '0');
        $cents = ($whole * 100) + (int) $fraction;

        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public static function percentage(int $partCents, int $budgetCents): ?float
    {
        if ($budgetCents === 0) {
            return $partCents === 0 ? 0.0 : null;
        }

        return round(($partCents / $budgetCents) * 100, 2);
    }
}
