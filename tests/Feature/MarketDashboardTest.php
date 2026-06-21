<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\MarketDashboard;
use App\Models\Candle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function seedCandles(string $symbol = 'BTC-USDT', string $interval = '1h', int $count = 160): void
    {
        $rows = [];
        $t = 1_700_000_000_000;
        $price = 60_000.0;

        for ($i = 0; $i < $count; $i++) {
            // Gentle uptrend with an oscillation so pivots/levels have material.
            $price += 25 + 200 * sin($i / 6);
            $high = $price + 80;
            $low = $price - 80;
            $open = $price - 20;
            $close = $price + 20;

            $rows[] = [
                'symbol' => $symbol,
                'interval' => $interval,
                'open_time' => $t,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => 100 + $i,
                'close_time' => $t + 3_599_999,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $t += 3_600_000;
        }

        Candle::insert($rows);
    }

    public function test_dashboard_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/market-dashboard')
            ->assertOk()
            ->assertSee('Аналитика рынка')
            ->assertSee('Уровни')
            ->assertSee('Фигуры')
            ->assertSee('ATR');
    }

    public function test_market_data_returns_candles_and_analysis(): void
    {
        $this->seedCandles();

        $payload = (new MarketDashboard())->marketData('BTC-USDT', '1h');

        $this->assertSame('BTC-USDT', $payload['symbol']);
        $this->assertSame('1h', $payload['interval']);
        $this->assertSame('BingX', $payload['exchange']);

        $this->assertNotEmpty($payload['candles']);
        $first = $payload['candles'][0];
        $this->assertArrayHasKey('time', $first);
        $this->assertArrayHasKey('open', $first);
        $this->assertArrayHasKey('volume', $first);

        $this->assertGreaterThan(0, $payload['atr']);
        $this->assertArrayHasKey('direction', $payload['trend']);
        $this->assertIsArray($payload['levels']);
        $this->assertLessThanOrEqual(4, count($payload['levels']));
        $this->assertIsArray($payload['patterns']);
        // Ticker needs a live exchange call; in tests it degrades to null.
        $this->assertArrayHasKey('ticker', $payload);
    }

    public function test_invalid_symbol_falls_back_to_default(): void
    {
        $payload = (new MarketDashboard())->marketData('HACK-ME', 'bogus');

        $this->assertSame(config('exchange.default_pair'), $payload['symbol']);
        $this->assertSame(config('exchange.default_timeframe'), $payload['interval']);
    }
}
