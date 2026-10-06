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
        $candles[] = $this->candle(100.5, 101.0, 99.8, 100.2, 100.0); // touch zone (99.8 in [96.25, 103.75])
        $candles[] = $this->candle(100.2, 102.5, 100.0, 102.0, 150.0); // bounce >= 99.8 + 0.5 = 100.3, volume surge

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0); // price (102) > ema50 (90)

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 75.0);
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
        // Hard filters pass (normal atr, no_climax)
        // Soft criterion bullish_confirmation passes (ema8 rising) & volume_surge passes (150 > 115).
        // Soft criterion atr_bounce FAILS: minLow 100.0 + 0.10*atr (0.5) = 100.5, but close is 100.3 < 100.5
        // Result: 7/8 criteria pass = 87.5%, all Hard filters passed -> entry generated!
        $candles = $this->baseline(10, 108.0, 103.0);
        $candles[] = $this->candle(102.0, 102.5, 100.0, 100.5, 100.0); // minLow 100.0 in [96.25, 103.75]
        $candles[] = $this->candle(100.2, 100.8, 100.1, 100.4, 150.0); // green candle (close 100.4 > open 100.2), but close 100.4 < 100.5 (atr_bounce fails)

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0); // price (100.1) > ema50 (90)

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 75.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertEquals(88.89, $diag->score);
        $this->assertFalse($diag->isFullSignal);

        // evaluate should return entrySignal because all Hard filters passed and 87.5 >= minEntryScore 75.0
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNotNull($signal);
        $this->assertSame(Direction::Long, $signal->direction);
    }

    public function test_bounce_rejects_entry_when_hard_filter_strict_trend_fails(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // 12 candles: approach level 100.0
        // EMA8 is flat (95.0), so strict_trend fails!
        $candles = $this->baseline(10, 108.0, 103.0);
        $candles[] = $this->candle(102.0, 102.5, 100.0, 100.5, 100.0); // minLow 100.0
        $candles[] = $this->candle(100.1, 100.8, 100.0, 100.3, 120.0);

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0); // flat EMA8 -> not rising
        $ema50 = array_fill(0, $n, 105.0); // price (100.3) < ema50 (105.0) -> strict_trend fails

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 75.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertFalse($diag->criteria['strict_trend']->passed);

        // evaluate MUST reject entry because Hard filter strict_trend failed
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNull($signal);
    }

    public function test_bounce_rejects_entry_when_hard_filter_normal_atr_fails(): void
    {
        $level = 100.0;
        $atr = 0.05; // 0.05 / 100 = 0.05% < min 0.20% -> normal_atr Hard filter fails!

        $candles = $this->baseline(10, 100.5, 100.1);
        $candles[] = $this->candle(100.1, 100.2, 99.98, 100.0, 100.0);
        $candles[] = $this->candle(100.0, 100.2, 100.0, 100.15, 150.0);

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising
        $ema50 = array_fill(0, $n, 90.0);

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 75.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertFalse($diag->criteria['normal_atr']->passed);

        // evaluate MUST reject entry because Hard filter normal_atr failed!
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNull($signal);
    }

    public function test_bounce_rejects_entry_when_score_below_75(): void
    {
        $level = 100.0;
        $atr = 5.0;

        // 3 criteria fail: entry_zone (close 106 > 104.25), volume (80 < 90), trend (falling EMA8, below EMA50)
        $candles = $this->baseline(10, 105.0, 100.5);
        $candles[] = $this->candle(100.5, 101.0, 99.8, 100.2, 100.0);
        $candles[] = $this->candle(100.2, 106.5, 100.0, 106.0, 80.0);

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 94.0; // falling (trend fails for Long)
        $ema50 = array_fill(0, $n, 110.0); // price (106) < ema50 (110) (trend fails)

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(minEntryScore: 75.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertLessThan(75.0, $diag->score);

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

        $strategy = new BounceStrategy(minEntryScore: 75.0);
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

        $strategy = new BounceStrategy(minEntryScore: 75.0);
        $diag = $strategy->diagnose($ctx, $planner);

        $this->assertNotNull($diag);
        $this->assertFalse($diag->criteria['no_climax']->passed);
        $this->assertContains('Уровень пробивается на аномальном объеме вверх (импульсный пробой)', $diag->missingCriteria);

        // evaluate MUST return null because no_climax is a Hard filter
        $signal = $strategy->evaluate($ctx, $planner);
        $this->assertNull($signal);
    }

    public function test_bounce_respects_symbol_min_entry_score_override(): void
    {
        $atr = 5.0;
        $level = 100.0;

        // Create an 88.89% score setup (8 out of 9 criteria pass, atr_bounce fails)
        $candles = $this->baseline(10, 108.0, 103.0);
        $candles[] = $this->candle(102.0, 102.5, 100.0, 100.5, 100.0);
        $candles[] = $this->candle(100.1, 100.8, 100.0, 100.3, 120.0); // close > open (bullish)

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // rising -> strict_trend passes
        $ema50 = array_fill(0, $n, 90.0);

        $planner = new TradePlanner(['tp_percent' => 0.35]);

        $strategy = new BounceStrategy(
            minEntryScore: 80.0,
            symbolMinEntryScores: ['DOGE-USDT' => 95.0]
        );

        // ADA-USDT uses standard 80.0% threshold -> allowed (score is 88.89%)
        $adaCtx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $this->assertNotNull($strategy->evaluate($adaCtx, $planner));

        // DOGE-USDT requires 95.0% threshold -> blocked because score is 88.89%
        $dogeCtx = $this->createContext($candles, $level, $atr, 'DOGE-USDT', $ema8, $ema50);
        $this->assertNull($strategy->evaluate($dogeCtx, $planner));
    }

    public function test_bounce_long_rejects_entry_on_red_candle_without_hammer_wick(): void
    {
        $atr = 5.0;
        $level = 100.0;

        $candles = $this->baseline(10, 108.0, 103.0);
        $candles[] = $this->candle(102.0, 102.5, 100.0, 100.5, 100.0);
        // Red trigger candle: Open 100.5, Close 100.2, Low 100.1 (falling bar, tiny lower wick)
        $candles[] = $this->candle(100.5, 100.6, 100.1, 100.2, 120.0);

        $n = count($candles);
        $ema8 = array_fill(0, $n, 95.0);
        $ema8[$n - 1] = 96.0; // even though EMA8 is rising, red candle must NOT pass bullish_confirmation!
        $ema50 = array_fill(0, $n, 90.0);

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);
        $strategy = new BounceStrategy(minEntryScore: 75.0);

        $diag = $strategy->diagnose($ctx, $planner);
        $this->assertFalse($diag->criteria['bullish_confirmation']->passed);
        $this->assertNull($strategy->evaluate($ctx, $planner));
    }

    public function test_bounce_short_rejects_entry_when_ema8_is_rising(): void
    {
        $atr = 5.0;
        $level = 100.0;

        $candles = [];
        for ($i = 0; $i < 10; $i++) {
            $c = 95.0 + ($i % 2 == 0 ? 0.5 : -0.5);
            $candles[] = $this->candle($c, $c + 1.0, $c - 1.0, $c + ($i % 2 == 0 ? -0.2 : 0.2), 100.0);
        }
        $candles[] = $this->candle(96.0, 100.0, 95.5, 99.5, 100.0);
        $candles[] = $this->candle(99.8, 100.0, 98.8, 99.0, 120.0);

        $n = count($candles);
        // Price (99.3) < EMA50 (105.0), BUT EMA8 is actively rising (97.0 -> 99.0)
        $ema8 = array_fill(0, $n, 97.0);
        $ema8[$n - 1] = 99.0; // RISING EMA8
        $ema50 = array_fill(0, $n, 105.0);

        $ctx = $this->createContext($candles, $level, $atr, 'XRP-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);
        $strategy = new BounceStrategy(minEntryScore: 75.0);

        $diag = $strategy->diagnose($ctx, $planner);
        // strict_trend MUST fail because EMA8 is rising!
        $this->assertFalse($diag->criteria['strict_trend']->passed);
        $this->assertNull($strategy->evaluate($ctx, $planner));
    }

    public function test_bounce_short_rejects_entry_on_tiny_candle_after_heavy_momentum_pump(): void
    {
        $atr = 5.0;
        $level = 100.0;

        $candles = [];
        for ($i = 0; $i < 6; $i++) {
            $candles[] = $this->candle(90.0, 91.0, 89.5, 90.5, 100.0);
        }
        // 4 consecutive strong green candles pumping from 90.5 to 100.0 (+10.5% > 0.40%)
        $candles[] = $this->candle(90.5, 93.0, 90.0, 92.8, 100.0);
        $candles[] = $this->candle(92.8, 95.5, 92.5, 95.2, 100.0);
        $candles[] = $this->candle(95.2, 98.0, 95.0, 97.8, 100.0);
        $candles[] = $this->candle(97.8, 100.0, 97.5, 99.9, 100.0);
        // Tiny red pause candle: Open 100.0, Close 99.95 (body 0.05 < 0.15 * ATR = 0.75, no shooting star wick)
        $candles[] = $this->candle(100.0, 100.05, 99.9, 99.95, 120.0);

        $n = count($candles);
        $ema8 = array_fill(0, $n, 98.0);
        $ema8[$n - 1] = 97.0; // Falling EMA8
        $ema50 = array_fill(0, $n, 105.0);

        $ctx = $this->createContext($candles, $level, $atr, 'XRP-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);
        $strategy = new BounceStrategy(minEntryScore: 75.0);

        $diag = $strategy->diagnose($ctx, $planner);
        // bearish_confirmation MUST fail because of heavy up run and tiny body!
        $this->assertFalse($diag->criteria['bearish_confirmation']->passed);
        $this->assertNull($strategy->evaluate($ctx, $planner));
    }

    public function test_bounce_long_rejects_entry_on_tiny_candle_after_heavy_momentum_dump(): void
    {
        $atr = 5.0;
        $level = 100.0;

        $candles = [];
        for ($i = 0; $i < 6; $i++) {
            $candles[] = $this->candle(110.0, 111.0, 109.5, 110.5, 100.0);
        }
        // 4 consecutive strong red candles dumping from 110.5 to 100.0 (-9.5% > 0.40%)
        $candles[] = $this->candle(110.5, 111.0, 107.5, 107.8, 100.0);
        $candles[] = $this->candle(107.8, 108.0, 105.0, 105.2, 100.0);
        $candles[] = $this->candle(105.2, 105.5, 102.5, 102.8, 100.0);
        $candles[] = $this->candle(102.8, 103.0, 100.0, 100.1, 100.0);
        // Tiny green pause candle: Open 100.0, Close 100.05 (body 0.05 < 0.15 * ATR = 0.75, no hammer wick)
        $candles[] = $this->candle(100.0, 100.1, 99.95, 100.05, 120.0);

        $n = count($candles);
        $ema8 = array_fill(0, $n, 102.0);
        $ema8[$n - 1] = 103.0; // Rising EMA8
        $ema50 = array_fill(0, $n, 95.0);

        $ctx = $this->createContext($candles, $level, $atr, 'ADA-USDT', $ema8, $ema50);
        $planner = new TradePlanner(['tp_percent' => 0.35]);
        $strategy = new BounceStrategy(minEntryScore: 75.0);

        $diag = $strategy->diagnose($ctx, $planner);
        // bullish_confirmation MUST fail because of heavy down run and tiny body!
        $this->assertFalse($diag->criteria['bullish_confirmation']->passed);
        $this->assertNull($strategy->evaluate($ctx, $planner));
    }
}
