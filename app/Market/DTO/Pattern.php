<?php

declare(strict_types=1);

namespace App\Market\DTO;

/**
 * A detected chart figure (head & shoulders, triangle, double top/bottom, ...).
 *
 * `points` is an ordered list of pivot anchors [['time' => sec, 'price' => x], ...]
 * so the front-end can draw the figure outline on the chart.
 */
final class Pattern
{
    /**
     * @param array<int, array{time:int, price:float}> $points
     */
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $bias,          // bullish | bearish | neutral
        public readonly float $confidence,     // 0..1
        public readonly array $points,
        public readonly int $startTime,
        public readonly int $endTime,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'bias' => $this->bias,
            'confidence' => round($this->confidence, 3),
            'points' => $this->points,
            'startTime' => $this->startTime,
            'endTime' => $this->endTime,
        ];
    }
}
