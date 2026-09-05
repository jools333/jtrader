<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Market\DTO\Candle;
use App\Trading\Agent\RuleContext;
use App\Trading\Agent\TradePlanner;
use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;
use App\Trading\Strategies\Entry\BounceStrategy;
use PHPUnit\Framework\TestCase;

class BounceStrategyTest extends TestCase
{
    private int $t = 1_700_000_000_000;

    private function candle(float $o, float $h, float $l, float $c, float $v = 100.0): Candle
    {
        $candle = new Candle($this->t, $o, $h, $l, $c, $v, $this->t + 299_999);
        $this->t += 300_000;

        return $candle;
    }

    private function baseline(int $n, float $start, float $end): array
    {
        $candles = [];
        $step = ($end - $start) / max(1, $n - 1);
        for ($i = 0; $i < $n; $i++) {
            $c = $start + $step * $i;
            $candles[] = $this->candle($c - 0.1, $c + 1.0, $c - 1.0, $c);
        }

        return $candles;
    }

    private function createContext(
        array $candles,
        float $level = 100.0,
        float $atr = 5.0,
        string $symbol = 'ADA-USDT',
        ?array $ema8 = null,
        ?array $ema50 = null,
    ): RuleContext {
        $n = count($candles);
        $ema8Series = $ema8 ?? array_fill(0, $n, 100.0);
        $ema50Series = $ema50 ?? array_fill(0, $n, 90.0);
        $ema21Series = array_fill(0, $n, 95.0);
        $macd = [
            'line' => array_fill(0, $n, 0.0),
            'signal' => array_fill(0, $n, 0.0),
            'histogram' => array_fill(0, $n, 0.0),
        ];

        return new RuleContext(
            candles: $candles,
            level: $level,
            atr: $atr,
            ema8: $ema8Series,
            ema21: $ema21Series,
            ema50: $ema50Series,
            macd: $macd,
            symbol: $symbol,
            interval: '5m',
        );
    }

    public function test_bounce_long_generates_entry_at_100_percent(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // 12 candles: approach level 100.0, bounce up
        $candles = $this->baseline(10, 105.0, 100.5);
        $candles[] = $this->candle(100.5, 101.0, 99.8, 100.2); // touch zone (99.8 in [97.5, 102.5])
        $candles[] = $this->candle(100.2, 102.5, 100.0, 102.0); // bounce >= 99.8 + 0.5 = 100.3, close in zone <= 102.5

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0); // price (102) > ema50 (90)

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 83.33);
        $signal = $strategy->evaluate($ctx, $planner);

        $this->assertNotNull($signal);
        $this->assertSame(SignalType::Bounce, $signal->type);
        $this->assertSame(Direction::Long, $signal->direction);
        $this->assertSame(102.0, $signal->entryPrice);
    }

    public function test_bounce_long_generates_entry_at_83_percent_score(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // 12 candles: approach level 100.0, but bounce moved close slightly above 102.5 (level + 0.5*atr)
        // so entry_zone fails (5/6 criteria pass = 83.33%)
        $candles = $this->baseline(10, 105.0, 100.5);
        $candles[] = $this->candle(100.5, 101.0, 99.8, 100.2); // touch zone (99.8 in [97.5, 102.5])
        $candles[] = $this->candle(100.2, 103.5, 100.0, 103.0); // bounce >= 100.3, but close 103.0 > 102.5 (entry_zone fails!)

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0); // price (103) > ema50 (90)

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 83.33);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertEquals(83.33, $diag->score);
        $this->assertFalse($diag->isFullSignal);

        // evaluate should return entrySignal because 83.33 >= minEntryScore 83.33
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNotNull($signal);
        $this->assertSame(Direction::Long, $signal->direction);
    }

    public function test_bounce_rejects_entry_when_score_below_83(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // 2 criteria fail: entry_zone AND trend fails
        $candles = $this->baseline(10, 105.0, 100.5);
        $candles[] = $this->candle(100.5, 101.0, 99.8, 100.2);
        $candles[] = $this->candle(100.2, 103.5, 100.0, 103.0); // entry_zone fails

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 94.0; // falling (trend fails for Long)
        $ema50 = array_fill(0, $n, 105.0); // price (103) < ema50 (105) (trend fails)

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 83.33);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertLessThan(83.33, $diag->score);

        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNull($signal);
    }
}
