<?php

declare(strict_types=1);

namespace App\Market\DTO;

use App\Market\Enums\TrendDirection;

/**
 * Trend direction plus a 0..1 strength score (slope significance + ADX-like
 * directional movement).
 */
final class TrendResult
{
    public function __construct(
        public readonly TrendDirection $direction,
        public readonly float $strength,
        public readonly float $slope,
        public readonly float $adx,
    ) {
    }

    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'label' => $this->direction->label(),
            'color' => $this->direction->color(),
            'strength' => round($this->strength, 3),
            'slope' => round($this->slope, 6),
            'adx' => round($this->adx, 2),
        ];
    }
}
