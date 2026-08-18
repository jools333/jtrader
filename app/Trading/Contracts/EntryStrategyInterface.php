<?php

declare(strict_types=1);

namespace App\Trading\Contracts;

use App\Trading\Agent\RuleContext;
use App\Trading\Agent\TradePlanner;
use App\Trading\DTO\EntrySignal;

/**
 * Strategy contract for market entry pattern recognition.
 */
interface EntryStrategyInterface
{
    /**
     * Evaluate the market context and produce an entry signal if the pattern matches.
     */
    public function evaluate(RuleContext $ctx, TradePlanner $planner): ?EntrySignal;
}
