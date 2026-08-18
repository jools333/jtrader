<?php

declare(strict_types=1);

namespace App\Trading\Contracts;

use App\Trading\Agent\RuleContext;
use App\Trading\DTO\ExitSignal;
use App\Trading\DTO\PositionState;

/**
 * Strategy contract for managing position exits.
 */
interface ExitStrategyInterface
{
    /**
     * Evaluate the market context against an active position and produce an exit signal if triggered.
     */
    public function evaluate(RuleContext $ctx, PositionState $position): ?ExitSignal;
}
