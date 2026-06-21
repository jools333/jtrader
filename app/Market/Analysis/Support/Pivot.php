<?php

declare(strict_types=1);

namespace App\Market\Analysis\Support;

/**
 * A swing point (local high or low) in a candle series.
 */
final class Pivot
{
    public const HIGH = 'high';
    public const LOW = 'low';

    public function __construct(
        public readonly int $index,
        public readonly int $time,   // seconds (chart time)
        public readonly float $price,
        public readonly string $kind, // self::HIGH | self::LOW
    ) {
    }

    public function isHigh(): bool
    {
        return $this->kind === self::HIGH;
    }

    public function isLow(): bool
    {
        return $this->kind === self::LOW;
    }
}
