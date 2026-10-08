<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Trading\DTO\EntrySignal;
use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;
use App\Trading\Execution\BingX\BingXTradeExecutor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BingXTradeExecutorTest extends TestCase
{
    public function test_open_position_formats_brackets_and_quantities_with_exact_symbol_precision(): void
    {
        Http::fake([
            'https://open-api.bingx.com/openApi/swap/v2/trade/allOpenOrders*' => Http::response(['code' => 0, 'data' => []]),
            'https://open-api.bingx.com/openApi/swap/v2/trade/order*' => Http::response([
                'code' => 0,
                'data' => ['order' => ['orderId' => 123456789]],
            ]),
        ]);

        $executor = new BingXTradeExecutor(
            http: $this->app->make(\Illuminate\Http\Client\Factory::class),
            config: [
                'api_key' => 'fake-key',
                'api_secret' => 'fake-secret',
                'entry_order_type' => 'MARKET',
                'base_url' => 'https://open-api.bingx.com',
            ],
        );

        $signal = new EntrySignal(
            type: SignalType::Bounce,
            direction: Direction::Short,
            entryPrice: 0.2543,
            stop: 0.255502475,
            target1: 0.251896321,
            target2: 0.249492100,
            rrRatio: 2.0,
        );

        $res = $executor->openPosition($signal, 'ADA-USDT', 11529.85);
        $this->assertTrue($res->ok);
        $this->assertSame('123456789', $res->orderId);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/openApi/swap/v2/trade/order')) {
                return false;
            }

            $data = $request->data();

            // Quantity must be floored to 0 decimals for ADA-USDT
            $this->assertSame(11529.0, (float) $data['quantity']);

            // Brackets must be rounded to exactly 4 decimals
            $sl = json_decode($data['stopLoss'], true);
            $this->assertSame(0.2555, $sl['stopPrice']);

            $tp = json_decode($data['takeProfit'], true);
            $this->assertSame(0.2519, $tp['stopPrice']);

            return true;
        });
    }

    public function test_move_stop_rounds_to_symbol_contract_precision(): void
    {
        Http::fake([
            'https://open-api.bingx.com/openApi/swap/v2/trade/openOrders*' => Http::response(['code' => 0, 'data' => []]),
            'https://open-api.bingx.com/openApi/swap/v2/trade/order*' => Http::response([
                'code' => 0,
                'data' => ['order' => ['orderId' => 987654321]],
            ]),
        ]);

        $executor = new BingXTradeExecutor(
            http: $this->app->make(\Illuminate\Http\Client\Factory::class),
            config: [
                'api_key' => 'fake-key',
                'api_secret' => 'fake-secret',
                'base_url' => 'https://open-api.bingx.com',
            ],
        );

        $res = $executor->moveStop('SOL-USDT', Direction::Long, 120.208765);
        $this->assertTrue($res->ok);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/openApi/swap/v2/trade/order')) {
                return false;
            }

            $data = $request->data();
            // SOL has 3 decimals
            $this->assertSame(120.209, (float) $data['stopPrice']);

            return true;
        });
    }
}
