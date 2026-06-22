<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Market\DTO\Candle;
use App\Trading\Agent\TradingAgent;
use App\Trading\DTO\PositionState;
use App\Trading\Enums\Direction;
use App\Trading\Enums\ExitType;
use App\Trading\Enums\SignalType;
use PHPUnit\Framework\TestCase;

class TradingAgentTest extends TestCase
{
    private int $t = 1_700_000_000_000;

    private function candle(float $o, float $h, float $l, float $c, float $v = 1.0): Candle
    {
        $candle = new Candle($this->t, $o, $h, $l, $c, $v, $this->t + 3_599_999);
        $this->t += 3_600_000;

        return $candle;
    }

    /**
     * Baseline of `n` gently rising candles ending near `end`, all comfortably
     * below the resistance used in the entry tests.
     *
     * @return array<int, Candle>
     */
    private function baseline(int $n, float $start, float $end): array
    {
        $candles = [];
        $step = ($end - $start) / max(1, $n - 1);
        for ($i = 0; $i < $n; $i++) {
            $c = $start + $step * $i;
            $candles[] = $this->candle($c - 0.1, $c + 1, $c - 1, $c);
        }

        return $candles;
    }

    private function agent(): TradingAgent
    {
        return new TradingAgent([
            'min_rr' => 2.0,
            'max_atr_travel' => 0.60,
            'min_flat_width' => 0.30,
            'stop_atr' => 0.5,
            'target1_r' => 2.0,
            'target2_r' => 4.0,
        ]);
    }

    public function test_always_returns_an_indicator_snapshot(): void
    {
        $candles = $this->baseline(60, 90, 99);
        $result = $this->agent()->evaluate($candles, 100.0, 10.0);

        $this->assertGreaterThan(0.0, $result->indicators->ema8);
        $this->assertSame(10.0, $result->indicators->atr);
    }

    public function test_no_entry_with_too_few_candles(): void
    {
        $candles = $this->baseline(40, 90, 99);
        $result = $this->agent()->evaluate($candles, 100.0, 10.0);

        $this->assertNull($result->entrySignal);
    }

    public function test_dead_flat_market_produces_no_entry(): void
    {
        // 60 candles pinned to the level: last-5 width is far below ATR*0.3.
        $candles = [];
        for ($i = 0; $i < 60; $i++) {
            $candles[] = $this->candle(100.0, 100.2, 99.8, 100.0);
        }
        $result = $this->agent()->evaluate($candles, 100.0, 10.0);

        $this->assertNull($result->entrySignal);
    }

    public function test_bounce_short_off_resistance(): void
    {
        $atr = 10.0;
        $level = 100.0;
        $candles = $this->baseline(50, 88, 97);

        // Compression candles hugging the level, then a bearish impulse rejecting it.
        $candles[] = $this->candle(99.0, 100.8, 99.3, 100.1);   // compression
        $candles[] = $this->candle(100.1, 100.6, 99.4, 99.9);   // compression (last 3)
        $candles[] = $this->candle(99.9, 100.5, 99.2, 100.0);   // compression (last 3)
        $candles[] = $this->candle(103.5, 103.7, 98.0, 98.2);   // bearish impulse (last 3)

        $result = $this->agent()->evaluate($candles, $level, $atr);

        $this->assertNotNull($result->entrySignal);
        $this->assertSame(SignalType::Bounce, $result->entrySignal->type);
        $this->assertSame(Direction::Short, $result->entrySignal->direction);
        $this->assertGreaterThanOrEqual(2.0, $result->entrySignal->rrRatio);
        // Stop sits above resistance; targets are below entry for a short.
        $this->assertGreaterThan($level, $result->entrySignal->stop);
        $this->assertLessThan($result->entrySignal->entryPrice, $result->entrySignal->target2);
    }

    public function test_duplicate_signal_type_is_suppressed(): void
    {
        $atr = 10.0;
        $level = 100.0;
        $candles = $this->baseline(50, 88, 97);
        $candles[] = $this->candle(99.0, 100.8, 99.3, 100.1);
        $candles[] = $this->candle(100.1, 100.6, 99.4, 99.9);
        $candles[] = $this->candle(99.9, 100.5, 99.2, 100.0);
        $candles[] = $this->candle(103.5, 103.7, 98.0, 98.2);

        $result = $this->agent()->evaluate($candles, $level, $atr, null, ['BOUNCE']);

        $this->assertNull($result->entrySignal);
    }

    public function test_exit_partial_take_profit_at_target1(): void
    {
        $candles = $this->baseline(55, 95, 100); // last close = 100
        $position = new PositionState(
            direction: Direction::Long,
            entryPrice: 90.0,
            stopPrice: 88.0,
            target1: 99.0,
            target2: 110.0,
        );

        $exit = $this->agent()->evaluate($candles, 90.0, 10.0, $position)->exitSignal;

        $this->assertNotNull($exit);
        $this->assertSame(ExitType::Target1, $exit->type);
        $this->assertSame(50, $exit->closePercent);
        $this->assertSame(90.0, $exit->moveStopTo);
    }

    public function test_exit_full_at_target2(): void
    {
        $candles = $this->baseline(55, 95, 100);
        $position = new PositionState(Direction::Long, 90.0, 88.0, 95.0, 100.0);

        $exit = $this->agent()->evaluate($candles, 90.0, 10.0, $position)->exitSignal;

        $this->assertNotNull($exit);
        $this->assertSame(ExitType::Target2, $exit->type);
        $this->assertSame(100, $exit->closePercent);
    }

    public function test_exit_stop_loss(): void
    {
        $candles = $this->baseline(55, 105, 100); // last close = 100
        $position = new PositionState(Direction::Long, 110.0, 105.0, 130.0, 140.0);

        $exit = $this->agent()->evaluate($candles, 110.0, 10.0, $position)->exitSignal;

        $this->assertNotNull($exit);
        $this->assertSame(ExitType::StopLoss, $exit->type);
        $this->assertSame(100, $exit->closePercent);
    }

    public function test_break_even_stop_after_partial(): void
    {
        $candles = $this->baseline(55, 95, 100); // last close = 100 = entry
        $position = new PositionState(
            direction: Direction::Long,
            entryPrice: 100.0,
            stopPrice: 88.0,
            target1: 95.0,
            target2: 130.0,
            size: 0.5,
            breakevenSet: true,
        );

        $exit = $this->agent()->evaluate($candles, 100.0, 10.0, $position)->exitSignal;

        $this->assertNotNull($exit);
        $this->assertSame(ExitType::StopLoss, $exit->type);
    }

    public function test_early_reversal_closes_long(): void
    {
        $atr = 8.0;
        $candles = $this->baseline(45, 90, 100);
        // Two compression candles, then a strong bearish impulse with EMA rolling over.
        $candles[] = $this->candle(99.8, 100.6, 99.0, 99.2);   // compression
        $candles[] = $this->candle(99.2, 99.8, 98.4, 98.6);    // compression
        $candles[] = $this->candle(98.6, 98.8, 91.5, 92.0);    // bearish impulse

        $position = new PositionState(
            direction: Direction::Long,
            entryPrice: 80.0,
            stopPrice: 70.0,
            target1: 200.0,
            target2: 300.0,
        );

        $exit = $this->agent()->evaluate($candles, 95.0, $atr, $position)->exitSignal;

        $this->assertNotNull($exit);
        $this->assertSame(ExitType::EarlyReversal, $exit->type);
        $this->assertSame(100, $exit->closePercent);
        $this->assertNotNull($exit->reason);
    }
}
