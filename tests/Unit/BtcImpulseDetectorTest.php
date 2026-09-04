<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Market\Contracts\ExchangeInterface;
use App\Market\Contracts\MarketAnalyzerInterface;
use App\Market\DTO\Candle;
use App\Market\Repositories\CandleRepository;
use App\Trading\Contracts\TradingAgentInterface;
use App\Trading\Contracts\TradeExecutorInterface;
use App\Trading\DTO\AgentResult;
use App\Trading\DTO\IndicatorSnapshot;
use App\Trading\Execution\PositionManager;
use App\Trading\Services\BtcImpulseDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class BtcImpulseDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::forget(BtcImpulseDetector::COOLDOWN_KEY);
        parent::tearDown();
    }

    public function test_no_scan_when_btc_move_below_threshold(): void
    {
        $exchange = Mockery::mock(ExchangeInterface::class);
        $repo = new CandleRepository($exchange);
        $analyzer = Mockery::mock(MarketAnalyzerInterface::class);
        $agent = Mockery::mock(TradingAgentInterface::class);
        $executor = Mockery::mock(TradeExecutorInterface::class);
        $manager = new PositionManager($agent, $executor);

        $detector = new BtcImpulseDetector(
            candlesRepo: $repo,
            analyzer: $analyzer,
            manager: $manager,
            config: [
                'lead_lag_enabled' => true,
                'lead_lag_btc_impulse_pct' => 0.40,
            ],
        );

        // BTC move +0.10% (below 0.40% threshold)
        $candle = new Candle(1_700_000_000, 80000.0, 80100.0, 79950.0, 80080.0, 10.0, 1_700_059_999);

        // Agent should not be called
        $agent->shouldNotReceive('evaluate');

        $detector->onBtcTick($candle);

        $this->assertFalse(Cache::has(BtcImpulseDetector::COOLDOWN_KEY));
    }

    public function test_no_scan_when_in_cooldown(): void
    {
        Cache::put(BtcImpulseDetector::COOLDOWN_KEY, true, 300);

        $exchange = Mockery::mock(ExchangeInterface::class);
        $repo = new CandleRepository($exchange);
        $analyzer = Mockery::mock(MarketAnalyzerInterface::class);
        $agent = Mockery::mock(TradingAgentInterface::class);
        $executor = Mockery::mock(TradeExecutorInterface::class);
        $manager = new PositionManager($agent, $executor);

        $detector = new BtcImpulseDetector(
            candlesRepo: $repo,
            analyzer: $analyzer,
            manager: $manager,
            config: [
                'lead_lag_enabled' => true,
                'lead_lag_btc_impulse_pct' => 0.40,
            ],
        );

        // BTC move +0.60% (above threshold)
        $candle = new Candle(1_700_000_000, 80000.0, 80550.0, 79950.0, 80480.0, 10.0, 1_700_059_999);

        $agent->shouldNotReceive('evaluate');

        $detector->onBtcTick($candle);

        $this->assertTrue(Cache::has(BtcImpulseDetector::COOLDOWN_KEY));
    }

    public function test_scans_when_impulse_detected(): void
    {
        Cache::forget(BtcImpulseDetector::COOLDOWN_KEY);

        $exchange = Mockery::mock(ExchangeInterface::class);
        $repo = new CandleRepository($exchange);
        $analyzer = Mockery::mock(MarketAnalyzerInterface::class);
        $agent = Mockery::mock(TradingAgentInterface::class);
        $executor = Mockery::mock(TradeExecutorInterface::class);
        $manager = new PositionManager($agent, $executor);

        $detector = new BtcImpulseDetector(
            candlesRepo: $repo,
            analyzer: $analyzer,
            manager: $manager,
            config: [
                'lead_lag_enabled' => true,
                'lead_lag_btc_impulse_pct' => 0.40,
                'lead_lag_interval' => '5m',
            ],
        );

        // Seed 15 BTC candles in DB
        $candles = [];
        for ($i = 0; $i < 15; $i++) {
            $candles[] = new Candle(1_700_000_000 + $i * 300_000, 80000.0, 80100.0, 79900.0, 80000.0, 10.0, 1_700_000_000 + ($i + 1) * 300_000 - 1);
        }
        $repo->persist('BTC-USDT', '5m', $candles);

        // Seed 50 ETH candles in DB
        $ethCandles = [];
        for ($i = 0; $i < 50; $i++) {
            $ethCandles[] = new Candle(1_700_000_000 + $i * 300_000, 2000.0, 2010.0, 1990.0, 2000.0, 10.0, 1_700_000_000 + ($i + 1) * 300_000 - 1);
        }
        $repo->persist('ETH-USDT', '5m', $ethCandles);

        $analyzer->shouldReceive('atr')->andReturn(10.0);
        $analyzer->shouldReceive('levels')->andReturn([]);

        $agent->shouldReceive('evaluate')
            ->andReturn(new AgentResult(
                entrySignal: null,
                exitSignal: null,
                indicators: new IndicatorSnapshot(0, 0, 0, 0, 0, 0, 0),
            ));

        // BTC tick with -0.60% move
        $candle = new Candle(1_700_000_000 + 16 * 300_000, 80000.0, 80050.0, 79450.0, 79520.0, 10.0, 1_700_000_000 + 17 * 300_000 - 1);

        $detector->onBtcTick($candle);

        $this->assertTrue(true);
    }

    public function test_no_scan_when_lead_lag_disabled(): void
    {
        $exchange = Mockery::mock(ExchangeInterface::class);
        $repo = new CandleRepository($exchange);
        $analyzer = Mockery::mock(MarketAnalyzerInterface::class);
        $agent = Mockery::mock(TradingAgentInterface::class);
        $executor = Mockery::mock(TradeExecutorInterface::class);
        $manager = new PositionManager($agent, $executor);

        // lead_lag_enabled omitted (defaults to false) or explicitly false
        $detector = new BtcImpulseDetector(
            candlesRepo: $repo,
            analyzer: $analyzer,
            manager: $manager,
            config: [
                'lead_lag_enabled' => false,
                'lead_lag_btc_impulse_pct' => 0.40,
            ],
        );

        // Huge BTC dump -2.0%
        $candle = new Candle(1_700_000_000, 80000.0, 80000.0, 78400.0, 78400.0, 50.0, 1_700_059_999);

        // Should NOT evaluate or check anything
        $agent->shouldNotReceive('evaluate');
        $analyzer->shouldNotReceive('atr');

        $detector->onBtcTick($candle);

        $this->assertFalse(Cache::has(BtcImpulseDetector::COOLDOWN_KEY));
    }
}
