<?php

declare(strict_types=1);

namespace App\Market\Analysis\Support;

use App\Market\DTO\Candle;
use App\Market\DTO\Pattern;

/**
 * Detects scalping-relevant chart figures from a candle series:
 * head & shoulders (+ inverse), double top / double bottom, and triangles
 * (ascending / descending / symmetrical).
 *
 * Detection works on a zig-zag of alternating swing pivots rather than raw
 * candles, which keeps it tolerant to noise.
 */
final class PatternDetector
{
    /**
     * @param array<int, Candle> $candles
     * @return array<int, Pattern>
     */
    public function detect(array $candles, int $left = 3, int $right = 3): array
    {
        if (count($candles) < 20) {
            return [];
        }

        $pivots = $this->zigzag(SeriesMath::pivots($candles, $left, $right));
        if (count($pivots) < 4) {
            return [];
        }

        $avgPrice = $this->averagePrice($candles);
        $tol = $avgPrice * 0.02; // ~2% "roughly equal" tolerance

        $patterns = [];

        if ($p = $this->headAndShoulders($pivots, $tol, false)) {
            $patterns[] = $p;
        }
        if ($p = $this->headAndShoulders($pivots, $tol, true)) {
            $patterns[] = $p;
        }
        if ($p = $this->doubleExtreme($pivots, $tol, true)) {
            $patterns[] = $p;
        }
        if ($p = $this->doubleExtreme($pivots, $tol, false)) {
            $patterns[] = $p;
        }
        if ($p = $this->triangle($pivots)) {
            $patterns[] = $p;
        }

        return $patterns;
    }

    /**
     * Collapse consecutive same-kind pivots, keeping the extreme one, so the
     * result strictly alternates high/low.
     *
     * @param array<int, Pivot> $pivots
     * @return array<int, Pivot>
     */
    private function zigzag(array $pivots): array
    {
        $out = [];
        foreach ($pivots as $p) {
            if ($out === []) {
                $out[] = $p;
                continue;
            }
            $last = $out[count($out) - 1];
            if ($last->kind === $p->kind) {
                $moreExtreme = $p->isHigh() ? $p->price > $last->price : $p->price < $last->price;
                if ($moreExtreme) {
                    $out[count($out) - 1] = $p;
                }
            } else {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Head & shoulders (bearish) or inverse head & shoulders (bullish).
     * Examines the last 5 alternating pivots.
     *
     * @param array<int, Pivot> $pivots
     */
    private function headAndShoulders(array $pivots, float $tol, bool $inverse): ?Pattern
    {
        $window = array_slice($pivots, -5);
        if (count($window) < 5) {
            return null;
        }

        // Need shoulders/head on one side and the neckline on the other.
        $extreme = $inverse ? Pivot::LOW : Pivot::HIGH;
        $neck = $inverse ? Pivot::HIGH : Pivot::LOW;

        [$ls, $n1, $head, $n2, $rs] = $window;

        if ($ls->kind !== $extreme || $head->kind !== $extreme || $rs->kind !== $extreme) {
            return null;
        }
        if ($n1->kind !== $neck || $n2->kind !== $neck) {
            return null;
        }

        if ($inverse) {
            // Head is the lowest; shoulders above head and ~equal.
            if (! ($head->price < $ls->price && $head->price < $rs->price)) {
                return null;
            }
        } else {
            if (! ($head->price > $ls->price && $head->price > $rs->price)) {
                return null;
            }
        }

        if (abs($ls->price - $rs->price) > $tol) {
            return null; // shoulders not symmetric
        }
        if (abs($n1->price - $n2->price) > $tol * 1.5) {
            return null; // neckline not horizontal enough
        }

        $shoulderErr = abs($ls->price - $rs->price) / max($tol, 1e-9);
        $confidence = max(0.4, min(0.95, 1.0 - $shoulderErr * 0.4));

        return new Pattern(
            type: $inverse ? 'inverse_head_and_shoulders' : 'head_and_shoulders',
            label: $inverse ? 'Перевёрнутая голова и плечи' : 'Голова и плечи',
            bias: $inverse ? 'bullish' : 'bearish',
            confidence: $confidence,
            points: $this->points([$ls, $n1, $head, $n2, $rs]),
            startTime: $ls->time,
            endTime: $rs->time,
        );
    }

    /**
     * Double top (bearish) or double bottom (bullish) over the last 3 pivots.
     *
     * @param array<int, Pivot> $pivots
     */
    private function doubleExtreme(array $pivots, float $tol, bool $top): ?Pattern
    {
        $window = array_slice($pivots, -3);
        if (count($window) < 3) {
            return null;
        }

        [$a, $mid, $b] = $window;
        $extreme = $top ? Pivot::HIGH : Pivot::LOW;

        if ($a->kind !== $extreme || $b->kind !== $extreme || $mid->kind === $extreme) {
            return null;
        }
        if (abs($a->price - $b->price) > $tol) {
            return null; // the two tops/bottoms must be ~equal
        }

        // The reaction between them must be meaningful (> tol).
        $reaction = abs($a->price - $mid->price);
        if ($reaction < $tol) {
            return null;
        }

        $equalityErr = abs($a->price - $b->price) / max($tol, 1e-9);
        $confidence = max(0.4, min(0.9, 1.0 - $equalityErr * 0.5));

        return new Pattern(
            type: $top ? 'double_top' : 'double_bottom',
            label: $top ? 'Двойная вершина' : 'Двойное основание',
            bias: $top ? 'bearish' : 'bullish',
            confidence: $confidence,
            points: $this->points([$a, $mid, $b]),
            startTime: $a->time,
            endTime: $b->time,
        );
    }

    /**
     * Converging/диагональные triangles from the last swing highs & lows.
     *
     * @param array<int, Pivot> $pivots
     */
    private function triangle(array $pivots): ?Pattern
    {
        $window = array_slice($pivots, -6);
        $highs = array_values(array_filter($window, static fn (Pivot $p) => $p->isHigh()));
        $lows = array_values(array_filter($window, static fn (Pivot $p) => $p->isLow()));

        if (count($highs) < 2 || count($lows) < 2) {
            return null;
        }

        $highPrices = array_map(static fn (Pivot $p) => $p->price, $highs);
        $lowPrices = array_map(static fn (Pivot $p) => $p->price, $lows);

        $highSlope = SeriesMath::linregSlope($highPrices);
        $lowSlope = SeriesMath::linregSlope($lowPrices);

        $avg = (array_sum($highPrices) + array_sum($lowPrices)) / (count($highPrices) + count($lowPrices));
        // Flatness threshold relative to price scale.
        $flat = $avg * 0.0015;

        $highFlat = abs($highSlope) <= $flat;
        $lowFlat = abs($lowSlope) <= $flat;

        $type = $label = $bias = null;

        if ($highFlat && $lowSlope > $flat) {
            $type = 'ascending_triangle';
            $label = 'Восходящий треугольник';
            $bias = 'bullish';
        } elseif ($lowFlat && $highSlope < -$flat) {
            $type = 'descending_triangle';
            $label = 'Нисходящий треугольник';
            $bias = 'bearish';
        } elseif ($highSlope < -$flat && $lowSlope > $flat) {
            $type = 'symmetrical_triangle';
            $label = 'Симметричный треугольник';
            $bias = 'neutral';
        }

        if ($type === null) {
            return null;
        }

        $fit = (SeriesMath::rSquared($highPrices) + SeriesMath::rSquared($lowPrices)) / 2;
        $confidence = max(0.4, min(0.9, 0.4 + $fit * 0.5));

        $anchors = array_merge($highs, $lows);
        usort($anchors, static fn (Pivot $a, Pivot $b) => $a->time <=> $b->time);

        return new Pattern(
            type: $type,
            label: $label,
            bias: $bias,
            confidence: $confidence,
            points: $this->points($anchors),
            startTime: $anchors[0]->time,
            endTime: $anchors[count($anchors) - 1]->time,
        );
    }

    /**
     * @param array<int, Pivot> $pivots
     * @return array<int, array{time:int, price:float}>
     */
    private function points(array $pivots): array
    {
        return array_map(
            static fn (Pivot $p) => ['time' => $p->time, 'price' => $p->price],
            $pivots,
        );
    }

    /** @param array<int, Candle> $candles */
    private function averagePrice(array $candles): float
    {
        $sum = 0.0;
        foreach ($candles as $c) {
            $sum += $c->close;
        }

        return $sum / max(1, count($candles));
    }
}
