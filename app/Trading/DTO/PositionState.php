<?php

declare(strict_types=1);

namespace App\Trading\DTO;

use App\Trading\Enums\Direction;

/**
 * Snapshot of an open position handed to the agent so it can evaluate exits.
 *
 * Mirrors the `$position` array in the spec; `breakevenSet` records whether the
 * stop has already been moved to entry after a partial take-profit.
 */
final class PositionState
{
    public function __construct(
        public readonly Direction $direction,
        public readonly float $entryPrice,
        public readonly float $stopPrice,
        public readonly float $target1,
        public readonly float $target2,
        public readonly float $size = 1.0,
        public readonly bool $breakevenSet = false,
    ) {
    }

    /**
     * @param array{
     *     direction: string, entry_price: float|int, stop_price: float|int,
     *     target1: float|int, target2: float|int, size?: float|int, breakeven_set?: bool
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            direction: Direction::from($data['direction']),
            entryPrice: (float) $data['entry_price'],
            stopPrice: (float) $data['stop_price'],
            target1: (float) $data['target1'],
            target2: (float) $data['target2'],
            size: (float) ($data['size'] ?? 1.0),
            breakevenSet: (bool) ($data['breakeven_set'] ?? false),
        );
    }
}
