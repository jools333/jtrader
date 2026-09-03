<?php

declare(strict_types=1);

namespace App\Trading\Enums;

/**
 * The four entry setups the agent recognises.
 */
enum SignalType: string
{
    case Bounce = 'BOUNCE';
    case Retest = 'RETEST';
    case FalseBreakout = 'FALSE_BREAKOUT';
    case TrendPullback = 'TREND_PULLBACK';
    case BtcLeadLag = 'BTC_LEAD_LAG';
}
