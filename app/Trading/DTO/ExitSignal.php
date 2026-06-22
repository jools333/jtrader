<?php

declare(strict_types=1);

namespace App\Trading\DTO;

use App\Trading\Enums\ExitReason;
use App\Trading\Enums\ExitType;

/**
 * An exit instruction for an open position.
 *
 * `closePercent` is the share of the *current* position to close; `moveStopTo`
 * is set only when the stop should be relocated (e.g. to break-even after a
 * partial take-profit). `reason` is populated only for early reversals.
 */
final class ExitSignal
{
    public function __construct(
        public readonly ExitType $type,
        public readonly int $closePercent,
        public readonly ?float $moveStopTo = null,
        public readonly ?ExitReason $reason = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'exit_type' => $this->type->value,
            'close_percent' => $this->closePercent,
            'move_stop_to' => $this->moveStopTo === null ? null : round($this->moveStopTo, 8),
            'reason' => $this->reason?->value,
        ];
    }
}
