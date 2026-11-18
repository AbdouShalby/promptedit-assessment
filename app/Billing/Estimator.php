<?php

namespace App\Billing;

use App\Pixelforge\Pricing;

/**
 * Price range before submitting. It is a range because two token counts are
 * only known at completion: text tokens (+-15%) and reasoning tokens (a wide
 * range per quality). Image and output tokens are exact.
 */
final class Estimator
{
    public static function estimate(
        string $prompt,
        string $size,
        string $quality,
        int $referenceImageCount,
        Pricing $pricing,
        int $markupBps,
    ): Estimate {
        // Unicode code points of the exact string we will send.
        $characters = mb_strlen($prompt, 'UTF-8');
        $perToken = $pricing->charactersPerTextToken;
        $baseTextTokens = intdiv($characters + $perToken - 1, $perToken);   // ceil(chars / 4)

        // Integer basis-point maths; ceil($base * 1.15) in floats can add a phantom token.
        $variance = $pricing->textTokenVarianceBps;
        $minTextTokens = intdiv($baseTextTokens * (10_000 - $variance), 10_000);            // floor
        $maxTextTokens = intdiv($baseTextTokens * (10_000 + $variance) + 9_999, 10_000);    // ceil

        $exact = [
            'input_image_tokens' => $pricing->tokensPerReferenceImage * $referenceImageCount,
            'output_tokens' => (int) $pricing->outputTokens[$quality][$size],
        ];

        $minTokens = $exact + [
            'input_text_tokens' => $minTextTokens,
            'reasoning_tokens' => (int) $pricing->reasoningTokens[$quality]['min'],
        ];
        $maxTokens = $exact + [
            'input_text_tokens' => $maxTextTokens,
            'reasoning_tokens' => (int) $pricing->reasoningTokens[$quality]['max'],
        ];

        $minCost = CostCalculator::providerCost($minTokens, $pricing->rates);
        $maxCost = CostCalculator::providerCost($maxTokens, $pricing->rates);

        return new Estimate(
            minTokens: $minTokens,
            maxTokens: $maxTokens,
            minProviderCostNano: $minCost,
            maxProviderCostNano: $maxCost,
            minChargeNano: CostCalculator::charge($minCost, $markupBps),
            maxChargeNano: CostCalculator::charge($maxCost, $markupBps),
        );
    }
}
