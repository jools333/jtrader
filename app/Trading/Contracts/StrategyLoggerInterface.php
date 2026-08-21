<?php

declare(strict_types=1);

namespace App\Trading\Contracts;

use App\Trading\DTO\StrategyEvaluationResult;

interface StrategyLoggerInterface
{
    /**
     * Log a strategy evaluation result (e.g. if score >= 50%).
     */
    public function log(StrategyEvaluationResult $result): void;
}
