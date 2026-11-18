<?php

namespace App\Billing;

/** The up-front price range for a generation, in nanodollars, plus the token sets it came from. */
final class Estimate
{
    /**
     * @param  array<string, int>  $minTokens
     * @param  array<string, int>  $maxTokens
     */
    public function __construct(
        public readonly array $minTokens,
        public readonly array $maxTokens,
        public readonly int $minProviderCostNano,
        public readonly int $maxProviderCostNano,
        public readonly int $minChargeNano,
        public readonly int $maxChargeNano,
    ) {}
}
