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
    | risk_percent — % of available balance to risk per trade (e.g. 1.0 = 1%).
    |   Quantity = (balance × risk_percent / 100) / |entry − stop|
    | paper_balance — virtual balance used by the paper executor (USDT).
    | max_quantity — hard cap after the calculation (0 = no cap).
    |   Per-symbol minimum lot size is enforced by the exchange, not here.
    */
    'risk_percent'  => (float) env('TRADING_RISK_PCT', 1.0),
    'paper_balance' => (float) env('TRADING_PAPER_BALANCE', 1_000.0),
    'max_quantity'  => (float) env('TRADING_MAX_QTY', 0.0),

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

    /*
    |--------------------------------------------------------------------------
    | Position chart rendering
    |--------------------------------------------------------------------------
    | When enabled, each opened/closed position gets a PNG (candles, level,
    | EMA8/21, entry/exit markers, volume) via scripts/render_position.py.
    | Requires Python + matplotlib reachable at `python_bin`. In the container
    | (no Python) leave this off and render specs on the host out-of-band; the
    | spec JSON is always written regardless.
    */
    'chart' => [
        'enabled' => (bool) env('TRADING_CHART', false),
        'python_bin' => env('TRADING_CHART_PYTHON', 'python3'),
        'script' => env('TRADING_CHART_SCRIPT', base_path('scripts/render_position.py')),
        'window' => 60, // candles to plot
        'timeout' => 60,
    ],

];
