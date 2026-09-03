<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Market\DTO\Candle;
use App\Market\Repositories\CandleRepository;
use App\Trading\Services\BtcImpulseDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use WebSocket\Client;

/**
 * Long-running WebSocket listener that streams live candles from BingX.
 *
 * On startup it seeds the DB via the REST API (same as candles:sync), then
 * subscribes to every configured pair × timeframe on a single WebSocket
 * connection and upserts each incoming kline tick. Automatically reconnects
 * on any error.
 */
class WsCandles extends Command
{
    protected $signature = 'candles:ws';

    protected $description = 'Stream live candles from BingX via WebSocket (runs indefinitely)';

    /** Seconds to wait before reconnecting after a failure. */
    private const RECONNECT_DELAY = 5;

    /** Socket read timeout — BingX sends pings every ~30 s, so 60 s is safe. */
    private const SOCKET_TIMEOUT = 60;

    public function handle(CandleRepository $repository, ?BtcImpulseDetector $detector = null): int
    {
        $symbols    = (array) config('exchange.pairs');
        $intervals  = array_keys((array) config('exchange.timeframes'));
        $timeframes = (array) config('exchange.timeframes');

        $this->seedHistorical($repository, $symbols, $intervals);

        $this->info('Starting WebSocket stream. Press Ctrl+C to stop.');

        while (true) {
            try {
                $this->stream($repository, $detector, $symbols, $intervals, $timeframes);
            } catch (Throwable $e) {
                Log::warning('candles:ws disconnected', ['error' => $e->getMessage()]);
                $this->warn("Connection lost ({$e->getMessage()}). Reconnecting in " . self::RECONNECT_DELAY . 's…');
                sleep(self::RECONNECT_DELAY);
            }
        }
    }

    // -------------------------------------------------------------------------

    private function seedHistorical(CandleRepository $repository, array $symbols, array $intervals): void
    {
        $this->info('Seeding historical candles via REST…');

        foreach ($symbols as $symbol) {
            foreach ($intervals as $interval) {
                try {
                    $n = $repository->sync($symbol, $interval);
                    $this->line("  <info>✓</info> {$symbol} {$interval} — {$n} candles");
                } catch (Throwable $e) {
                    $this->line("  <error>✗</error> {$symbol} {$interval} — {$e->getMessage()}");
                }
            }
        }
    }

    private function stream(
        CandleRepository $repository,
        ?BtcImpulseDetector $detector,
        array $symbols,
        array $intervals,
        array $timeframes,
    ): void {
        $wsUrl  = (string) config('exchange.drivers.bingx.ws_url', 'wss://open-api-swap.bingx.com/swap-market');
        $client = new Client($wsUrl, ['timeout' => self::SOCKET_TIMEOUT]);

        foreach ($symbols as $symbol) {
            foreach ($intervals as $interval) {
                $client->send(json_encode([
                    'id'       => "{$symbol}_{$interval}_" . uniqid(),
                    'reqType'  => 'sub',
                    'dataType' => "{$symbol}@kline_{$interval}",
                ]));
                usleep(10000);
            }
        }

        $total = count($symbols) * count($intervals);
        $this->line("  Subscribed to {$total} streams via {$wsUrl}");

        while (true) {
            $raw  = $client->receive();
            // BingX may gzip-compress frames; fall back to raw on failure.
            $text = (@gzdecode($raw) ?: $raw);

            // Raw string Ping keep-alive from BingX
            if (trim((string) $text) === 'Ping') {
                $client->send('Pong');
                continue;
            }

            $msg  = json_decode($text, true);

            if (! is_array($msg)) {
                continue;
            }

            // Application-level ping — BingX sends {"ping": <timestamp>}.
            if (isset($msg['ping'])) {
                $client->send(json_encode(['pong' => $msg['ping']]));
                continue;
            }

            $this->handleKline($repository, $detector, $msg, $timeframes);
        }
    }

    private function handleKline(
        CandleRepository $repository,
        ?BtcImpulseDetector $detector,
        array $msg,
        array $timeframes,
    ): void {
        $dataType = (string) ($msg['dataType'] ?? '');

        if (! str_contains($dataType, '@kline_')) {
            return;
        }

        // dataType: "BTC-USDT@kline_1m"
        [$symbol, $klineSpec] = explode('@', $dataType, 2);
        $interval = substr($klineSpec, strlen('kline_'));

        // BingX WS kline payload: data is an array with one row.
        // Fields: T=openTime(ms), o, h, l, c, v
        $row = $msg['data'][0] ?? null;

        if (! is_array($row)) {
            return;
        }

        $openTime   = (int) $row['T'];
        $intervalMs = ((int) ($timeframes[$interval] ?? 60)) * 1000;

        $candle = new Candle(
            openTime:  $openTime,
            open:      (float) $row['o'],
            high:      (float) $row['h'],
            low:       (float) $row['l'],
            close:     (float) $row['c'],
            volume:    (float) $row['v'],
            closeTime: $openTime + $intervalMs - 1,
        );

        $repository->persist($symbol, $interval, [$candle]);

        // Real-time BTC impulse detection
        if ($detector !== null && $symbol === 'BTC-USDT' && $interval === '1m') {
            try {
                $detector->onBtcTick($candle);
            } catch (Throwable $e) {
                Log::warning('BTC impulse detection error', ['error' => $e->getMessage()]);
            }
        }
    }
}
