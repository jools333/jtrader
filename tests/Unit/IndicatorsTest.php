<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Market\Analysis\Support\SeriesMath;
use App\Market\DTO\Candle;
use PHPUnit\Framework\TestCase;

class IndicatorsTest extends TestCase
{
    /** @param array<int, float> $closes */
    private function flatCandles(array $closes): array
    {
        $t = 1_700_000_000_000;

        return array_map(function (float $c) use (&$t): Candle {
            $candle = new Candle($t, $c, $c + 5, $c - 5, $c, 1.0, $t + 3_599_999);
            $t += 3_600_000;

            return $candle;
        }, $closes);
    }

    public function test_ema_is_seeded_with_first_value(): void
    {
        $ema = SeriesMath::ema([1.0, 2.0, 3.0, 4.0, 5.0], 3);

        $this->assertSame(1.0, $ema[0]);
        // k = 0.5; recursively: 1, 1.5, 2.25, 3.125, 4.0625
        $this->assertEqualsWithDelta(4.0625, end($ema), 1e-9);
    }

    public function test_ema_of_constant_series_is_constant(): void
    {
        $ema = SeriesMath::ema(array_fill(0, 30, 42.0), 8);

        $this->assertEqualsWithDelta(42.0, end($ema), 1e-9);
    }

    public function test_macd_of_constant_series_is_zero(): void
    {
        $macd = SeriesMath::macd(array_fill(0, 60, 100.0));

        $this->assertEqualsWithDelta(0.0, end($macd['line']), 1e-9);
        $this->assertEqualsWithDelta(0.0, end($macd['signal']), 1e-9);
        $this->assertEqualsWithDelta(0.0, end($macd['histogram']), 1e-9);
    }

    public function test_macd_line_is_positive_in_an_uptrend(): void
    {
        $closes = [];
        for ($i = 0; $i < 60; $i++) {
            $closes[] = 100.0 + $i; // steadily rising
        }
        $macd = SeriesMath::macd($closes);

        // Fast EMA leads a rising series, so MACD line is positive.
        $this->assertGreaterThan(0.0, end($macd['line']));
    }

    public function test_atr_sma_equals_average_true_range(): void
    {
        // Constant 10-wide range, no gaps -> every TR is 10 -> SMA = 10.
        $candles = $this->flatCandles(array_fill(0, 30, 100.0));

        $this->assertEqualsWithDelta(10.0, SeriesMath::atrSma($candles, 14), 1e-9);
    }
}
