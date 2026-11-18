<?php

namespace Tests\Unit;

use App\Billing\CostCalculator;
use App\Billing\InexactCostException;
use App\Support\Nano;
use PHPUnit\Framework\TestCase;

class CostCalculatorTest extends TestCase
{
    /** @return array<string, string> the rates captured from the live GET /v1/pricing */
    private function rates(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true)['rates'];
    }

    /** @return array<string, int> */
    private function usage(int $text = 0, int $image = 0, int $output = 0, int $reasoning = 0): array
    {
        return [
            'input_text_tokens' => $text,
            'input_image_tokens' => $image,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
        ];
    }

    /** The worked example from the Pixelforge docs: high, 1536x1024, 400-char prompt, 2 reference images. */
    public function test_the_documented_worked_example_is_reproduced_exactly(): void
    {
        $cost = CostCalculator::providerCost($this->usage(115, 1530, 6450, 8000), $this->rates());

        $this->assertSame(373_875_000, $cost);
        $this->assertSame('0.373875000', Nano::toDecimal($cost));
    }

    public function test_each_component_of_the_worked_example(): void
    {
        $rates = $this->rates();

        $this->assertSame(575_000, CostCalculator::providerCost($this->usage(text: 115), $rates));            // $0.000575
        $this->assertSame(15_300_000, CostCalculator::providerCost($this->usage(image: 1530), $rates));       // $0.0153
        $this->assertSame(258_000_000, CostCalculator::providerCost($this->usage(output: 6450), $rates));     // $0.258
        $this->assertSame(100_000_000, CostCalculator::providerCost($this->usage(reasoning: 8000), $rates));  // $0.1
    }

    public function test_one_token_at_one_dollar_per_million_costs_one_millionth_of_a_dollar(): void
    {
        $rates = array_fill_keys(CostCalculator::TOKEN_TYPES, '1.00');

        $this->assertSame(1_000, CostCalculator::providerCost($this->usage(text: 1), $rates));
    }

    public function test_extra_usage_keys_such_as_total_tokens_are_ignored(): void
    {
        $usage = $this->usage(115, 1530, 6450, 8000) + ['total_tokens' => 16_095];

        $this->assertSame(373_875_000, CostCalculator::providerCost($usage, $this->rates()));
    }

    public function test_it_throws_instead_of_rounding_when_the_cost_is_not_a_whole_nanodollar(): void
    {
        $rates = array_fill_keys(CostCalculator::TOKEN_TYPES, '0.0000001');   // 100 nano per 1M tokens

        $this->expectException(InexactCostException::class);

        CostCalculator::providerCost($this->usage(text: 1), $rates);
    }

    public function test_markup_is_exactly_25_percent(): void
    {
        $this->assertSame(467_343_750, CostCalculator::charge(373_875_000, 2500));
        $this->assertSame(5, CostCalculator::charge(4, 2500));
        $this->assertSame(0, CostCalculator::charge(0, 2500));
    }

    public function test_a_fraction_of_a_nanodollar_is_rounded_up_so_profit_is_never_negative(): void
    {
        $this->assertSame(2, CostCalculator::charge(1, 2500));   // 1.25 -> 2
        $this->assertSame(4, CostCalculator::charge(3, 2500));   // 3.75 -> 4

        foreach ([1, 2, 3, 7, 999, 373_875_001] as $cost) {
            $this->assertGreaterThanOrEqual($cost, CostCalculator::charge($cost, 2500));
        }
    }
}
