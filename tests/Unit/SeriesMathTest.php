<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Market\Analysis\Support\Pivot;
use App\Market\Analysis\Support\SeriesMath;
use App\Market\DTO\Candle;
use PHPUnit\Framework\TestCase;

class SeriesMathTest extends TestCase
{
    /** @param array<int, array{0:float,1:float,2:float,3:float}> $ohlc */
    private function candles(array $ohlc): array
    {
        $t = 1_700_000_000_000;

        return array_map(function (array $row) use (&$t): Candle {
            [$o, $h, $l, $c] = $row;
            $candle = new Candle($t, $o, $h, $l, $c, 1.0, $t + 3_599_999);
            $t += 3_600_000;

            return $candle;
        }, $ohlc);
    }

    public function test_atr_matches_average_true_range(): void
    {
        // Each candle has a constant 10-wide range and no gaps -> ATR = 10.
        $candles = $this->candles(array_fill(0, 30, [100.0, 105.0, 95.0, 100.0]));

        $this->assertEqualsWithDelta(10.0, SeriesMath::atr($candles, 14), 0.001);
    }

    public function test_linreg_slope_sign_follows_direction(): void
    {
        $this->assertGreaterThan(0, SeriesMath::linregSlope([1, 2, 3, 4, 5]));
        $this->assertLessThan(0, SeriesMath::linregSlope([5, 4, 3, 2, 1]));
        $this->assertEqualsWithDelta(0.0, SeriesMath::linregSlope([3, 3, 3, 3]), 1e-9);
    }

    public function test_rsquared_is_one_for_perfect_line(): void
    {
        $this->assertEqualsWithDelta(1.0, SeriesMath::rSquared([2, 4, 6, 8, 10]), 1e-9);
    }

    public function test_pivots_detect_local_extrema(): void
    {
        // A single clear peak at index 5, then a clear trough at index 11.
        $prices = [10, 11, 12, 13, 14, 20, 14, 13, 12, 11, 10, 4, 10, 11, 12, 13, 14];
        $candles = $this->candles(array_map(
            static fn (float $p) => [$p, $p + 1, $p - 1, $p],
            array_map('floatval', $prices),
        ));

        $pivots = SeriesMath::pivots($candles, 3, 3);

        $highs = array_filter($pivots, static fn (Pivot $p) => $p->isHigh());
        $lows = array_filter($pivots, static fn (Pivot $p) => $p->isLow());

        $this->assertNotEmpty($highs, 'expected at least one swing high');
        $this->assertNotEmpty($lows, 'expected at least one swing low');
    }
}
