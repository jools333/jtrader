<?php

declare(strict_types=1);

namespace App\Trading\Execution\BingX;

use App\Trading\Contracts\TradeExecutorInterface;
use App\Trading\DTO\EntrySignal;
use App\Trading\Enums\Direction;
use App\Trading\Execution\OrderResult;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * BingX USDT-M perpetual order routing (signed private endpoints).
 *
 * Opens a MARKET position with attached TAKE_PROFIT (target1) and STOP_LOSS,
 * and supports partial closes / stop relocation. All BingX-specific request
 * shaping and HMAC-SHA256 signing is contained here, behind
 * {@see TradeExecutorInterface}.
 *
 * NOTE: requires real API credentials and outbound network access. In this dev
 * sandbox the containers cannot reach BingX, so {@see PaperTradeExecutor} is
 * the configured default; this class is exercised against the live venue only.
 */
final class BingXTradeExecutor implements TradeExecutorInterface
{
    /** @param array<string, mixed> $config the `exchange.drivers.bingx` block */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {
    }

    public function name(): string
    {
        return $this->isDemo() ? 'BingX (VST demo)' : 'BingX';
    }

    /** Whether orders route to the BingX demo (virtual USDT / VST) environment. */
    private function isDemo(): bool
    {
        return (bool) ($this->config['demo'] ?? false);
    }

    /** Order-routing host — the VST demo host when demo mode is on. */
    private function baseUrl(): string
    {
        $url = $this->isDemo()
            ? ($this->config['base_url_demo'] ?? 'https://open-api-vst.bingx.com')
            : ($this->config['base_url'] ?? '');

        return rtrim((string) $url, '/');
    }

    public function openPosition(EntrySignal $signal, string $symbol, float $quantity): OrderResult
    {
        $side = $signal->direction === Direction::Long ? 'BUY' : 'SELL';
        $positionSide = $signal->direction === Direction::Long ? 'LONG' : 'SHORT';

        return $this->send('/openApi/swap/v2/trade/order', [
            'symbol' => $symbol,
            'side' => $side,
            'positionSide' => $positionSide,
            'type' => 'MARKET',
            'quantity' => $quantity,
            // Server-side protective orders so the position is covered even if
            // the agent process dies between bars.
            'takeProfit' => $this->bracket('TAKE_PROFIT_MARKET', $signal->target1),
            'stopLoss' => $this->bracket('STOP_MARKET', $signal->stop),
        ]);
    }

    public function closePosition(string $symbol, Direction $direction, int $percent): OrderResult
    {
        // Reduce-only market order on the opposite side closes the position.
        $side = $direction === Direction::Long ? 'SELL' : 'BUY';
        $positionSide = $direction === Direction::Long ? 'LONG' : 'SHORT';

        return $this->send('/openApi/swap/v2/trade/closePosition', [
            'symbol' => $symbol,
            'side' => $side,
            'positionSide' => $positionSide,
            'reduceOnly' => 'true',
            'closePercent' => $percent,
        ]);
    }

    public function moveStop(string $symbol, Direction $direction, float $newStop): OrderResult
    {
        $side = $direction === Direction::Long ? 'SELL' : 'BUY';
        $positionSide = $direction === Direction::Long ? 'LONG' : 'SHORT';

        return $this->send('/openApi/swap/v2/trade/order', [
            'symbol' => $symbol,
            'side' => $side,
            'positionSide' => $positionSide,
            'type' => 'STOP_MARKET',
            'stopPrice' => $newStop,
            'reduceOnly' => 'true',
        ]);
    }

    public function balance(): float
    {
        $key = (string) ($this->config['api_key'] ?? '');
        $secret = (string) ($this->config['api_secret'] ?? '');
        if ($key === '' || $secret === '') {
            return 0.0;
        }

        $params = ['timestamp' => (int) (microtime(true) * 1000)];
        $query = http_build_query($params);
        $signature = hash_hmac('sha256', $query, $secret);

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl())
                ->timeout((int) ($this->config['timeout'] ?? 15))
                ->withHeaders(['X-BX-APIKEY' => $key])
                ->get('/openApi/swap/v2/user/balance', $params + ['signature' => $signature]);

            $payload = (array) $response->json();
        } catch (Throwable) {
            return 0.0;
        }

        if (($payload['code'] ?? -1) !== 0) {
            return 0.0;
        }

        $b = (array) ($payload['data']['balance'] ?? []);

        return (float) ($b['availableMargin'] ?? $b['equity'] ?? 0.0);
    }

    /** @return string JSON for a BingX bracket (TP/SL) sub-order. */
    private function bracket(string $type, float $stopPrice): string
    {
        return json_encode(['type' => $type, 'stopPrice' => $stopPrice], JSON_THROW_ON_ERROR);
    }

    /**
     * Sign and POST a request to BingX; normalise the response to an OrderResult.
     *
     * @param array<string, mixed> $params
     */
    private function send(string $path, array $params): OrderResult
    {
        $key = (string) ($this->config['api_key'] ?? '');
        $secret = (string) ($this->config['api_secret'] ?? '');
        if ($key === '' || $secret === '') {
            return OrderResult::failure('BingX API credentials are not configured.');
        }

        $params['timestamp'] = (int) (microtime(true) * 1000);
        ksort($params);
        $query = http_build_query($params);
        $signature = hash_hmac('sha256', $query, $secret);

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl())
                ->timeout((int) ($this->config['timeout'] ?? 15))
                ->withHeaders(['X-BX-APIKEY' => $key])
                ->asForm()
                ->post($path.'?signature='.$signature, $params);

            $payload = (array) $response->json();
        } catch (Throwable $e) {
            return OrderResult::failure($e->getMessage());
        }

        if (($payload['code'] ?? -1) !== 0) {
            return OrderResult::failure((string) ($payload['msg'] ?? 'unknown error'), $payload);
        }

        $orderId = $payload['data']['order']['orderId'] ?? $payload['data']['orderId'] ?? null;

        return OrderResult::success($orderId === null ? null : (string) $orderId, $payload);
    }
}
