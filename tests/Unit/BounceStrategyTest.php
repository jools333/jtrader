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

        // 12 candles: touch level 100.0 (low 99.8).
        // All 4 Hard filters pass (approach 99.8 in [97.5, 102.5], normal atr, trend, entry_zone in [96.75, 103.25])
        // Soft criterion bullish_confirmation passes (ema8 rising).
        // Soft criterion atr_bounce FAILS: minLow 100.0 + 0.10*atr (0.5) = 100.5, but close is 100.3 < 100.5
        // Result: 5/6 criteria pass = 83.33%, all Hard filters passed -> entry generated!
        $candles = $this->baseline(10, 108.0, 103.0);
        $candles[] = $this->candle(102.0, 102.5, 100.0, 100.5); // minLow 100.0 in [97.5, 102.5]
        $candles[] = $this->candle(100.5, 100.8, 100.1, 100.3); // close 100.3 < 100.5 (atr_bounce fails)

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0); // price (100.1) > ema50 (90)

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 83.33);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertEquals(83.33, $diag->score);
        $this->assertFalse($diag->isFullSignal);

        // evaluate should return entrySignal because all Hard filters passed and 83.33 >= minEntryScore 83.33
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNotNull($signal);
        $this->assertSame(Direction::Long, $signal->direction);
    }

    public function test_bounce_rejects_entry_when_hard_filter_fails_even_at_83_score(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // Level approach (Hard filter) fails: pierced too deep (min low 97.0 < 97.5)
        // But price bounced to 98.0 (>= 97.0 + 0.5), in entry zone [96.75, 103.25], trend & atr pass (5/6 = 83.33%)
        $candles = $this->baseline(10, 105.0, 100.5);
        $candles[] = $this->candle(100.0, 100.5, 97.0, 97.5); // pierced too deep (low 97.0 < 97.5 -> level_approach fails!)
        $candles[] = $this->candle(97.5, 98.5, 97.5, 98.0); // bounce to 98.0 >= 97.5

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0); // price 98.0 > ema50 90.0

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 83.33);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertEquals(83.33, $diag->score);

        // evaluate MUST reject entry because Hard filter level_approach failed!
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNull($signal);
    }

    public function test_bounce_rejects_entry_when_score_below_83(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // 2 criteria fail: entry_zone (close 104 > 103.25) AND trend fails (falling EMA8, below EMA50)
        $candles = $this->baseline(10, 105.0, 100.5);
        $candles[] = $this->candle(100.5, 101.0, 99.8, 100.2);
        $candles[] = $this->candle(100.2, 104.5, 100.0, 104.0); // entry_zone fails (104.0 > 103.25)

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 94.0; // falling (trend fails for Long)
        $ema50 = array_fill(0, $n, 105.0); // price (104) < ema50 (105) (trend fails)

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
