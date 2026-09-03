<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Position;
use App\Trading\Enums\Direction;
use App\Trading\Enums\ExitType;
use App\Trading\Services\BingXPositionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PositionSyncTest extends TestCase
{
    use RefreshDatabase;

    private array $bingxConfig = [
        'api_key' => 'test-key',
        'api_secret' => 'test-secret',
        'base_url' => 'https://open-api.bingx.com',
        'base_url_demo' => 'https://open-api-vst.bingx.com',
        'demo' => true,
    ];

    public function test_sync_imports_new_open_position_from_bingx(): void
    {
        Http::fake([
            '*/openApi/swap/v2/user/positions*' => Http::response([
                'code' => 0,
                'msg' => '',
                'data' => [
                    [
                        'symbol' => 'ADA-USDT',
                        'positionSide' => 'LONG',
                        'positionAmt' => '250.0',
                        'entryPrice' => '0.3452',
                        'leverage' => 10,
                        'positionId' => 'pos_12345',
                        'updateTime' => 1788400000000,
                    ],
                ],
            ]),
            '*/openApi/swap/v2/trade/allOrders*' => Http::response(['code' => 0, 'data' => ['orders' => []]]),
            '*/openApi/swap/v2/user/income*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $service = new BingXPositionSyncService(
            http: app(\Illuminate\Http\Client\Factory::class),
            config: $this->bingxConfig,
        );

        $result = $service->sync();

        $this->assertEquals(1, $result->imported);
        $this->assertDatabaseHas('positions', [
            'symbol' => 'ADA-USDT',
            'direction' => 'LONG',
            'status' => 'open',
            'signal_type' => 'EXTERNAL',
            'entry_price' => 0.3452,
            'quantity' => 250.0,
            'leverage' => 10,
            'external_id' => 'pos_12345',
        ]);
    }

    public function test_sync_updates_existing_open_position(): void
    {
        $pos = Position::create([
            'symbol' => 'SOL-USDT',
            'interval' => '5m',
            'direction' => 'SHORT',
            'signal_type' => 'BOUNCE',
            'status' => Position::STATUS_OPEN,
            'entry_price' => 135.0,
            'stop_price' => 140.0,
            'target1' => 125.0,
            'target2' => 120.0,
            'quantity' => 1.0,
            'size' => 1.0,
            'opened_at' => now()->subHour(),
        ]);

        Http::fake([
            '*/openApi/swap/v2/user/positions*' => Http::response([
                'code' => 0,
                'msg' => '',
                'data' => [
                    [
                        'symbol' => 'SOL-USDT',
                        'positionSide' => 'SHORT',
                        'positionAmt' => '-1.5',
                        'entryPrice' => '134.80',
                        'leverage' => 5,
                        'positionId' => 'sol_pos_1',
                        'updateTime' => 1788401000000,
                    ],
                ],
            ]),
            '*/openApi/swap/v2/trade/allOrders*' => Http::response(['code' => 0, 'data' => ['orders' => []]]),
            '*/openApi/swap/v2/user/income*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $service = new BingXPositionSyncService(
            http: app(\Illuminate\Http\Client\Factory::class),
            config: $this->bingxConfig,
        );

        $result = $service->sync();

        $this->assertEquals(1, $result->updated);

        $pos->refresh();
        $this->assertEquals(1.5, $pos->quantity);
        $this->assertEquals(134.80, $pos->entry_price);
        $this->assertEquals(5, $pos->leverage);
        $this->assertEquals('sol_pos_1', $pos->external_id);
        $this->assertNotNull($pos->synced_at);
    }

    public function test_sync_detects_closed_position_on_bingx_and_attaches_fees_and_pnl(): void
    {
        $pos = Position::create([
            'symbol' => 'LINK-USDT',
            'interval' => '5m',
            'direction' => 'LONG',
            'signal_type' => 'BOUNCE',
            'status' => Position::STATUS_OPEN,
            'entry_price' => 11.0,
            'stop_price' => 10.5,
            'target1' => 12.0,
            'target2' => 13.0,
            'quantity' => 10.0,
            'size' => 1.0,
            'opened_at' => \Illuminate\Support\Carbon::createFromTimestampMs(1788400000000),
        ]);

        Http::fake([
            // Exchange reports zero open positions for LINK-USDT -> it was closed!
            '*/openApi/swap/v2/user/positions*' => Http::response([
                'code' => 0,
                'msg' => '',
                'data' => [],
            ]),
            '*/openApi/swap/v2/trade/allOrders*' => Http::response([
                'code' => 0,
                'data' => [
                    'orders' => [
                        [
                            'orderId' => 'order_tp_999',
                            'symbol' => 'LINK-USDT',
                            'side' => 'SELL',
                            'positionSide' => 'LONG',
                            'type' => 'TAKE_PROFIT_MARKET',
                            'status' => 'FILLED',
                            'avgPrice' => '12.05',
                            'executedQty' => '10.0',
                            'profit' => '10.50',
                            'commission' => '-0.06',
                            'reduceOnly' => true,
                            'updateTime' => 1788402000000,
                        ],
                    ],
                ],
            ]),
            '*/openApi/swap/v2/user/income*' => Http::response([
                'code' => 0,
                'data' => [
                    [
                        'symbol' => 'LINK-USDT',
                        'incomeType' => 'REALIZED_PNL',
                        'income' => '10.50000000',
                        'time' => 1788402000000,
                    ],
                    [
                        'symbol' => 'LINK-USDT',
                        'incomeType' => 'TRADING_FEE',
                        'income' => '-0.06000000',
                        'time' => 1788402000000,
                    ],
                    [
                        'symbol' => 'LINK-USDT',
                        'incomeType' => 'TRADING_FEE',
                        'income' => '-0.04000000',
                        'time' => 1788395000000,
                    ],
                    [
                        'symbol' => 'LINK-USDT',
                        'incomeType' => 'FUNDING_FEE',
                        'income' => '0.02000000',
                        'time' => 1788400000000,
                    ],
                ],
            ]),
        ]);

        $service = new BingXPositionSyncService(
            http: app(\Illuminate\Http\Client\Factory::class),
            config: $this->bingxConfig,
        );

        $result = $service->sync();

        $this->assertEquals(1, $result->closed);

        $pos->refresh();
        $this->assertEquals(Position::STATUS_CLOSED, $pos->status);
        $this->assertEquals(12.05, $pos->exit_price);
        $this->assertEquals(10.50, $pos->realized_pnl);
        $this->assertEquals(0.10, $pos->commission); // 0.06 + 0.04
        $this->assertEquals(0.02, $pos->funding_fee);
        $this->assertEquals(ExitType::Target1->value, $pos->exit_type);
        $this->assertEquals('take_profit_hit', $pos->exit_reason);
        $this->assertEquals('order_tp_999', $pos->exit_order_id);
        $this->assertNotNull($pos->synced_at);

        // Net PnL = 10.50 - 0.10 - 0.02 = 10.38
        $this->assertEquals(10.38, $pos->netPnl());
    }

    public function test_positions_sync_artisan_command(): void
    {
        Http::fake([
            '*/openApi/swap/v2/user/positions*' => Http::response([
                'code' => 0,
                'data' => [],
            ]),
            '*/openApi/swap/v2/trade/allOrders*' => Http::response(['code' => 0, 'data' => ['orders' => []]]),
            '*/openApi/swap/v2/user/income*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $this->artisan('positions:sync', ['--days' => 1])
            ->assertSuccessful()
            ->expectsOutputToContain('Position synchronization finished.');
    }
}
