<?php

namespace Tests\Unit;

use App\Support\Nano;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NanoTest extends TestCase
{
    public function test_it_parses_the_api_decimal_format_exactly(): void
    {
        $this->assertSame(46_125_000, Nano::fromDecimal('0.046125000'));
        $this->assertSame('0.046125000', Nano::toDecimal(46_125_000));
    }

    public function test_it_parses_rates_with_fewer_decimals(): void
    {
        $this->assertSame(5_000_000_000, Nano::fromDecimal('5'));
        $this->assertSame(5_000_000_000, Nano::fromDecimal('5.00'));
        $this->assertSame(12_500_000_000, Nano::fromDecimal('12.50'));
        $this->assertSame(1, Nano::fromDecimal('0.000000001'));
    }

    #[DataProvider('invalidDecimals')]
    public function test_it_rejects_anything_that_is_not_a_plain_decimal(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Nano::fromDecimal($value);
    }

    /** @return array<string, array{string}> */
    public static function invalidDecimals(): array
    {
        return [
            'ten decimals' => ['0.0000000001'],
            'empty' => [''],
            'negative' => ['-1.00'],
            'exponent' => ['1e-3'],
            'thousands separator' => ['1,000.00'],
            'no integer part' => ['.5'],
            'trailing dot' => ['5.'],
            'spaces' => [' 5.00'],
        ];
    }

    public function test_to_decimal_always_has_nine_decimals(): void
    {
        $this->assertSame('0.000000000', Nano::toDecimal(0));
        $this->assertSame('0.373875000', Nano::toDecimal(373_875_000));
        $this->assertSame('12.000000001', Nano::toDecimal(12_000_000_001));
        $this->assertSame('-0.500000000', Nano::toDecimal(-500_000_000));
    }

    public function test_costs_are_displayed_rounded_up_to_four_decimals(): void
    {
        $this->assertSame('$0.4674', Nano::formatCost(467_343_750));   // 0.46734375 -> up
        $this->assertSame('$0.0001', Nano::formatCost(1));             // never shows a cost as free
        $this->assertSame('$0.0000', Nano::formatCost(0));
        $this->assertSame('$0.5000', Nano::formatCost(500_000_000));   // exact values are not bumped
        $this->assertSame('$12.3457', Nano::formatCost(12_345_600_001));
    }

    public function test_balances_are_displayed_rounded_down_to_four_decimals(): void
    {
        $this->assertSame('$9.5326', Nano::formatBalance(9_532_656_250));
        $this->assertSame('$0.0000', Nano::formatBalance(99_999));
        $this->assertSame('$10.0000', Nano::formatBalance(10_000_000_000));
        $this->assertSame('-$0.0001', Nano::formatBalance(-1));        // a debt is not hidden either
    }
}
