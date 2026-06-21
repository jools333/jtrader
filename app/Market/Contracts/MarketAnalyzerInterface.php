<?php

declare(strict_types=1);

namespace App\Market\Contracts;

use App\Market\DTO\Level;
use App\Market\DTO\Pattern;
use App\Market\DTO\TrendResult;

/**
 * Technical analysis surface consumed by the UI and (later) the trading module.
 *
 * Implementations receive market data via {@see ExchangeInterface}, so the
 * analyzer is decoupled from any specific exchange.
 */
interface MarketAnalyzerInterface
{
    /**
     * Average True Range over `period` candles for the given pair/timeframe.
     */
    public function atr(string $symbol, string $interval, int $period = 14): float;

    /**
     * Up to 4 most significant support/resistance levels on the timeframe.
     *
     * @return array<int, Level>
     */
    public function levels(string $symbol, string $interval, int $maxLevels = 4): array;

    /**
     * Direction and strength of the prevailing trend.
     */
    public function trend(string $symbol, string $interval): TrendResult;

    /**
     * Chart figures relevant to scalping (head & shoulders, triangles,
     * double top/bottom, ...) detected on the timeframe.
     *
     * @return array<int, Pattern>
     */
    public function patterns(string $symbol, string $interval): array;
}
