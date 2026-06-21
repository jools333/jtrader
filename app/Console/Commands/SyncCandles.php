<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Market\Repositories\CandleRepository;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls candles from the active exchange into the local store.
 *
 *   php artisan candles:sync                       # all configured pairs/timeframes
 *   php artisan candles:sync --symbol=BTC-USDT     # one pair, all timeframes
 *   php artisan candles:sync --interval=1h         # all pairs, one timeframe
 */
class SyncCandles extends Command
{
    protected $signature = 'candles:sync
        {--symbol= : Limit to a single symbol}
        {--interval= : Limit to a single timeframe}';

    protected $description = 'Synchronise OHLCV candles from the exchange into the database';

    public function handle(CandleRepository $repository): int
    {
        $symbols = $this->option('symbol')
            ? [$this->option('symbol')]
            : (array) config('exchange.pairs');

        $intervals = $this->option('interval')
            ? [$this->option('interval')]
            : array_keys((array) config('exchange.timeframes'));

        $limit = (int) config('exchange.klines_limit', 500);
        $written = 0;

        foreach ($symbols as $symbol) {
            foreach ($intervals as $interval) {
                try {
                    $count = $repository->sync($symbol, $interval, $limit);
                    $written += $count;
                    $this->line(sprintf('  <info>✓</info> %s %s — %d candles', $symbol, $interval, $count));
                } catch (Throwable $e) {
                    $this->line(sprintf('  <error>✗</error> %s %s — %s', $symbol, $interval, $e->getMessage()));
                }
            }
        }

        $this->info("Done. {$written} candles upserted.");

        return self::SUCCESS;
    }
}
