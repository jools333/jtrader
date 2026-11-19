<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Market\DTO\Candle;
use App\Trading\Agent\RuleContext;
use App\Trading\Agent\TradePlanner;
use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;
use App\Trading\Strategies\Entry\SqueezeBreakoutStrategy;
use PHPUnit\Framework\TestCase;

class SqueezeBreakoutStrategyTest extends TestCase
{
    private int $t = 1_700_000_000_000;

    private function candle(float $o, float $h, float $l, float $c, float $v = 100.0): Candle
    {
        $candle = new Candle($this->t, $o, $h, $l, $c, $v, $this->t + 14_399_999);
        $this->t += 14_400_000;

        return $candle;
    }

    /**
     * 4H series: volatile swings first (wide BBWidth), then tight squeeze near 110,
     * followed by a high-volume bullish breakout bar above BB(20, 2.0) and EMA50.
     *
     * @return array<int, Candle>
     */
    private function longBreakoutCandles(bool $withVolume = true, bool $withSqueeze = true): array
    {
        $candles = [];

        // 1. 55 bars with wide volatility (sets high historical BBWidth baseline) and rising drift to ~110.
        $base = 95.0;
        for ($i = 0; $i < 55; $i++) {
            $wave = $withSqueeze ? (($i % 2 === 0) ? 4.5 : -4.5) : 0.0;
            $c = $base + $i * 0.25 + $wave;
            $candles[] = $this->candle($c - 0.5, $c + 2.0, $c - 2.0, $c, 100.0);
        }

        // 2. 22 bars of tight compression around 110.0 (BBWidth drops into bottom 35%).
        for ($i = 0; $i < 22; $i++) {
            $offset = $withSqueeze ? (($i % 2 === 0) ? 0.15 : -0.15) : (($i % 2 === 0) ? 5.0 : -5.0);
            $c = 110.0 + $offset;
            $span = $withSqueeze ? 0.35 : 3.0;
            $candles[] = $this->candle($c - 0.05, $c + $span, $c - $span, $c, 100.0);
        }

        // 3. Breakout bar closing cleanly above upper Bollinger Band & EMA50.
        $vol = $withVolume ? 200.0 : 80.0;
        $candles[] = $this->candle(110.1, 114.2, 110.0, 113.8, $vol);

        return $candles;
    }

    /**
     * @param  array<int, Candle>  $candles
     */
    private function context(array $candles, string $interval = '4h'): RuleContext
    {
        $n = count($candles);

        return new RuleContext(
            candles: $candles,
            level: 110.0,
            atr: 1.5,
            ema8: array_fill(0, $n, 110.0),
            ema21: array_fill(0, $n, 109.0),
            ema50: array_fill(0, $n, 105.0),
            macd: [
                'line' => array_fill(0, $n, 0.0),
                'signal' => array_fill(0, $n, 0.0),
                'histogram' => array_fill(0, $n, 0.0),
            ],
            symbol: 'ETH-USDT',
            interval: $interval,
        );
    }

    public function test_generates_long_squeeze_breakout_without_fixed_tp_cap(): void
    {
        $candles = $this->longBreakoutCandles();
        $strategy = new SqueezeBreakoutStrategy;

        $signal = $strategy->evaluate($this->context($candles), new TradePlanner);

        $this->assertNotNull($signal);
        $this->assertSame(SignalType::SqueezeBreakout, $signal->type);
        $this->assertSame(Direction::Long, $signal->direction);
        $this->assertLessThan($signal->entryPrice, $signal->stop);
        // No fixed 2R profit cap — exits via Chandelier ATR trailing stop.
        $this->assertSame(0.0, $signal->target1);
        $this->assertSame(0.0, $signal->target2);
    }

    public function test_rejects_breakout_without_volume_confirmation(): void
    {
        $candles = $this->longBreakoutCandles(withVolume: false);
        $strategy = new SqueezeBreakoutStrategy;

        $this->assertNull($strategy->evaluate($this->context($candles), new TradePlanner));
    }

    public function test_inactive_on_1h_timeframe(): void
    {
        $candles = $this->longBreakoutCandles();
        $strategy = new SqueezeBreakoutStrategy;

        $this->assertNull($strategy->evaluate($this->context($candles, '1h'), new TradePlanner));
    }
}
