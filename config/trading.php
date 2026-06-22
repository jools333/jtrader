<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Order executor
    |--------------------------------------------------------------------------
    | Which TradeExecutorInterface implementation routes orders. "paper" logs
    | and simulates fills (safe default — and the only option that works in the
    | network-restricted dev sandbox). "bingx" routes signed live orders.
    | Bound in App\Providers\TradingServiceProvider.
    */
    'executor' => env('TRADING_EXECUTOR', 'paper'),

    /*
    |--------------------------------------------------------------------------
    | Position sizing
    |--------------------------------------------------------------------------
    | Quantity (in contracts/base units) per position. A risk-based sizer can
    | replace this later without touching the agent.
    */
    'default_quantity' => (float) env('TRADING_QUANTITY', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Agent rule parameters (all ATR-relative unless noted)
    |--------------------------------------------------------------------------
    */
    'agent' => [
        'min_rr' => 2.0,          // reject entries with reward:risk below this
        'max_atr_travel' => 0.60, // skip if price ran > 60% of ATR off the level
        'min_flat_width' => 0.30, // skip if last 5 candles span < ATR*0.30 (dead flat)
        'stop_atr' => 0.5,        // stop sits ATR*0.5 beyond the level
        'target1_r' => 2.0,       // target 1 at 2R
        'target2_r' => 4.0,       // target 2 at 4R
    ],

];
