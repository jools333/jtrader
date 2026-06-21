<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Market\DTO\Candle as CandleDTO;
use App\Market\Repositories\CandleRepository;
use Illuminate\Console\Command;

/**
 * Imports candles from pre-fetched BingX JSON files into the database.
 *
 * Files must be named  SYMBOL__INTERVAL.json  (e.g. BTC-USDT__1h.json) and
 * contain a raw BingX swap-v3 klines response. This provides an offline seed
 * path for environments where the app container cannot reach the exchange.
 *
 *   php artisan candles:import --dir=storage/app/seed
 */
class ImportCandles extends Command
{
    protected $signature = 'candles:import {--dir=storage/app/seed : Directory with SYMBOL__INTERVAL.json files}';

    protected $description = 'Import candles from pre-fetched BingX JSON files into the database';

    public function handle(CandleRepository $repository): int
    {
        $dir = base_path((string) $this->option('dir'));
        $files = glob(rtrim($dir, '/').'/*.json') ?: [];

        if ($files === []) {
            $this->warn("No JSON files found in {$dir}");

            return self::FAILURE;
        }

        $timeframes = (array) config('exchange.timeframes');
        $total = 0;

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (! str_contains($name, '__')) {
                $this->line("  <comment>skip</comment> {$name} (expected SYMBOL__INTERVAL)");
                continue;
            }

            [$symbol, $interval] = explode('__', $name, 2);
            $payload = json_decode((string) file_get_contents($file), true);
            $data = is_array($payload) ? ($payload['data'] ?? []) : [];

            if (! is_array($data) || $data === []) {
                $this->line("  <error>✗</error> {$symbol} {$interval} — no data");
                continue;
            }

            $intervalMs = ((int) ($timeframes[$interval] ?? 60)) * 1000;

            $candles = array_map(function (array $row) use ($intervalMs): CandleDTO {
                $openTime = (int) $row['time'];

                return new CandleDTO(
                    openTime: $openTime,
                    open: (float) $row['open'],
                    high: (float) $row['high'],
                    low: (float) $row['low'],
                    close: (float) $row['close'],
                    volume: (float) $row['volume'],
                    closeTime: $openTime + $intervalMs - 1,
                );
            }, $data);

            usort($candles, static fn (CandleDTO $a, CandleDTO $b) => $a->openTime <=> $b->openTime);

            $written = $repository->persist($symbol, $interval, $candles);
            $total += $written;
            $this->line(sprintf('  <info>✓</info> %s %s — %d candles', $symbol, $interval, $written));
        }

        $this->info("Done. {$total} candles imported.");

        return self::SUCCESS;
    }
}
