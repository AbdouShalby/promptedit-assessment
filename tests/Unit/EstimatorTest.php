<?php

namespace Tests\Unit;

use App\Billing\Estimate;
use App\Billing\Estimator;
use App\Pixelforge\Pricing;
use PHPUnit\Framework\TestCase;

class EstimatorTest extends TestCase
{
    private function pricing(): Pricing
    {
        return Pricing::fromApi(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true));
    }

    private function estimate(string $prompt, string $size = '1024x1024', string $quality = 'medium', int $references = 0): Estimate
    {
        return Estimator::estimate($prompt, $size, $quality, $references, $this->pricing(), 2500);
    }

    public function test_pricing_is_read_from_the_fixture_not_hardcoded(): void
    {
        $pricing = $this->pricing();

        $this->assertSame(['low', 'medium', 'high'], $pricing->qualities());
        $this->assertSame(['1024x1024', '1536x1024', '1024x1536'], $pricing->sizes());
        $this->assertSame(1500, $pricing->textTokenVarianceBps);
        $this->assertSame(4, $pricing->charactersPerTextToken);
        $this->assertSame(765, $pricing->tokensPerReferenceImage);
        $this->assertSame('12.50', $pricing->rates['reasoning_tokens']);
    }

    /** The maximum of the documented worked example: 400 chars, high, 1536x1024, 2 reference images. */
    public function test_the_worked_example_is_the_maximum_estimate(): void
    {
        $estimate = $this->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(115, $estimate->maxTokens['input_text_tokens']);
        $this->assertSame(85, $estimate->minTokens['input_text_tokens']);
        $this->assertSame(1530, $estimate->maxTokens['input_image_tokens']);
        $this->assertSame(6450, $estimate->maxTokens['output_tokens']);
        $this->assertSame(8000, $estimate->maxTokens['reasoning_tokens']);
        $this->assertSame(373_875_000, $estimate->maxProviderCostNano);
        $this->assertSame(467_343_750, $estimate->maxChargeNano);
    }

    public function test_minimum_estimate_uses_the_low_ends(): void
    {
        $estimate = $this->estimate(str_repeat('a', 400), '1536x1024', 'high', 2);

        // 85x5 + 1530x10 + 6450x40 + 1000x12.5 = 286225 micro-dollars
        $this->assertSame(286_225_000, $estimate->minProviderCostNano);
        $this->assertSame(357_781_250, $estimate->minChargeNano);
    }

    public function test_no_reference_images_means_no_image_tokens(): void
    {
        $this->assertSame(0, $this->estimate('a red fox')->maxTokens['input_image_tokens']);
        $this->assertSame(765 * 4, $this->estimate('a red fox', references: 4)->maxTokens['input_image_tokens']);
    }

    public function test_text_tokens_are_characters_divided_by_four_rounded_up(): void
    {
        // base 1 -> min floor(0.85) = 0, max ceil(1.15) = 2
        $this->assertSame(0, $this->estimate('a')->minTokens['input_text_tokens']);
        $this->assertSame(2, $this->estimate('a')->maxTokens['input_text_tokens']);

        // 5 chars -> base 2 -> max ceil(2.3) = 3
        $this->assertSame(3, $this->estimate('abcde')->maxTokens['input_text_tokens']);

        // base 20 -> exactly 17 and 23: integer maths must not add a phantom token
        $this->assertSame(17, $this->estimate(str_repeat('a', 80))->minTokens['input_text_tokens']);
        $this->assertSame(23, $this->estimate(str_repeat('a', 80))->maxTokens['input_text_tokens']);
    }

    public function test_characters_are_unicode_code_points_not_bytes(): void
    {
        $arabic = str_repeat('ق', 400);     // 2 bytes each in UTF-8
        $emoji = str_repeat('🦊', 400);     // 4 bytes each in UTF-8

        $this->assertSame(115, $this->estimate($arabic)->maxTokens['input_text_tokens']);
        $this->assertSame(115, $this->estimate($emoji)->maxTokens['input_text_tokens']);
    }

    public function test_every_size_and_quality_uses_the_output_tokens_from_the_price_list(): void
    {
        $pricing = $this->pricing();

        foreach ($pricing->qualities() as $quality) {
            foreach ($pricing->sizes() as $size) {
                $estimate = $this->estimate('a red fox', $size, $quality);

                $this->assertSame($pricing->outputTokens[$quality][$size], $estimate->minTokens['output_tokens']);
                $this->assertSame($pricing->outputTokens[$quality][$size], $estimate->maxTokens['output_tokens']);
                $this->assertSame($pricing->reasoningTokens[$quality]['min'], $estimate->minTokens['reasoning_tokens']);
                $this->assertSame($pricing->reasoningTokens[$quality]['max'], $estimate->maxTokens['reasoning_tokens']);
                $this->assertLessThanOrEqual($estimate->maxChargeNano, $estimate->minChargeNano);
            }
        }
    }
}
