<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Market\DTO\Candle;
use App\Trading\Agent\EntryGuard;
use App\Trading\Agent\RuleContext;
use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;
use PHPUnit\Framework\TestCase;

class EntryGuardTest extends TestCase
{
    private function candle(float $price): Candle
    {
        return new Candle(1_700_000_000_000, $price, $price + 0.5, $price - 0.5, $price, 100.0, 1_700_000_300_000);
    }

    private function createContext(
        array $btcCandles,
        array $btcHtfCandles = [],
        ?array $btcHtfEma8 = null,
        ?array $btcHtfEma21 = null,
        ?array $btcHtfEma50 = null,
    ): RuleContext {
        $candles = [];
        for ($i = 0; $i < 50; $i++) {
            $candles[] = new Candle(1_700_000_000_000 + $i * 300_000, 10.0, 10.5, 9.5, 10.0, 100.0, 1_700_000_000_000 + ($i + 1) * 300_000);
        }

        return new RuleContext(
            candles: $candles,
            level: 10.0,
            atr: 0.5,
            ema8: array_fill(0, 50, 10.0),
            ema21: array_fill(0, 50, 10.0),
            ema50: array_fill(0, 50, 10.0),
            macd: [
                'line' => array_fill(0, 50, 0.0),
                'signal' => array_fill(0, 50, 0.0),
                'histogram' => array_fill(0, 50, 0.0),
            ],
            symbol: 'SOL-USDT',
            interval: '5m',
            btcCandles: $btcCandles,
            btcEma8: array_fill(0, count($btcCandles), 80000.0),
            btcEma21: array_fill(0, count($btcCandles), 79500.0),
            btcEma50: array_fill(0, count($btcCandles), 79000.0),
            btcHtfCandles: $btcHtfCandles,
            btcHtfEma8: $btcHtfEma8,
            btcHtfEma21: $btcHtfEma21,
            btcHtfEma50: $btcHtfEma50,
        );
    }

    public function test_allows_when_htf_btc_is_aligned(): void
    {
        $guard = new EntryGuard([
            'btc_filter_enabled' => true,
            'btc_htf_filter_enabled' => true,
        ]);

        $btc5m = [$this->candle(80000), $this->candle(80050), $this->candle(80100)];
        $btcHtf = [$this->candle(80000), $this->candle(80100), $this->candle(80200)];

        // Bullish HTF: price (80200) > EMA50 (79000), EMA8 (80100) > EMA21 (79500)
        $ctx = $this->createContext(
            btcCandles: $btc5m,
            btcHtfCandles: $btcHtf,
            btcHtfEma8: [79900.0, 80000.0, 80100.0],
            btcHtfEma21: [79400.0, 79450.0, 79500.0],
            btcHtfEma50: [79000.0, 79000.0, 79000.0],
        );

        $this->assertTrue($guard->allows($ctx, Direction::Long, SignalType::Bounce));
    }

    public function test_blocks_altcoin_long_when_btc_htf_is_below_ema50(): void
    {
        $guard = new EntryGuard([
            'btc_filter_enabled' => true,
            'btc_htf_filter_enabled' => true,
        ]);

        // On 5m BTC made a slight local bounce, so 5m return is positive
        $btc5m = [$this->candle(78000), $this->candle(78100), $this->candle(78200)];
        // On 1h BTC is 78200, but EMA50 is 79000 (bearish macro regime)
        $btcHtf = [$this->candle(78000), $this->candle(78100), $this->candle(78200)];

        $ctx = $this->createContext(
            btcCandles: $btc5m,
            btcHtfCandles: $btcHtf,
            btcHtfEma8: [78000.0, 78100.0, 78150.0],
            btcHtfEma21: [78200.0, 78150.0, 78100.0],
            btcHtfEma50: [79000.0, 79000.0, 79000.0], // Price 78200 < EMA50 79000
        );

        // LONG must be BLOCKED!
        $this->assertFalse($guard->allows($ctx, Direction::Long, SignalType::Bounce));
    }

    public function test_blocks_altcoin_short_when_btc_htf_is_above_ema50(): void
    {
        $guard = new EntryGuard([
            'btc_filter_enabled' => true,
            'btc_htf_filter_enabled' => true,
        ]);

        // On 5m BTC pulled back slightly, so 5m return is negative
        $btc5m = [$this->candle(81000), $this->candle(80950), $this->candle(80900)];
        // On 1h BTC is 80900, but EMA50 is 79000 (bullish macro regime)
        $btcHtf = [$this->candle(80800), $this->candle(80850), $this->candle(80900)];

        $ctx = $this->createContext(
            btcCandles: $btc5m,
            btcHtfCandles: $btcHtf,
            btcHtfEma8: [80800.0, 80850.0, 80900.0],
            btcHtfEma21: [80000.0, 80100.0, 80200.0],
            btcHtfEma50: [79000.0, 79000.0, 79000.0], // Price 80900 > EMA50 79000
        );

        // SHORT must be BLOCKED!
        $this->assertFalse($guard->allows($ctx, Direction::Short, SignalType::Bounce));
    }

    public function test_htf_regimes_are_mutually_exclusive_preventing_deadlock(): void
    {
        $guard = new EntryGuard([
            'btc_filter_enabled' => true,
            'btc_htf_filter_enabled' => true,
        ]);

        // 5m BTC is stable
        $btc5m = [$this->candle(80000), $this->candle(80050), $this->candle(80100)];
        // 1h BTC is 80100 (above EMA50 79000), but EMA8 (79400) is below EMA21 (79500)
        $btcHtf = [$this->candle(80000), $this->candle(80050), $this->candle(80100)];

        $ctx = $this->createContext(
            btcCandles: $btc5m,
            btcHtfCandles: $btcHtf,
            btcHtfEma8: [79600.0, 79500.0, 79400.0],
            btcHtfEma21: [79500.0, 79500.0, 79500.0], // EMA8 < EMA21
            btcHtfEma50: [79000.0, 79000.0, 79000.0], // Price 80100 > EMA50 79000
        );

        // Bullish macro regime: price > EMA50
        $this->assertTrue($ctx->btcHtfTrendBullish());
        // Must NOT also be Bearish!
        $this->assertFalse($ctx->btcHtfTrendBearish());

        // LONG is allowed (not deadlocked)
        $this->assertTrue($guard->allows($ctx, Direction::Long, SignalType::Bounce));
    }
}
