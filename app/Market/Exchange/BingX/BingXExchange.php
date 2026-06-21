<?php

declare(strict_types=1);

namespace App\Market\Exchange\BingX;

use App\Market\Contracts\ExchangeInterface;
use App\Market\DTO\Candle;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * BingX USDT-M perpetual ("swap") public market data adapter.
 *
 * Only public endpoints are used (klines, ticker), so no API key/signature is
 * required. Everything BingX-specific is contained in this class.
 */
final class BingXExchange implements ExchangeInterface
{
    /** @param array<string, mixed> $config the `exchange.drivers.bingx` block */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
        private readonly array $pairs,
        private readonly array $timeframes,
    ) {
    }

    public function name(): string
    {
        return 'BingX';
    }

    public function symbols(): array
    {
        return array_values($this->pairs);
    }

    public function klines(string $symbol, string $interval, int $limit = 500): array
    {
        $response = $this->client()
            ->get('/openApi/swap/v3/quote/klines', [
                'symbol' => $symbol,
                'interval' => $interval,
                'limit' => $limit,
            ]);

        $payload = $response->json();

        if (! is_array($payload) || ($payload['code'] ?? -1) !== 0) {
            throw new RuntimeException(sprintf(
                'BingX klines error for %s %s: %s',
                $symbol,
                $interval,
                is_array($payload) ? ($payload['msg'] ?? 'unknown') : 'invalid response'
            ));
        }

        $intervalMs = ($this->timeframes[$interval] ?? 60) * 1000;

        $candles = array_map(function (array $row) use ($intervalMs): Candle {
            $openTime = (int) $row['time'];

            return new Candle(
                openTime: $openTime,
                open: (float) $row['open'],
                high: (float) $row['high'],
                low: (float) $row['low'],
                close: (float) $row['close'],
                volume: (float) $row['volume'],
                closeTime: $openTime + $intervalMs - 1,
            );
        }, $payload['data'] ?? []);

        // BingX returns newest-first; the rest of the app expects oldest-first.
        usort($candles, static fn (Candle $a, Candle $b) => $a->openTime <=> $b->openTime);

        return $candles;
    }

    public function ticker(string $symbol): array
    {
        $response = $this->client()
            ->get('/openApi/swap/v2/quote/ticker', ['symbol' => $symbol]);

        $payload = $response->json();
        $data = is_array($payload) ? ($payload['data'] ?? []) : [];

        return [
            'symbol' => $symbol,
            'last' => (float) ($data['lastPrice'] ?? 0),
            'high' => (float) ($data['highPrice'] ?? 0),
            'low' => (float) ($data['lowPrice'] ?? 0),
            'volume' => (float) ($data['volume'] ?? 0),
            'changePercent' => (float) ($data['priceChangePercent'] ?? 0),
        ];
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) $this->config['base_url'], '/'))
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->acceptJson()
            ->retry(2, 200);
    }
}
