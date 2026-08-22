<?php

declare(strict_types=1);

namespace App\Trading\DTO;

/**
 * The indicator values at the moment of evaluation (latest candle).
 */
final class IndicatorSnapshot
{
    public function __construct(
        public readonly float $ema8,
        public readonly float $ema21,
        public readonly float $ema50,
        public readonly float $macdLine,
        public readonly float $macdSignal,
        public readonly float $macdHist,
        public readonly float $atr,
    ) {
    }

    public function toArray(): array
    {
        return [
            'ema8' => round($this->ema8, 8),
            'ema21' => round($this->ema21, 8),
            'ema50' => round($this->ema50, 8),
            'macd_line' => round($this->macdLine, 8),
            'macd_signal' => round($this->macdSignal, 8),
            'macd_hist' => round($this->macdHist, 8),
            'atr' => round($this->atr, 8),
        ];
    }
}
