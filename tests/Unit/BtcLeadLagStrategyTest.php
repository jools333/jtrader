<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Market\DTO\Candle;
use App\Trading\Agent\RuleContext;
use App\Trading\Agent\TradePlanner;
use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;
use App\Trading\Strategies\Entry\BtcLeadLagStrategy;
use PHPUnit\Framework\TestCase;

class BtcLeadLagStrategyTest extends TestCase
{
    private int $t = 1_700_000_000_000;

    private function candle(float $o, float $h, float $l, float $c, float $v = 100.0): Candle
    {
        $candle = new Candle($this->t, $o, $h, $l, $c, $v, $this->t + 299_999);
        $this->t += 300_000;

        return $candle;
    }

    /**
     * @return array<int, Candle>
     */
    private function series(int $n, float $price, float $vol = 100.0): array
    {
        $res = [];
        for ($i = 0; $i < $n; $i++) {
            $res[] = $this->candle($price, $price + 0.5, $price - 0.5, $price, $vol);
        }

        return $res;
    }

    private function createContext(
        array $altCandles,
        array $btcCandles,
        string $symbol = 'ETH-USDT',
        float $level = 2000.0,
        float $atr = 10.0,
    ): RuleContext {
        $closes = array_map(static fn (Candle $c) => $c->close, $altCandles);
        $ema50 = array_fill(0, count($closes), end($closes));
        $ema21 = array_fill(0, count($closes), end($closes));
        $ema8 = array_fill(0, count($closes), end($closes));
        $macd = [
            'line' => array_fill(0, count($closes), 0.0),
            'signal' => array_fill(0, count($closes), 0.0),
            'histogram' => array_fill(0, count($closes), 0.0),
        ];

        return new RuleContext(
            candles: $altCandles,
            level: $level,
            atr: $atr,
            ema8: $ema8,
            ema21: $ema21,
            ema50: $ema50,
            macd: $macd,
            symbol: $symbol,
            interval: '5m',
            btcCandles: $btcCandles,
        );
    }

    public function test_short_signal_when_btc_dumps_and_alt_lags(): void
    {
        // Altcoin flat around 2000.0
        $altCandles = $this->series(20, 2000.0);
        // Alt dropped slightly by -0.05% on the last candle
        $altCandles[count($altCandles) - 1] = $this->candle(2000.0, 2001.0, 1998.0, 1999.0);

        // BTC had 20 candles at 80000, then dumped to 79400 (-0.75%)
        $btcCandles = $this->series(20, 80000.0);
        $btcCandles[count($btcCandles) - 1] = $this->candle(80000.0, 80050.0, 79350.0, 79400.0, 300.0); // volume spike

        $ctx = $this->createContext($altCandles, $btcCandles, 'ETH-USDT', 1950.0, 15.0);
        $strategy = new BtcLeadLagStrategy(
            minEntryScore: 70.0,
            btcImpulsePct: 0.40,
            minGapPct: 0.25,
        );
        $planner = new TradePlanner();

        $signal = $strategy->evaluate($ctx, $planner);

        $this->assertNotNull($signal);
        $this->assertSame(SignalType::BtcLeadLag, $signal->type);
        $this->assertSame(Direction::Short, $signal->direction);
        $this->assertSame(1999.0, $signal->entryPrice);
        $this->assertGreaterThan($signal->entryPrice, $signal->stop);
        $this->assertLessThan($signal->entryPrice, $signal->target1);
    }

    public function test_long_signal_when_btc_pumps_and_alt_lags(): void
    {
        // Altcoin flat around 2000.0
        $altCandles = $this->series(20, 2000.0);
        $altCandles[count($altCandles) - 1] = $this->candle(2000.0, 2002.0, 1999.0, 2001.0); // +0.05%

        // BTC had 20 candles at 80000, then pumped to 80600 (+0.75%)
        $btcCandles = $this->series(20, 80000.0);
        $btcCandles[count($btcCandles) - 1] = $this->candle(80000.0, 80650.0, 79950.0, 80600.0, 300.0);

        $ctx = $this->createContext($altCandles, $btcCandles, 'ETH-USDT', 2050.0, 15.0);
        $strategy = new BtcLeadLagStrategy(
            minEntryScore: 70.0,
            btcImpulsePct: 0.40,
            minGapPct: 0.25,
        );
        $planner = new TradePlanner();

        $signal = $strategy->evaluate($ctx, $planner);

        $this->assertNotNull($signal);
        $this->assertSame(SignalType::BtcLeadLag, $signal->type);
        $this->assertSame(Direction::Long, $signal->direction);
        $this->assertSame(2001.0, $signal->entryPrice);
        $this->assertLessThan($signal->entryPrice, $signal->stop);
        $this->assertGreaterThan($signal->entryPrice, $signal->target1);
    }

    public function test_no_signal_when_alt_has_already_moved(): void
    {
        // Altcoin already dropped by -0.70% (no lag gap with BTC)
        $altCandles = $this->series(20, 2000.0);
        $altCandles[count($altCandles) - 1] = $this->candle(2000.0, 2001.0, 1985.0, 1986.0); // -0.70%

        // BTC dumped -0.75%
        $btcCandles = $this->series(20, 80000.0);
        $btcCandles[count($btcCandles) - 1] = $this->candle(80000.0, 80050.0, 79350.0, 79400.0);

        $ctx = $this->createContext($altCandles, $btcCandles, 'ETH-USDT', 1950.0, 15.0);
        $strategy = new BtcLeadLagStrategy(
            minEntryScore: 70.0,
            btcImpulsePct: 0.40,
            minGapPct: 0.25,
        );
        $planner = new TradePlanner();

        $signal = $strategy->evaluate($ctx, $planner);

        $this->assertNull($signal);
    }

    public function test_no_short_when_alt_is_in_strong_bull_pump(): void
    {
        $altCandles = $this->series(20, 2000.0);
        // Altcoin pumping hard to 2050
        $altCandles[count($altCandles) - 1] = $this->candle(2010.0, 2055.0, 2005.0, 2050.0);

        // BTC dumped -0.75%
        $btcCandles = $this->series(20, 80000.0);
        $btcCandles[count($btcCandles) - 1] = $this->candle(80000.0, 80050.0, 79350.0, 79400.0);

        // EMA50 at 2000, price at 2050 (> EMA50 + 0.3 ATR), positive MACD histogram
        $closes = array_map(static fn (Candle $c) => $c->close, $altCandles);
        $ema50 = array_fill(0, count($closes), 2000.0);
        $ema21 = array_fill(0, count($closes), 2010.0);
        $ema8 = array_fill(0, count($closes), 2020.0);
        $macd = [
            'line' => array_fill(0, count($closes), 5.0),
            'signal' => array_fill(0, count($closes), 2.0),
            'histogram' => array_fill(0, count($closes), 3.0),
        ];

        $ctx = new RuleContext(
            candles: $altCandles,
            level: 1950.0,
            atr: 10.0,
            ema8: $ema8,
            ema21: $ema21,
            ema50: $ema50,
            macd: $macd,
            symbol: 'ETH-USDT',
            interval: '5m',
            btcCandles: $btcCandles,
        );

        $strategy = new BtcLeadLagStrategy(
            minEntryScore: 70.0,
            btcImpulsePct: 0.40,
            minGapPct: 0.25,
        );
        $planner = new TradePlanner();

        $signal = $strategy->evaluate($ctx, $planner);

        // Blocked by structure guard (relative strength)
        $this->assertNull($signal);
    }

    public function test_ignored_for_btc_usdt_symbol(): void
    {
        $altCandles = $this->series(20, 80000.0);
        $btcCandles = $this->series(20, 80000.0);
        $btcCandles[count($btcCandles) - 1] = $this->candle(80000.0, 80050.0, 79350.0, 79400.0);

        $ctx = $this->createContext($altCandles, $btcCandles, 'BTC-USDT', 78000.0, 100.0);
        $strategy = new BtcLeadLagStrategy();
        $planner = new TradePlanner();

        $this->assertNull($strategy->evaluate($ctx, $planner));
    }
}
