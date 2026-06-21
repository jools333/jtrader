<?php

declare(strict_types=1);

namespace App\Market\DTO;

/**
 * A single OHLCV candle. Timestamps are unix milliseconds (exchange-native).
 */
final class Candle
{
    public function __construct(
        public readonly int $openTime,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly float $volume,
        public readonly int $closeTime,
    ) {
    }

    public function isBullish(): bool
    {
        return $this->close >= $this->open;
    }

    /** True candle range (high - low). */
    public function range(): float
    {
        return $this->high - $this->low;
    }

    /**
     * Shape consumed by the front-end chart (lightweight-charts).
     * `time` is in seconds, as the library expects.
     */
    public function toChartArray(): array
    {
        return [
            'time' => intdiv($this->openTime, 1000),
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'volume' => $this->volume,
        ];
    }
}
