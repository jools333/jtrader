<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Market\DTO\Candle;
use App\Models\Position;
use App\Trading\Agent\TradingAgent;
use App\Trading\Execution\PaperTradeExecutor;
use App\Trading\Execution\PositionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PositionManagerTest extends TestCase
{
    use RefreshDatabase;

    private int $t = 1_700_000_000_000;

    private function candle(float $o, float $h, float $l, float $c, float $vol = 1.0): Candle
    {
        $candle = new Candle($this->t, $o, $h, $l, $c, $vol, $this->t + 3_599_999);
        $this->t += 3_600_000;

        return $candle;
    }

    /** @return array<int, Candle> A bounce-short setup at resistance = 100. */
    private function bounceShortCandles(): array
    {
        $candles = [];
        for ($i = 0; $i < 48; $i++) {
            $c = 110.0 - (10.0 / 47.0) * $i;
            $candles[] = $this->candle($c - 0.1, $c + 0.5, $c - 0.5, $c);
        }
        // Prior impulse down through 100 to 93.5
        $candles[] = $this->candle(100.0, 100.2, 93.8, 94.0);
        $candles[] = $this->candle(94.0, 94.5, 93.0, 93.5);
        // Pullback into zone with compression
        $candles[] = $this->candle(94.0, 97.5, 93.8, 97.0);
        $candles[] = $this->candle(97.0, 99.8, 96.8, 99.5);
        $candles[] = $this->candle(99.5, 100.5, 99.0, 99.8);
        // Trigger candle rejecting level
        $candles[] = $this->candle(99.8, 100.5, 94.5, 95.0, 2000.0);

        return $candles;
    }

    private function manager(float $paperBalance = 1_000.0, float $riskPct = 1.0): PositionManager
    {
        return new PositionManager(
            agent: new TradingAgent((array) config('trading.agent')),
            executor: new PaperTradeExecutor(Log::getLogger(), $paperBalance),
            config: array_merge((array) config('trading'), [
                'risk_percent' => $riskPct,
                'paper_balance' => $paperBalance,
                'max_position_pct' => 0.0,
            ]),
        );
    }

    public function test_entry_signal_is_logged_as_an_open_position(): void
    {
        $result = $this->manager()->process('ETH-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);

        $this->assertNotNull($result->entrySignal);
        $this->assertDatabaseCount('positions', 1);

        $position = Position::first();
        $this->assertSame('SHORT', $position->direction);
        $this->assertSame('BOUNCE', $position->signal_type);
        $this->assertSame(Position::STATUS_OPEN, $position->status);
        // Entry rationale (signal + indicators) is persisted for audit.
        $this->assertArrayHasKey('signal', $position->entry_context);
        $this->assertArrayHasKey('indicators', $position->entry_context);
    }

    public function test_quantity_is_calculated_from_risk_percent(): void
    {
        // ATR=10, stop_atr=0.5 → stop buffer = 5.
        // Level=100 (resistance), SHORT: stop = 100 + 5 = 105, entry ≈ 98.2.
        // risk_per_unit ≈ 105 - 98.2 = 6.8.
        // balance=1000, risk_pct=1% → risk_amount = 10 USDT.
        // expected qty = 10 / 6.8 ≈ 1.4706, rounded to 4dp.
        $result = $this->manager(paperBalance: 1_000.0, riskPct: 1.0)
            ->process('ETH-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);

        $this->assertNotNull($result->entrySignal);
        $position = Position::first();
        $this->assertNotNull($position);

        $signal = $result->entrySignal;
        $riskPerUnit = abs($signal->entryPrice - $signal->stop);
        $expected = round((1_000.0 * 1.0 / 100.0) / $riskPerUnit, 4);

        $this->assertEqualsWithDelta($expected, $position->quantity, 0.0001);
    }

    public function test_quantity_uses_symbol_risk_pct_override(): void
    {
        $manager = new PositionManager(
            agent: new TradingAgent((array) config('trading.agent')),
            executor: new PaperTradeExecutor(Log::getLogger(), 1_000.0),
            config: array_merge((array) config('trading'), [
                'risk_percent' => 1.0,
                'symbol_risk_pct' => ['ADA-USDT' => 0.75],
                'paper_balance' => 1_000.0,
                'max_position_pct' => 0.0,
            ]),
        );

        $result = $manager->process('ADA-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);

        $this->assertNotNull($result->entrySignal);
        $position = Position::where('symbol', 'ADA-USDT')->first();
        $this->assertNotNull($position);

        $signal = $result->entrySignal;
        $riskPerUnit = abs($signal->entryPrice - $signal->stop);
        $expected = round((1_000.0 * 0.75 / 100.0) / $riskPerUnit, 4);

        $this->assertEqualsWithDelta($expected, $position->quantity, 0.0001);
    }

    public function test_quantity_uses_symbol_max_position_pct_override(): void
    {
        $manager = new PositionManager(
            agent: new TradingAgent((array) config('trading.agent')),
            executor: new PaperTradeExecutor(Log::getLogger(), 10_000.0),
            config: array_merge((array) config('trading'), [
                'risk_percent' => 5.0,
                'paper_balance' => 10_000.0,
                'max_position_pct' => 10.0,
                'symbol_max_position_pct' => ['DOGE-USDT' => 2.5],
            ]),
        );

        $result = $manager->process('DOGE-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);

        $this->assertNotNull($result->entrySignal);
        $position = Position::where('symbol', 'DOGE-USDT')->first();
        $this->assertNotNull($position);

        // Notional max at 2.5% of 10000 = $250.
        // At price ~100, max quantity is 250 / 100 = 2.5
        $signal = $result->entrySignal;
        $expectedMaxQty = round(10_000.0 * 2.5 / 100.0 / $signal->entryPrice, 4);

        $this->assertEqualsWithDelta($expectedMaxQty, $position->quantity, 0.0001);
    }

    public function test_open_position_is_closed_on_target(): void
    {
        $manager = $this->manager();
        $manager->process('ETH-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);
        $position = Position::first();

        // Price drops to the target (Target1).
        $target = $position->target2;
        $candles = $this->bounceShortCandles();
        $candles[] = $this->candle($target + 1, $target + 2, $target - 1, $target);

        $manager->process('ETH-USDT', '1h', $candles, 100.0, 10.0);

        $position->refresh();
        $this->assertSame(Position::STATUS_CLOSED, $position->status);
        // It could be Target2 or Target1 depending on evaluation order, both are equal.
        $this->assertContains($position->exit_type, ['TARGET2']);
        $this->assertNotNull($position->closed_at);
    }

    public function test_cooldown_prevents_new_entry(): void
    {
        // Position was recently closed (5 minutes ago)
        Position::create([
            'symbol' => 'ETH-USDT',
            'interval' => '1h',
            'direction' => 'SHORT',
            'signal_type' => 'BOUNCE',
            'status' => Position::STATUS_CLOSED,
            'entry_price' => 100.0,
            'stop_price' => 105.0,
            'target1' => 95.0,
            'target2' => 90.0,
            'quantity' => 1.0,
            'size' => 1.0,
            'opened_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(5),
        ]);

        $manager = new PositionManager(
            agent: new TradingAgent((array) config('trading.agent')),
            executor: new PaperTradeExecutor(Log::getLogger(), 1_000.0),
            config: array_merge((array) config('trading'), [
                'entry_cooldown_minutes' => 30,
            ]),
        );

        // Even with a valid bounce setup, entry is blocked during cooldown
        $result = $manager->process('ETH-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);

        $this->assertNull($result->entrySignal);
        $this->assertDatabaseCount('positions', 1);
    }

    public function test_excluded_symbol_is_not_opened(): void
    {
        $manager = new PositionManager(
            agent: new TradingAgent((array) config('trading.agent')),
            executor: new PaperTradeExecutor(Log::getLogger(), 1_000.0),
            config: array_merge((array) config('trading'), [
                'excluded_symbols' => ['BTC-USDT'],
            ]),
        );

        // Even with a valid signal, excluded symbol BTC-USDT does not create a position
        $result = $manager->process('BTC-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);

        $this->assertNotNull($result->entrySignal);
        $this->assertDatabaseCount('positions', 0);
    }

    public function test_max_open_positions_blocks_new_entries(): void
    {
        // Create 3 open positions on different symbols
        foreach (['ADA-USDT', 'SOL-USDT', 'LINK-USDT'] as $sym) {
            Position::create([
                'symbol' => $sym,
                'interval' => '1h',
                'direction' => 'SHORT',
                'signal_type' => 'BOUNCE',
                'status' => Position::STATUS_OPEN,
                'entry_price' => 100.0,
                'stop_price' => 105.0,
                'target1' => 95.0,
                'target2' => 90.0,
                'quantity' => 1.0,
                'size' => 1.0,
                'opened_at' => now()->subMinutes(15),
            ]);
        }

        $manager = new PositionManager(
            agent: new TradingAgent((array) config('trading.agent')),
            executor: new PaperTradeExecutor(Log::getLogger(), 1_000.0),
            config: array_merge((array) config('trading'), [
                'max_open_positions' => 3,
                'entry_cooldown_minutes' => 0,
            ]),
        );

        // When 3 positions are open, 4th entry is blocked
        $result = $manager->process('ETH-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);
        $this->assertDatabaseCount('positions', 3);

        // Close 1 position so only 2 remain open
        Position::where('symbol', 'ADA-USDT')->update([
            'status' => Position::STATUS_CLOSED,
            'closed_at' => now()->subHours(2),
        ]);

        // Now entry is allowed
        $manager->process('ETH-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);
        $this->assertDatabaseCount('positions', 4);
        $this->assertTrue(Position::where('symbol', 'ETH-USDT')->where('status', Position::STATUS_OPEN)->exists());
    }

    public function test_stop_loss_extended_cooldown(): void
    {
        // Position was closed via Stop Loss 45 minutes ago
        Position::create([
            'symbol' => 'ETH-USDT',
            'interval' => '1h',
            'direction' => 'SHORT',
            'signal_type' => 'BOUNCE',
            'status' => Position::STATUS_CLOSED,
            'entry_price' => 100.0,
            'stop_price' => 105.0,
            'target1' => 95.0,
            'target2' => 90.0,
            'quantity' => 1.0,
            'size' => 1.0,
            'exit_type' => 'STOP_LOSS',
            'exit_reason' => 'stop_loss_hit',
            'opened_at' => now()->subMinutes(60),
            'closed_at' => now()->subMinutes(45),
        ]);

        $manager = new PositionManager(
            agent: new TradingAgent((array) config('trading.agent')),
            executor: new PaperTradeExecutor(Log::getLogger(), 1_000.0),
            config: array_merge((array) config('trading'), [
                'entry_cooldown_minutes' => 30,
                'stop_loss_cooldown_minutes' => 60,
            ]),
        );

        // Standard 30m cooldown would have expired, but 60m SL cooldown blocks entry
        $manager->process('ETH-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);
        $this->assertDatabaseCount('positions', 1);

        // Advance closed_at to 65 minutes ago, opened_at to 6 hours ago
        Position::where('symbol', 'ETH-USDT')->update([
            'closed_at' => now()->subMinutes(65),
            'opened_at' => now()->subHours(6),
        ]);

        // Now entry is allowed
        $manager->process('ETH-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);
        $this->assertDatabaseCount('positions', 2);
    }

    public function test_daily_loss_limit_circuit_breaker(): void
    {
        // Closed position today with net loss -165 USDT
        Position::create([
            'symbol' => 'SOL-USDT',
            'interval' => '1h',
            'direction' => 'SHORT',
            'signal_type' => 'BOUNCE',
            'status' => Position::STATUS_CLOSED,
            'entry_price' => 100.0,
            'stop_price' => 105.0,
            'target1' => 95.0,
            'target2' => 90.0,
            'quantity' => 1.0,
            'size' => 1.0,
            'exit_type' => 'STOP_LOSS',
            'realized_pnl' => -160.0,
            'commission' => 5.0,
            'opened_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
        ]);

        $manager = new PositionManager(
            agent: new TradingAgent((array) config('trading.agent')),
            executor: new PaperTradeExecutor(Log::getLogger(), 1_000.0),
            config: array_merge((array) config('trading'), [
                'daily_loss_limit' => 150.0,
                'entry_cooldown_minutes' => 0,
            ]),
        );

        // Entry blocked because daily loss (-165 USDT) exceeds limit (150 USDT)
        $manager->process('ETH-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);
        $this->assertDatabaseCount('positions', 1);

        // When circuit breaker is disabled (0), entry proceeds
        $managerDisabled = new PositionManager(
            agent: new TradingAgent((array) config('trading.agent')),
            executor: new PaperTradeExecutor(Log::getLogger(), 1_000.0),
            config: array_merge((array) config('trading'), [
                'daily_loss_limit' => 0.0,
                'entry_cooldown_minutes' => 0,
            ]),
        );

        $managerDisabled->process('ETH-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);
        $this->assertDatabaseCount('positions', 2);
    }
}
