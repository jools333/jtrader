<?php

declare(strict_types=1);

namespace App\Trading\Enums;

/**
 * The four exit conditions the agent can raise against an open position.
 */
enum ExitType: string
{
    case Target1 = 'TARGET1';
    case Target2 = 'TARGET2';
    case EarlyReversal = 'EARLY_REVERSAL';
    case StopLoss = 'STOP_LOSS';
}
