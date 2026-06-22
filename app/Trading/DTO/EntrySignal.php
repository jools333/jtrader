<?php

declare(strict_types=1);

namespace App\Trading\DTO;

use App\Trading\Enums\Direction;
use App\Trading\Enums\SignalType;

/**
 * A generated entry signal, including the trade plan (stop / targets / R:R)
 * derived from the triggering level and ATR.
 */
final class EntrySignal
{
    public function __construct(
        public readonly SignalType $type,
        public readonly Direction $direction,
        public readonly float $entryPrice,
        public readonly float $stop,
        public readonly float $target1,
        public readonly float $target2,
        public readonly float $rrRatio,
        public readonly bool $confluence = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'direction' => $this->direction->value,
            'confluence' => $this->confluence,
            'entry' => round($this->entryPrice, 8),
            'stop' => round($this->stop, 8),
            'target1' => round($this->target1, 8),
            'target2' => round($this->target2, 8),
            'rr_ratio' => round($this->rrRatio, 2),
        ];
    }
}
