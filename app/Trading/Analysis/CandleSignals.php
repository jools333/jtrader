<?php

declare(strict_types=1);

namespace App\Trading\Analysis;

use App\Market\DTO\Candle;

/**
 * ATR-relative candle classification used by the trading rules:
 * compression (consolidation) and impulse (expansion) candles.
 *
 * All thresholds are expressed as fractions of ATR so the same logic works
 * across instruments and timeframes.
 */
final class CandleSignals
{
    /** Absolute body size |close - open|. */
    public static function body(Candle $c): float
    {
        return abs($c->close - $c->open);
    }

    /**
     * A compression (consolidation) candle: small body AND small full range.
     *   |close-open| < ATR*0.3  and  (high-low) < ATR*0.6
     */
    public static function isCompression(Candle $c, float $atr): bool
    {
        return self::body($c) < $atr * 0.3
            && $c->range() < $atr * 0.6;
    }

    /** Bullish impulse: large up-body, |close-open| > ATR*`mult` and close > open. */
    public static function isBullishImpulse(Candle $c, float $atr, float $mult = 0.5): bool
    {
        return $c->close > $c->open && self::body($c) > $atr * $mult;
    }

    /** Bearish impulse: large down-body, |close-open| > ATR*`mult` and close < open. */
    public static function isBearishImpulse(Candle $c, float $atr, float $mult = 0.5): bool
    {
        return $c->close < $c->open && self::body($c) > $atr * $mult;
    }

    /**
     * Count compression candles within the given slice of candles.
     *
     * @param array<int, Candle> $candles
     */
    public static function countCompression(array $candles, float $atr): int
    {
        return count(array_filter($candles, static fn (Candle $c) => self::isCompression($c, $atr)));
    }

    /**
     * Average volume over the last `period` candles (or all, if fewer).
     *
     * @param array<int, Candle> $candles
     */
    public static function avgVolume(array $candles, int $period = 20): float
    {
        $slice = array_slice($candles, -$period);
        if ($slice === []) {
            return 0.0;
        }

        return array_sum(array_map(static fn (Candle $c) => $c->volume, $slice)) / count($slice);
    }
}
