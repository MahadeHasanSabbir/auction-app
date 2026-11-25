<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('decimalStrings')]
    public function test_it_parses_decimal_strings_without_floats(string $input, int $minor): void
    {
        $this->assertSame($minor, Money::parse($input)->amount);
    }

    public static function decimalStrings(): array
    {
        return [
            'whole' => ['1250', 125000],
            'two decimals' => ['12.50', 1250],
            'one decimal' => ['12.5', 1250],
            'leading zeros' => ['0012.05', 1205],
            'zero' => ['0', 0],
            'sub unit' => ['0.01', 1],
            'negative' => ['-3.20', -320],
            'float trap' => ['0.29', 29],
            'padded' => ['  7.10 ', 710],
        ];
    }

    #[DataProvider('invalidStrings')]
    public function test_it_rejects_invalid_amounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse($input);
    }

    public static function invalidStrings(): array
    {
        return [
            'empty' => [''],
            'letters' => ['abc'],
            'three decimals' => ['1.234'],
            'grouping' => ['1,250'],
            'exponent' => ['1e3'],
            'trailing dot' => ['12.'],
            'too large' => ['12345678901234567890'],
        ];
    }

    public function test_it_adds_and_subtracts(): void
    {
        $a = Money::minor(1000);
        $b = Money::minor(250);

        $this->assertSame(1250, $a->plus($b)->amount);
        $this->assertSame(750, $a->minus($b)->amount);
        $this->assertSame(-750, $b->minus($a)->amount);
        $this->assertSame(1000, $a->amount, 'Money is immutable.');
    }

    #[DataProvider('basisPointCases')]
    public function test_basis_points_round_half_away_from_zero(int $amount, int $bps, int $expected): void
    {
        $this->assertSame($expected, Money::minor($amount)->basisPoints($bps)->amount);
    }

    public static function basisPointCases(): array
    {
        return [
            '10% of 600.00' => [60000, 1000, 6000],
            '2% of 1000.00' => [100000, 200, 2000],
            'half rounds up' => [25, 200, 1],      // 0.5 -> 1
            'below half rounds down' => [24, 200, 0], // 0.48 -> 0
            '35% of 0.01' => [1, 3500, 0],
            'negative half' => [-25, 200, -1],
            'zero rate' => [99999, 0, 0],
            'full rate' => [99999, 10000, 99999],
        ];
    }

    public function test_floor_share_never_rounds_up(): void
    {
        // 80% of 9.99 is 7.992: the biddable amount must not exceed it.
        $this->assertSame(799, Money::minor(999)->basisPointsFloor(8000)->amount);
        $this->assertSame(799, Money::minor(999)->basisPoints(8000)->amount);
        $this->assertSame(0, Money::minor(1)->basisPointsFloor(8000)->amount);
    }

    public function test_it_clamps_between_bounds(): void
    {
        $min = Money::minor(500);
        $max = Money::minor(2000);

        $this->assertSame(500, Money::minor(100)->clamp($min, $max)->amount);
        $this->assertSame(2000, Money::minor(9000)->clamp($min, $max)->amount);
        $this->assertSame(900, Money::minor(900)->clamp($min, $max)->amount);
        $this->assertSame(100, Money::minor(100)->clamp(null, $max)->amount);
        $this->assertSame(9000, Money::minor(9000)->clamp($min, null)->amount);
    }

    public function test_it_compares(): void
    {
        $small = Money::minor(100);
        $large = Money::minor(200);

        $this->assertTrue($large->greaterThan($small));
        $this->assertTrue($small->lessThan($large));
        $this->assertTrue($small->lessThanOrEqual(Money::minor(100)));
        $this->assertTrue($small->equals(Money::minor(100)));
        $this->assertFalse($small->equals(Money::minor(100, 'USD')));
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue(Money::minor(-1)->isNegative());
        $this->assertTrue(Money::minor(1)->isPositive());
    }

    public function test_it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::minor(100, 'BDT')->plus(Money::minor(100, 'USD'));
    }

    public function test_it_converts_back_to_a_decimal_string(): void
    {
        $this->assertSame('12.50', Money::minor(1250)->toDecimal());
        $this->assertSame('0.05', Money::minor(5)->toDecimal());
        $this->assertSame('-0.05', Money::minor(-5)->toDecimal());
        $this->assertSame('0.00', Money::zero()->toDecimal());
        $this->assertSame('1250.00', Money::parse('1250')->toDecimal());
    }

    public function test_it_formats_with_the_currency(): void
    {
        $formatted = Money::minor(125050, 'USD')->format('en');

        $this->assertSame('$1,250.50', $formatted);
        $this->assertStringContainsString('1,250.50', Money::minor(125050, 'BDT')->format('en'));
    }

    public function test_it_uses_the_configured_currency_by_default(): void
    {
        config(['auction.currency' => 'USD']);

        $this->assertSame('USD', Money::minor(1)->currency);
    }

    public function test_it_takes_an_exact_share_rounded_towards_zero(): void
    {
        $this->assertSame(14_000, Money::minor(20_000, 'BDT')->share(140_000, 200_000)->amount);
        $this->assertSame(3, Money::minor(10, 'BDT')->share(1, 3)->amount);
        $this->assertSame(0, Money::minor(10, 'BDT')->share(0, 3)->amount);
        // No overflow where amount × numerator exceeds 64 bits.
        $this->assertSame(4_000_000_000_000, Money::minor(8_000_000_000_000, 'BDT')->share(5_000_000_000_000, 10_000_000_000_000)->amount);

        $this->expectException(InvalidArgumentException::class);
        Money::minor(10, 'BDT')->share(1, 0);
    }
}
