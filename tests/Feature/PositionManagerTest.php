<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Position;
use App\Market\DTO\Candle;
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
        $result = $this->manager()->process('BTC-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);

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
            ->process('BTC-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);

        $this->assertNotNull($result->entrySignal);
        $position = Position::first();
        $this->assertNotNull($position);

        $signal = $result->entrySignal;
        $riskPerUnit = abs($signal->entryPrice - $signal->stop);
        $expected = round((1_000.0 * 1.0 / 100.0) / $riskPerUnit, 4);

        $this->assertEqualsWithDelta($expected, $position->quantity, 0.0001);
    }

    public function test_open_position_is_closed_on_target(): void
    {
        $manager = $this->manager();
        $manager->process('BTC-USDT', '1h', $this->bounceShortCandles(), 100.0, 10.0);
        $position = Position::first();

        // Price drops to the target (Target1).
        $target = $position->target1;
        $candles = $this->bounceShortCandles();
        $candles[] = $this->candle($target + 1, $target + 2, $target - 1, $target);

        $manager->process('BTC-USDT', '1h', $candles, 100.0, 10.0);

        $position->refresh();
        $this->assertSame(Position::STATUS_CLOSED, $position->status);
        // It could be Target2 or Target1 depending on evaluation order, both are equal.
        $this->assertContains($position->exit_type, ['TARGET1', 'TARGET2']);
        $this->assertNotNull($position->closed_at);
    }
}
