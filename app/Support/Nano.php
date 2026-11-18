<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money as integer nanodollars (1 USD = 1,000,000,000 nano).
 *
 * Pixelforge sends money as decimal strings with up to 9 decimals, so every
 * amount is a whole number of nanodollars. Parsing is string-only: a float
 * never touches a money value.
 */
final class Nano
{
    public const PER_DOLLAR = 1_000_000_000;

    private const DECIMALS = 9;

    /** "0.046125000" -> 46125000. Rejects anything that is not a plain non-negative decimal. */
    public static function fromDecimal(string $decimal): int
    {
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,9}))?$/', $decimal, $parts)) {
            throw new InvalidArgumentException("Not a decimal amount with at most 9 decimals: [{$decimal}]");
        }

        $fraction = str_pad($parts[2] ?? '', self::DECIMALS, '0');

        return (int) $parts[1] * self::PER_DOLLAR + (int) $fraction;
    }

    /** 373875000 -> "0.373875000" (the exact form Pixelforge uses, for comparisons). */
    public static function toDecimal(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $nano = abs($nano);

        return sprintf('%s%d.%09d', $sign, intdiv($nano, self::PER_DOLLAR), $nano % self::PER_DOLLAR);
    }

    /**
     * Display for amounts the user pays: 4 decimals, rounded UP,
     * so the screen never understates a cost. Stored values are untouched.
     */
    public static function formatCost(int $nano): string
    {
        return self::format(intdiv($nano + 99_999, 100_000));
    }

    /** Display for funds the user has: 4 decimals, rounded DOWN, never overstating a balance. */
    public static function formatBalance(int $nano): string
    {
        // intdiv truncates toward zero; floor explicitly so a negative balance is not overstated either.
        $units = intdiv($nano, 100_000);
        if ($nano < 0 && $nano % 100_000 !== 0) {
            $units--;
        }

        return self::format($units);
    }

    /** $units is ten-thousandths of a dollar. */
    private static function format(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);

        return sprintf('%s$%d.%04d', $sign, intdiv($units, 10_000), $units % 10_000);
    }
}
