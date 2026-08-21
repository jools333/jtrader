<?php

declare(strict_types=1);

namespace App\Market\Repositories;

use App\Market\Contracts\ExchangeInterface;
use App\Market\DTO\Candle as CandleDTO;
use App\Models\Candle as CandleModel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persistence layer for candles. Reads return value-object {@see CandleDTO}s
 * ordered oldest -> newest; writes upsert by (symbol, interval, open_time).
 *
 * `recent()` lazily syncs from the exchange when the local store is empty, so
 * the dashboard has data on first view even before the scheduler runs.
 */
final class CandleRepository
{
    public function __construct(
        private readonly ExchangeInterface $exchange,
    ) {
    }

    /**
     * @return array<int, CandleDTO>
     */
    public function recent(string $symbol, string $interval, int $limit = 500): array
    {
        // Lazily seed from the exchange when the store is empty. Network issues
        // here must never break reads — degrade to whatever is stored.
        if (! $this->has($symbol, $interval)) {
            try {
                $this->sync($symbol, $interval, $limit);
            } catch (Throwable $e) {
                Log::warning('Candle lazy-sync failed', [
                    'symbol' => $symbol, 'interval' => $interval, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->fromStore($symbol, $interval, $limit);
    }

    /**
     * Pull fresh candles from the exchange and upsert them. Returns the number
     * of rows written.
     */
    public function sync(string $symbol, string $interval, int $limit = 500): int
    {
        return $this->persist($symbol, $interval, $this->exchange->klines($symbol, $interval, $limit));
    }

    /**
     * Upsert a set of candle DTOs for a symbol/timeframe. Returns rows written.
     *
     * @param array<int, CandleDTO> $candles
     */
    public function persist(string $symbol, string $interval, array $candles): int
    {
        if ($candles === []) {
            return 0;
        }

        $rows = array_map(static fn (CandleDTO $c) => [
            'symbol' => $symbol,
            'interval' => $interval,
            'open_time' => $c->openTime,
            'open' => $c->open,
            'high' => $c->high,
            'low' => $c->low,
            'close' => $c->close,
            'volume' => $c->volume,
            'close_time' => $c->closeTime,
            'updated_at' => now(),
            'created_at' => now(),
        ], $candles);

        // Chunk to stay well under driver placeholder limits.
        foreach (array_chunk($rows, 200) as $chunk) {
            CandleModel::upsert(
                $chunk,
                ['symbol', 'interval', 'open_time'],
                ['open', 'high', 'low', 'close', 'volume', 'close_time', 'updated_at'],
            );
        }

        return count($rows);
    }

    /**
     * @return array<int, CandleDTO>
     */
    public function fromStore(string $symbol, string $interval, int $limit = 500): array
    {
        $rows = CandleModel::query()
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->orderByDesc('open_time')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return $rows->map(static fn (CandleModel $m) => new CandleDTO(
            openTime: (int) $m->open_time,
            open: (float) $m->open,
            high: (float) $m->high,
            low: (float) $m->low,
            close: (float) $m->close,
            volume: (float) $m->volume,
            closeTime: (int) $m->close_time,
        ))->all();
    }

    /**
     * Return a candle window centered around a target open_time ($beforeCount before/at, $afterCount strictly after).
     * Returns null if fewer than $afterCount candles exist after targetOpenTime.
     *
     * @return array<int, CandleDTO>|null
     */
    public function windowAround(
        string $symbol,
        string $interval,
        int $targetOpenTime,
        int $beforeCount = 40,
        int $afterCount = 30,
    ): ?array {
        $afterRows = CandleModel::query()
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->where('open_time', '>', $targetOpenTime)
            ->orderBy('open_time', 'asc')
            ->limit($afterCount)
            ->get();

        if ($afterRows->count() < $afterCount) {
            return null;
        }

        $beforeRows = CandleModel::query()
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->where('open_time', '<=', $targetOpenTime)
            ->orderByDesc('open_time')
            ->limit($beforeCount)
            ->get()
            ->reverse()
            ->values();

        $allRows = $beforeRows->concat($afterRows);

        return $allRows->map(static fn (CandleModel $m) => new CandleDTO(
            openTime: (int) $m->open_time,
            open: (float) $m->open,
            high: (float) $m->high,
            low: (float) $m->low,
            close: (float) $m->close,
            volume: (float) $m->volume,
            closeTime: (int) $m->close_time,
        ))->all();
    }

    private function has(string $symbol, string $interval): bool
    {
        return CandleModel::query()
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->exists();
    }
}
