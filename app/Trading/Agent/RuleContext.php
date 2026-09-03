<?php

declare(strict_types=1);

namespace App\Trading\Agent;

use App\Market\DTO\Candle;
use App\Trading\Analysis\CandleSignals;
use App\Trading\Enums\Direction;

/**
 * Precomputed, read-only view over the candle series and its indicators for a
 * single evaluation. Keeps {@see TradingAgent} focused on rule logic by
 * centralising the small geometric/indicator queries the rules share.
 *
 * @internal
 */
final class RuleContext
{
    /** Index of the last (current) candle. */
    public readonly int $i;

    /** Number of candles. */
    public readonly int $n;

    /**
     * @param array<int, Candle> $candles oldest -> newest
     * @param array<int, float> $ema8
     * @param array<int, float> $ema21
     * @param array{line: array<int,float>, signal: array<int,float>, histogram: array<int,float>} $macd
     */
    public function __construct(
        public readonly array $candles,
        public readonly float $level,
        public readonly float $atr,
        public readonly array $ema8,
        public readonly array $ema21,
        public readonly array $ema50,
        public readonly array $macd,
        public readonly ?string $symbol = null,
        public readonly ?string $interval = null,
        public readonly ?array $btcCandles = null,
        public readonly ?array $btcEma8 = null,
        public readonly ?array $btcEma21 = null,
        public readonly ?array $btcEma50 = null,
    ) {
        $this->n = count($candles);
        $this->i = $this->n - 1;
    }

    public function last(): Candle
    {
        return $this->candles[$this->i];
    }

    /** Current price = close of the last candle. */
    public function price(): float
    {
        return $this->candles[$this->i]->close;
    }

    /**
     * The last `k` candles.
     *
     * @return array<int, Candle>
     */
    public function slice(int $k): array
    {
        return array_slice($this->candles, -$k);
    }

    public function ema8At(int $idx): float
    {
        return $this->ema8[$idx] ?? 0.0;
    }

    public function ema21At(int $idx): float
    {
        return $this->ema21[$idx] ?? 0.0;
    }

    public function ema50At(int $idx): float
    {
        return $this->ema50[$idx] ?? 0.0;
    }

    public function macdHistAt(int $idx): float
    {
        return $this->macd['histogram'][$idx] ?? 0.0;
    }

    public function ema8Falling(): bool
    {
        return $this->ema8At($this->i) < $this->ema8At($this->i - 1);
    }

    public function ema8Rising(): bool
    {
        return $this->ema8At($this->i) > $this->ema8At($this->i - 1);
    }

    /** Signed share of ATR between current price and the level. */
    public function atrTravelSigned(): float
    {
        return $this->atr > 0.0 ? ($this->price() - $this->level) / $this->atr : 0.0;
    }

    /** Absolute share of ATR travelled away from the level. */
    public function atrTravelFraction(): float
    {
        return abs($this->atrTravelSigned());
    }

    /**
     * Every candle in the slice has its range intersecting the level band.
     *
     * @param array<int, Candle> $candles
     */
    public function allTouchZone(array $candles, float $tolerance): bool
    {
        foreach ($candles as $c) {
            if ($c->low > $this->level + $tolerance || $c->high < $this->level - $tolerance) {
                return false;
            }
        }

        return $candles !== [];
    }

    /** High-low span of the last `k` candles. */
    public function recentWidth(int $k): float
    {
        $slice = $this->slice($k);
        $highs = array_map(static fn (Candle $c) => $c->high, $slice);
        $lows = array_map(static fn (Candle $c) => $c->low, $slice);

        return (max($highs) ?: 0.0) - (min($lows) ?: 0.0);
    }

    /**
     * Was there a strong breakout candle (body > ATR*0.7) closing beyond the
     * level in the given direction, within the last `lookback` candles?
     */
    public function hadBreakoutCandle(Direction $dir, int $lookback): bool
    {
        foreach ($this->slice($lookback) as $c) {
            if (CandleSignals::body($c) <= $this->atr * 0.7) {
                continue;
            }
            if ($dir === Direction::Long && $c->close > $c->open && $c->close > $this->level) {
                return true;
            }
            if ($dir === Direction::Short && $c->close < $c->open && $c->close < $this->level) {
                return true;
            }
        }

        return false;
    }

    /** EMA8 has stayed on the trend side of EMA21 for the last `k` candles. */
    public function emaTrend(Direction $dir, int $k): bool
    {
        for ($idx = $this->i; $idx > $this->i - $k && $idx >= 0; $idx--) {
            $above = $this->ema8At($idx) > $this->ema21At($idx);
            if ($dir === Direction::Long && ! $above) {
                return false;
            }
            if ($dir === Direction::Short && $above) {
                return false;
            }
        }

        return true;
    }

    /** Price prints a higher high while MACD fails to confirm. */
    public function bearishDivergence(int $lookback = 5): bool
    {
        if ($this->i < $lookback) {
            return false;
        }
        $window = array_slice($this->candles, $this->i - $lookback, $lookback);
        $priorHigh = max(array_map(static fn (Candle $c) => $c->close, $window));
        $line = $this->macd['line'];

        return $this->price() > $priorHigh && ($line[$this->i] ?? 0.0) < ($line[$this->i - 1] ?? 0.0);
    }

    /** Price prints a lower low while MACD fails to confirm. */
    public function bullishDivergence(int $lookback = 5): bool
    {
        if ($this->i < $lookback) {
            return false;
        }
        $window = array_slice($this->candles, $this->i - $lookback, $lookback);
        $priorLow = min(array_map(static fn (Candle $c) => $c->close, $window));
        $line = $this->macd['line'];

        return $this->price() < $priorLow && ($line[$this->i] ?? 0.0) > ($line[$this->i - 1] ?? 0.0);
    }

    /** Whether BTC candle context is available. */
    public function hasBtcData(): bool
    {
        return ! empty($this->btcCandles) && count($this->btcCandles) >= 3;
    }

    /** Price change of BTC over the last `k` candles in percent (e.g. -0.25). */
    public function btcReturnPct(int $k = 3): ?float
    {
        if (! $this->hasBtcData()) {
            return null;
        }

        $cnt = count($this->btcCandles);
        $lastIdx = $cnt - 1;
        $prevIdx = max(0, $lastIdx - $k);

        $lastClose = $this->btcCandles[$lastIdx]->close;
        $prevClose = $this->btcCandles[$prevIdx]->close;

        if ($prevClose <= 0.0) {
            return 0.0;
        }

        return (($lastClose - $prevClose) / $prevClose) * 100.0;
    }

    public function btcEma8Falling(): ?bool
    {
        if (empty($this->btcEma8) || count($this->btcEma8) < 2) {
            return null;
        }

        $idx = count($this->btcEma8) - 1;

        return ($this->btcEma8[$idx] ?? 0.0) < ($this->btcEma8[$idx - 1] ?? 0.0);
    }

    public function btcEma8Rising(): ?bool
    {
        if (empty($this->btcEma8) || count($this->btcEma8) < 2) {
            return null;
        }

        $idx = count($this->btcEma8) - 1;

        return ($this->btcEma8[$idx] ?? 0.0) > ($this->btcEma8[$idx - 1] ?? 0.0);
    }

    public function btcLastPrice(): ?float
    {
        if (! $this->hasBtcData()) {
            return null;
        }

        $c = end($this->btcCandles);

        return $c ? $c->close : null;
    }

    public function btcEma50(): ?float
    {
        if (empty($this->btcEma50)) {
            return null;
        }

        $v = end($this->btcEma50);

        return $v !== false ? (float) $v : null;
    }

    /** Price change of the current symbol over the last `k` candles in percent (e.g. -0.25). */
    public function returnPct(int $k = 3): float
    {
        if ($this->n < 2) {
            return 0.0;
        }

        $prevIdx = max(0, $this->i - $k);
        $prevClose = $this->candles[$prevIdx]->close;

        if ($prevClose <= 0.0) {
            return 0.0;
        }

        return (($this->price() - $prevClose) / $prevClose) * 100.0;
    }

    /** Whether the latest BTC candle has volume surging above its recent average. */
    public function btcVolumeSurge(float $multiplier = 1.3, int $period = 20): ?bool
    {
        if (! $this->hasBtcData()) {
            return null;
        }

        $cnt = count($this->btcCandles);
        $lastCandle = $this->btcCandles[$cnt - 1];
        $priorSlice = array_slice($this->btcCandles, max(0, $cnt - 1 - $period), min($cnt - 1, $period));
        $avgVol = CandleSignals::avgVolume($priorSlice, $period);

        if ($avgVol <= 0.0) {
            return false;
        }

        return $lastCandle->volume >= $avgVol * $multiplier;
    }

    /** Whether the latest candle of the current symbol has volume surging above its recent average. */
    public function volumeSurge(float $multiplier = 1.5, int $period = 20): bool
    {
        if ($this->n < 2) {
            return false;
        }

        $lastCandle = $this->last();
        $priorSlice = array_slice($this->candles, max(0, $this->n - 1 - $period), min($this->n - 1, $period));
        $avgVol = CandleSignals::avgVolume($priorSlice, $period);

        if ($avgVol <= 0.0) {
            return false;
        }

        return $lastCandle->volume >= $avgVol * $multiplier;
    }
}

