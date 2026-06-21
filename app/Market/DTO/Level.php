<?php

declare(strict_types=1);

namespace App\Market\DTO;

use App\Market\Enums\LevelType;

/**
 * A horizontal price level (support/resistance) with a 0..1 strength score
 * derived from how many times price reacted to it.
 */
final class Level
{
    public function __construct(
        public readonly float $price,
        public readonly LevelType $type,
        public readonly float $strength,
        public readonly int $touches,
    ) {
    }

    public function toArray(): array
    {
        return [
            'price' => $this->price,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'color' => $this->type->color(),
            'strength' => round($this->strength, 3),
            'touches' => $this->touches,
        ];
    }
}
