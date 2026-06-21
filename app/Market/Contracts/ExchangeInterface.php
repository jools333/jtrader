<?php

declare(strict_types=1);

namespace App\Market\Contracts;

use App\Market\DTO\Candle;

/**
 * Abstraction over a crypto exchange's public market data.
 *
 * All BingX-specific code lives behind this interface; swapping exchanges is a
 * matter of providing another implementation and rebinding it in
 * {@see \App\Providers\ExchangeServiceProvider}.
 */
interface ExchangeInterface
{
    /** Human-readable driver name, e.g. "BingX". */
    public function name(): string;

    /**
     * Configured tradable symbols (exchange-native format).
     *
     * @return array<int, string>
     */
    public function symbols(): array;

    /**
     * Recent OHLCV candles for a symbol/timeframe, ordered oldest -> newest.
     *
     * @return array<int, Candle>
     */
    public function klines(string $symbol, string $interval, int $limit = 500): array;

    /**
     * Latest ticker snapshot (normalised keys: symbol, last, high, low,
     * volume, changePercent).
     *
     * @return array<string, mixed>
     */
    public function ticker(string $symbol): array;
}
