<?php

namespace App\Billing;

use App\Support\Nano;
use InvalidArgumentException;

/**
 * The only place where tokens become money.
 *
 * Pixelforge never returns a price: cost = sum(tokens x rate), with rates in
 * USD per 1,000,000 tokens. Everything here is integer maths in nanodollars.
 */
final class CostCalculator
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    private const TOKENS_PER_RATE_UNIT = 1_000_000;

    /**
     * @param  array<string, int>  $usage  token counts keyed by token type
     * @param  array<string, string>  $rates  USD per 1M tokens as decimal strings, same keys
     * @return int exact provider cost in nanodollars
     *
     * @throws InvalidArgumentException if a token count or rate is missing or not a whole number
     * @throws InexactCostException if the result is not a whole nanodollar (never rounds)
     */
    public static function providerCost(array $usage, array $rates): int
    {
        $nanoTimesMillion = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $tokens = $usage[$type] ?? null;

            // A missing or odd count must stop us: guessing would understate the cost silently.
            if (! is_int($tokens) || $tokens < 0 || ! isset($rates[$type])) {
                throw new InvalidArgumentException("Missing or invalid token count or rate for [{$type}].");
            }

            $nanoTimesMillion += $tokens * Nano::fromDecimal((string) $rates[$type]);
        }

        if ($nanoTimesMillion % self::TOKENS_PER_RATE_UNIT !== 0) {
            throw new InexactCostException('Usage x rates is not a whole number of nanodollars.');
        }

        return intdiv($nanoTimesMillion, self::TOKENS_PER_RATE_UNIT);
    }

    /**
     * What the user pays: cost plus markup (in basis points, 2500 = 25%),
     * rounded UP to the next whole nanodollar so profit is never negative.
     */
    public static function charge(int $providerCostNano, int $markupBps): int
    {
        return intdiv($providerCostNano * (10_000 + $markupBps) + 9_999, 10_000);
    }
}
