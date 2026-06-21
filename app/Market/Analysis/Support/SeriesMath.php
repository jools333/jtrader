<?php

declare(strict_types=1);

namespace App\Market\Analysis\Support;

use App\Market\DTO\Candle;

/**
 * Stateless numeric helpers used by the analyzer (regression, ATR, ADX, pivots).
 */
final class SeriesMath
{
    /**
     * Least-squares slope of y over x = 0..n-1.
     *
     * @param array<int, float> $values
     */
    public static function linregSlope(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach (array_values($values) as $i => $y) {
            $sumX += $i;
            $sumY += $y;
            $sumXY += $i * $y;
            $sumXX += $i * $i;
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);

        return $denom == 0.0 ? 0.0 : (($n * $sumXY) - ($sumX * $sumY)) / $denom;
    }

    /**
     * Coefficient of determination (R^2) of a linear fit, 0..1.
     *
     * @param array<int, float> $values
     */
    public static function rSquared(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $values = array_values($values);
        $slope = self::linregSlope($values);
        $mean = array_sum($values) / $n;
        $intercept = $mean - $slope * (($n - 1) / 2);

        $ssRes = $ssTot = 0.0;
        foreach ($values as $i => $y) {
            $predicted = $slope * $i + $intercept;
            $ssRes += ($y - $predicted) ** 2;
            $ssTot += ($y - $mean) ** 2;
        }

        return $ssTot == 0.0 ? 0.0 : max(0.0, 1.0 - ($ssRes / $ssTot));
    }

    /**
     * True Range series.
     *
     * @param array<int, Candle> $candles
     * @return array<int, float>
     */
    public static function trueRanges(array $candles): array
    {
        $tr = [];
        $prevClose = null;
        foreach ($candles as $c) {
            if ($prevClose === null) {
                $tr[] = $c->high - $c->low;
            } else {
                $tr[] = max(
                    $c->high - $c->low,
                    abs($c->high - $prevClose),
                    abs($c->low - $prevClose),
                );
            }
            $prevClose = $c->close;
        }

        return $tr;
    }

    /**
     * Wilder-smoothed ATR value (last). Returns 0 if insufficient data.
     *
     * @param array<int, Candle> $candles
     */
    public static function atr(array $candles, int $period): float
    {
        $tr = self::trueRanges($candles);
        $n = count($tr);
        if ($n < $period || $period < 1) {
            return $n > 0 ? array_sum($tr) / $n : 0.0;
        }

        // Seed with simple average of the first `period` TRs, then Wilder-smooth.
        $atr = array_sum(array_slice($tr, 0, $period)) / $period;
        for ($i = $period; $i < $n; $i++) {
            $atr = (($atr * ($period - 1)) + $tr[$i]) / $period;
        }

        return $atr;
    }

    /**
     * Wilder ADX with final +DI / -DI.
     *
     * @param array<int, Candle> $candles
     * @return array{adx: float, plusDi: float, minusDi: float}
     */
    public static function adx(array $candles, int $period = 14): array
    {
        $n = count($candles);
        if ($n <= $period + 1) {
            return ['adx' => 0.0, 'plusDi' => 0.0, 'minusDi' => 0.0];
        }

        $plusDm = $minusDm = $tr = [];
        for ($i = 1; $i < $n; $i++) {
            $up = $candles[$i]->high - $candles[$i - 1]->high;
            $down = $candles[$i - 1]->low - $candles[$i]->low;

            $plusDm[] = ($up > $down && $up > 0) ? $up : 0.0;
            $minusDm[] = ($down > $up && $down > 0) ? $down : 0.0;

            $tr[] = max(
                $candles[$i]->high - $candles[$i]->low,
                abs($candles[$i]->high - $candles[$i - 1]->close),
                abs($candles[$i]->low - $candles[$i - 1]->close),
            );
        }

        // Wilder smoothing of TR, +DM, -DM.
        $smooth = static function (array $values, int $p): array {
            $out = [];
            $sum = array_sum(array_slice($values, 0, $p));
            $out[] = $sum;
            for ($i = $p; $i < count($values); $i++) {
                $sum = $sum - ($sum / $p) + $values[$i];
                $out[] = $sum;
            }

            return $out;
        };

        $count = count($tr);
        if ($count < $period) {
            return ['adx' => 0.0, 'plusDi' => 0.0, 'minusDi' => 0.0];
        }

        $trS = $smooth($tr, $period);
        $plusS = $smooth($plusDm, $period);
        $minusS = $smooth($minusDm, $period);

        $dx = [];
        foreach ($trS as $i => $trVal) {
            $plusDi = $trVal == 0.0 ? 0.0 : 100 * ($plusS[$i] / $trVal);
            $minusDi = $trVal == 0.0 ? 0.0 : 100 * ($minusS[$i] / $trVal);
            $sumDi = $plusDi + $minusDi;
            $dx[] = $sumDi == 0.0 ? 0.0 : 100 * abs($plusDi - $minusDi) / $sumDi;
        }

        // Final DI from the last smoothed values.
        $lastTr = end($trS) ?: 0.0;
        $plusDiLast = $lastTr == 0.0 ? 0.0 : 100 * (end($plusS) / $lastTr);
        $minusDiLast = $lastTr == 0.0 ? 0.0 : 100 * (end($minusS) / $lastTr);

        // ADX = Wilder average of DX.
        if (count($dx) < $period) {
            $adx = array_sum($dx) / max(1, count($dx));
        } else {
            $adx = array_sum(array_slice($dx, 0, $period)) / $period;
            for ($i = $period; $i < count($dx); $i++) {
                $adx = (($adx * ($period - 1)) + $dx[$i]) / $period;
            }
        }

        return ['adx' => $adx, 'plusDi' => $plusDiLast, 'minusDi' => $minusDiLast];
    }

    /**
     * Detect swing pivots using a symmetric window of `left`/`right` bars.
     *
     * @param array<int, Candle> $candles
     * @return array<int, Pivot>
     */
    public static function pivots(array $candles, int $left = 3, int $right = 3): array
    {
        $pivots = [];
        $n = count($candles);

        for ($i = $left; $i < $n - $right; $i++) {
            $isHigh = true;
            $isLow = true;

            for ($j = $i - $left; $j <= $i + $right; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ($candles[$j]->high >= $candles[$i]->high) {
                    $isHigh = false;
                }
                if ($candles[$j]->low <= $candles[$i]->low) {
                    $isLow = false;
                }
            }

            $time = intdiv($candles[$i]->openTime, 1000);
            if ($isHigh) {
                $pivots[] = new Pivot($i, $time, $candles[$i]->high, Pivot::HIGH);
            } elseif ($isLow) {
                $pivots[] = new Pivot($i, $time, $candles[$i]->low, Pivot::LOW);
            }
        }

        return $pivots;
    }
}
