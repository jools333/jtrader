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

    private function baseline(int $n, float $start, float $end, float $v = 100.0): array
    {
        $candles = [];
        $step = ($end - $start) / max(1, $n - 1);
        for ($i = 0; $i < $n; $i++) {
            $c = $start + $step * $i;
            $candles[] = $this->candle($c - 0.1, $c + 1.0, $c - 1.0, $c, $v);
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

        // 12 candles: approach level 100.0, bounce up with volume confirmation (150 > 100 * 1.15)
        $candles = $this->baseline(10, 105.0, 100.5);
        $candles[] = $this->candle(100.5, 101.0, 99.8, 100.2, 100.0); // touch zone (99.8 in [97.5, 102.5])
        $candles[] = $this->candle(100.2, 102.5, 100.0, 102.0, 150.0); // bounce >= 99.8 + 0.5 = 100.3, volume surge

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0); // price (102) > ema50 (90)

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 80.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertEquals(100.0, $diag->score);
        $this->assertTrue($diag->isFullSignal);

        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNotNull($signal);
        $this->assertSame(SignalType::Bounce, $signal->type);
        $this->assertSame(Direction::Long, $signal->direction);
        $this->assertSame(102.0, $signal->entryPrice);
    }

    public function test_bounce_long_generates_entry_at_87_percent_score(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // 12 candles: touch level 100.0 (low 99.8).
        // All 5 Hard filters pass (approach 99.8 in [97.5, 102.5], normal atr, trend, entry_zone, no_climax)
        // Soft criterion bullish_confirmation passes (ema8 rising) & volume_surge passes (150 > 115).
        // Soft criterion atr_bounce FAILS: minLow 100.0 + 0.10*atr (0.5) = 100.5, but close is 100.3 < 100.5
        // Result: 7/8 criteria pass = 87.5%, all Hard filters passed -> entry generated!
        $candles = $this->baseline(10, 108.0, 103.0);
        $candles[] = $this->candle(102.0, 102.5, 100.0, 100.5, 100.0); // minLow 100.0 in [97.5, 102.5]
        $candles[] = $this->candle(100.5, 100.8, 100.1, 100.3, 150.0); // close 100.3 < 100.5 (atr_bounce fails), volume passes

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0); // price (100.1) > ema50 (90)

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 80.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertEquals(87.5, $diag->score);
        $this->assertFalse($diag->isFullSignal);

        // evaluate should return entrySignal because all Hard filters passed and 87.5 >= minEntryScore 80.0
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNotNull($signal);
        $this->assertSame(Direction::Long, $signal->direction);
    }

    public function test_bounce_rejects_entry_when_hard_filter_fails_even_at_87_score(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // Level approach (Hard filter) fails: pierced too deep (min low 97.0 < 97.5)
        // But price bounced to 98.0 (>= 97.0 + 0.5), in entry zone [96.75, 103.25], trend & atr pass, volume passes (7/8 = 87.5%)
        $candles = $this->baseline(10, 105.0, 100.5);
        $candles[] = $this->candle(100.0, 100.5, 97.0, 97.5, 100.0); // pierced too deep (low 97.0 < 97.5 -> level_approach fails!)
        $candles[] = $this->candle(97.5, 98.5, 97.5, 98.0, 150.0); // bounce to 98.0 >= 97.5, volume surge

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0); // price 98.0 > ema50 90.0

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 80.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertEquals(87.5, $diag->score);

        // evaluate MUST reject entry because Hard filter level_approach failed!
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNull($signal);
    }

    public function test_bounce_rejects_entry_when_score_below_80(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // 2 criteria fail: entry_zone (close 104 > 103.25) AND trend fails (falling EMA8, below EMA50)
        $candles = $this->baseline(10, 105.0, 100.5);
        $candles[] = $this->candle(100.5, 101.0, 99.8, 100.2, 100.0);
        $candles[] = $this->candle(100.2, 104.5, 100.0, 104.0, 100.0); // entry_zone fails (104.0 > 103.25) & volume fails (100 < 115)

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 94.0; // falling (trend fails for Long)
        $ema50 = array_fill(0, $n, 105.0); // price (104) < ema50 (105) (trend fails)

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 80.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertLessThan(80.0, $diag->score);

        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNull($signal);
    }

    public function test_bounce_long_rejected_on_selling_climax_hard_filter(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // Baseline candles with volume 100.0
        $candles = $this->baseline(10, 105.0, 103.0, 100.0);

        // Selling Climax candle: massive volume 300 (3x avg 100 > 2.2x),
        // large body (103.0 -> 99.8 = 3.2 >= 5.0 * 0.40 = 2.0 ATR), closes at absolute bottom (low 99.8)
        $candles[] = $this->candle(103.0, 103.2, 99.8, 99.8, 300.0);

        // Immediate bounce attempt on next candle with volume 150
        $candles[] = $this->candle(99.8, 101.5, 99.8, 101.0, 150.0);

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0);

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 80.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertFalse($diag->criteria['no_climax']->passed);
        $this->assertContains('Уровень пробивается на аномальном объеме (падающий нож)', $diag->missingCriteria);

        // evaluate MUST return null because no_climax is a Hard filter
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNull($signal);
    }

    public function test_bounce_short_rejected_on_buying_climax_hard_filter(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // Baseline candles with volume 100.0
        $candles = $this->baseline(10, 95.0, 97.0, 100.0);

        // Buying Climax candle: volume 300 (3x avg),
        // large bull body (97.0 -> 100.2 = 3.2 >= 2.0 ATR), closes at the very top (high 100.2)
        $candles[] = $this->candle(97.0, 100.2, 96.8, 100.2, 300.0);

        // Immediate bounce down attempt with volume 150
        $candles[] = $this->candle(100.2, 100.2, 98.5, 99.0, 150.0);

        $n = count($candles);
        $ema8 = array_fill(0, $n, 105.0);
        $ema8[$n - 1] = 104.0; // falling
        $ema50 = array_fill(0, $n, 110.0); // price < ema50

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 80.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertFalse($diag->criteria['no_climax']->passed);
        $this->assertContains('Уровень пробивается на аномальном объеме вверх (импульсный пробой)', $diag->missingCriteria);

        // evaluate MUST return null because no_climax is a Hard filter
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNull($signal);
    }
}
