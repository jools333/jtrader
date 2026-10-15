<?php

declare(strict_types=1);

namespace App\Trading\Enums;

/**
 * Entry setups the agent recognises.
 *
 * `LevelRetest` is the only one enabled by default (see {@see \App\Trading\Agent\TradingAgent}).
 * The remaining cases are kept for the disabled legacy strategies and for tests.
 */
enum SignalType: string
{
    case Bounce = 'BOUNCE';
    case Retest = 'RETEST';
    case FalseBreakout = 'FALSE_BREAKOUT';
    case TrendPullback = 'TREND_PULLBACK';
    case BtcLeadLag = 'BTC_LEAD_LAG';
    case LevelRetest = 'LEVEL_RETEST';
}
