<?php

declare(strict_types=1);

namespace App\Trading\DTO;

/**
 * The full output of one agent evaluation: at most one entry signal, at most
 * one exit signal, plus the indicator snapshot. Matches the return shape in the
 * task specification via {@see toArray()}.
 */
final class AgentResult
{
    public function __construct(
        public readonly ?EntrySignal $entrySignal,
        public readonly ?ExitSignal $exitSignal,
        public readonly IndicatorSnapshot $indicators,
    ) {
    }

    public function toArray(): array
    {
        return [
            'entry_signal' => $this->entrySignal?->toArray(),
            'exit_signal' => $this->exitSignal?->toArray(),
            'indicators' => $this->indicators->toArray(),
        ];
    }
}
