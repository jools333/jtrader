<?php

declare(strict_types=1);

namespace App\Trading\Enums;

/**
 * Why an {@see ExitType::EarlyReversal} fired.
 */
enum ExitReason: string
{
    case Divergence = 'divergence';
    case Absorption = 'absorption';
    case EmaTurn = 'ema_turn';
}
