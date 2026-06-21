<?php

declare(strict_types=1);

namespace App\Market\Analysis;

use App\Market\Analysis\Support\PatternDetector;
use App\Market\Analysis\Support\Pivot;
use App\Market\Analysis\Support\SeriesMath;
use App\Market\Contracts\MarketAnalyzerInterface;
use App\Market\DTO\Candle;
use App\Market\DTO\Level;
use App\Market\DTO\TrendResult;
use App\Market\Enums\LevelType;
use App\Market\Enums\TrendDirection;
use App\Market\Repositories\CandleRepository;

/**
 * Default technical-analysis implementation. Reads candles from the local
 * store ({@see CandleRepository}) and computes ATR, levels, trend and figures.
 */
final class MarketAnalyzer implements MarketAnalyzerInterface
{
    public function __construct(
        private readonly CandleRepository $candles,
        private readonly PatternDetector $patternDetector,
    ) {
    }

    public function atr(string $symbol, string $interval, int $period = 14): float
    {
        $candles = $this->candles->recent($symbol, $interval);

        return round(SeriesMath::atr($candles, $period), 8);
    }

    public function levels(string $symbol, string $interval, int $maxLevels = 4): array
    {
        $candles = $this->candles->recent($symbol, $interval);
        if (count($candles) < 20) {
            return [];
        }

        $pivots = SeriesMath::pivots($candles, 3, 3);
        if ($pivots === []) {
            return [];
        }

        $atr = SeriesMath::atr($candles, 14);
        $lastClose = $candles[count($candles) - 1]->close;
        $tolerance = max($atr * 0.6, $lastClose * 0.0035);
        $total = count($candles);

        $clusters = $this->clusterPivots($pivots, $tolerance, $total);

        // Rank clusters by score (touches dominate, recency breaks ties).
        usort($clusters, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $levels = [];
        foreach (array_slice($clusters, 0, $maxLevels) as $cluster) {
            $type = $cluster['price'] <= $lastClose ? LevelType::Support : LevelType::Resistance;
            $strength = min(1.0, ($cluster['touches'] / 5) + 0.1 * $cluster['recency']);

            $levels[] = new Level(
                price: round($cluster['price'], 8),
                type: $type,
                strength: $strength,
                touches: $cluster['touches'],
            );
        }

        return $levels;
    }

    public function trend(string $symbol, string $interval): TrendResult
    {
        $candles = $this->candles->recent($symbol, $interval);
        $n = count($candles);

        if ($n < 20) {
            return new TrendResult(TrendDirection::Sideways, 0.0, 0.0, 0.0);
        }

        $closes = array_map(
            static fn (Candle $c) => $c->close,
            array_slice($candles, -min(100, $n)),
        );

        $mean = array_sum($closes) / count($closes);
        $slope = SeriesMath::linregSlope($closes);
        $slopePct = $mean == 0.0 ? 0.0 : $slope / $mean; // fractional change per bar
        $rSquared = SeriesMath::rSquared($closes);

        $adxData = SeriesMath::adx($candles, 14);
        $adx = $adxData['adx'];

        $threshold = 0.0005; // 0.05% per bar
        if (abs($slopePct) < $threshold && $adx < 20) {
            $direction = TrendDirection::Sideways;
        } else {
            $direction = $slopePct >= 0 ? TrendDirection::Up : TrendDirection::Down;
        }

        $strength = $direction === TrendDirection::Sideways
            ? min(0.3, $adx / 100)
            : min(1.0, 0.6 * ($adx / 40) + 0.4 * $rSquared);

        return new TrendResult($direction, $strength, $slopePct, $adx);
    }

    public function patterns(string $symbol, string $interval): array
    {
        $candles = $this->candles->recent($symbol, $interval);

        return $this->patternDetector->detect($candles);
    }

    /**
     * Greedy 1-D clustering of pivot prices within `tolerance`.
     *
     * @param array<int, Pivot> $pivots
     * @return array<int, array{price: float, touches: int, recency: float, score: float}>
     */
    private function clusterPivots(array $pivots, float $tolerance, int $total): array
    {
        $sorted = $pivots;
        usort($sorted, static fn (Pivot $a, Pivot $b) => $a->price <=> $b->price);

        $clusters = [];
        foreach ($sorted as $pivot) {
            $placed = false;
            foreach ($clusters as &$cluster) {
                if (abs($cluster['price'] - $pivot->price) <= $tolerance) {
                    $count = $cluster['touches'];
                    $cluster['price'] = (($cluster['price'] * $count) + $pivot->price) / ($count + 1);
                    $cluster['touches']++;
                    $cluster['lastIndex'] = max($cluster['lastIndex'], $pivot->index);
                    $placed = true;
                    break;
                }
            }
            unset($cluster);

            if (! $placed) {
                $clusters[] = [
                    'price' => $pivot->price,
                    'touches' => 1,
                    'lastIndex' => $pivot->index,
                ];
            }
        }

        foreach ($clusters as &$cluster) {
            $recency = $total > 0 ? $cluster['lastIndex'] / $total : 0.0;
            $cluster['recency'] = $recency;
            $cluster['score'] = $cluster['touches'] * (1 + 0.3 * $recency);
        }
        unset($cluster);

        return $clusters;
    }
}
