<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Market\DTO\Candle;
use App\Trading\Agent\RuleContext;
use App\Trading\Agent\TradePlanner;
use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;
use PHPUnit\Framework\TestCase;

class TradePlannerTest extends TestCase
{
    private function candle(float $price): Candle
    {
        return new Candle(1_700_000_000_000, $price, $price, $price, $price, 1.0, 1_700_000_300_000);
    }

    private function context(float $price, float $atr): RuleContext
    {
        $candles = [];
        for ($i = 0; $i < 50; $i++) {
            $candles[] = $this->candle($price);
        }

        return new RuleContext(
            candles: $candles,
            level: $price,
            atr: $atr,
            ema8: array_fill(0, 50, $price),
            ema21: array_fill(0, 50, $price),
            ema50: array_fill(0, 50, $price),
            macd: [
                'line' => array_fill(0, 50, 0.0),
                'signal' => array_fill(0, 50, 0.0),
                'histogram' => array_fill(0, 50, 0.0),
            ],
            symbol: 'SOL-USDT',
            interval: '5m',
        );
    }

    public function test_hard_cap_limits_stop_distance_to_max_stop_percent(): void
    {
        $planner = new TradePlanner([
            'max_stop_percent' => 1.2,
        ]);

        $ctx = $this->context(100.0, 2.0); // entry = 100, ATR = 2.0 (2% of price)
        
        // Pass a wide technical stop at 95.0 (5% distance)
        $signal = $planner->plan($ctx, SignalType::Bounce, Direction::Long, stopPrice: 95.0);

        $this->assertNotNull($signal);
        $stopDistPct = (100.0 - $signal->stop) / 100.0 * 100.0;

        // Hard cap of 1.2% must be enforced, so stop must be at 98.8 (1.2% distance)
        $this->assertEqualsWithDelta(1.2, $stopDistPct, 0.001);
        $this->assertEqualsWithDelta(98.8, $signal->stop, 0.001);
    }

    public function test_closer_technical_stop_is_preserved_under_cap(): void
    {
        $planner = new TradePlanner([
            'max_stop_percent' => 1.2,
        ]);

        $ctx = $this->context(100.0, 1.0);
        
        // Technical stop at 99.4 (0.6% distance, well below 1.2% cap)
        $signal = $planner->plan($ctx, SignalType::Bounce, Direction::Long, stopPrice: 99.4);

        $this->assertNotNull($signal);
        $this->assertEqualsWithDelta(99.4, $signal->stop, 0.001);
    }

    public function test_short_stop_distance_is_capped_at_max_stop_percent(): void
    {
        $planner = new TradePlanner([
            'max_stop_percent' => 1.2,
        ]);

        $ctx = $this->context(100.0, 3.0); // high ATR
        
        // Technical stop at 104.0 (4% distance)
        $signal = $planner->plan($ctx, SignalType::Bounce, Direction::Short, stopPrice: 104.0);

        $this->assertNotNull($signal);
        // Capped at 100 * 1.012 = 101.2
        $this->assertEqualsWithDelta(101.2, $signal->stop, 0.001);
    }

    public function test_rr_mode_targets_at_least_1_point_5_r(): void
    {
        $planner = new TradePlanner([
            'tp_mode' => 'rr',
            'target1_r' => 1.5,
        ]);

        $ctx = $this->context(100.0, 1.0);
        // Technical stop at 99.2 (0.8 distance)
        $signal = $planner->plan($ctx, SignalType::Bounce, Direction::Long, stopPrice: 99.2);

        $this->assertNotNull($signal);
        $stopDist = 100.0 - $signal->stop; // 0.8
        $tpDist = $signal->target1 - 100.0; // 0.8 * 1.5 = 1.2 -> target1 = 101.2
        $this->assertEqualsWithDelta(0.8, $stopDist, 0.001);
        $this->assertEqualsWithDelta(1.2, $tpDist, 0.001);
        $this->assertEqualsWithDelta(101.2, $signal->target1, 0.001);
    }

    public function test_quick_mode_respects_quick_min_r(): void
    {
        $planner = new TradePlanner([
            'tp_mode' => 'quick',
            'tp_percent' => 0.35,
            'quick_min_r' => 1.0,
        ]);

        $ctx = $this->context(100.0, 1.0);
        // Technical stop at 99.0 (1.0 distance)
        $signal = $planner->plan($ctx, SignalType::Bounce, Direction::Long, stopPrice: 99.0);

        $this->assertNotNull($signal);
        $stopDist = 100.0 - $signal->stop; // 1.0
        $tpDist = $signal->target1 - 100.0;
        // Even though tp_percent is 0.35%, quick_min_r enforces tpDist >= 1.0 * stopDist = 1.0
        $this->assertGreaterThanOrEqual(1.0, $tpDist);
    }
}
