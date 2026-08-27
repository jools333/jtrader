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
    'risk_percent'     => (float) env('TRADING_RISK_PCT', 1.0),
    'paper_balance'    => (float) env('TRADING_PAPER_BALANCE', 1_000.0),
    'max_quantity'     => (float) env('TRADING_MAX_QTY', 0.0),
    // Hard cap: notional position value ≤ X% of balance (0 = disabled).
    // Prevents oversized positions when the stop is very tight relative to price.
    'max_position_pct' => (float) env('TRADING_MAX_POSITION_PCT', 10.0),

    /*
    |--------------------------------------------------------------------------
    | Strategy evaluation diagnostics & logging
    |--------------------------------------------------------------------------
    | Minimum criteria match percentage (0.0 to 100.0) required to log a setup.
    | 50.0 logs all setups reaching at least 50% criteria completion.
    */
    'min_eval_log_score' => (float) env('TRADING_MIN_EVAL_LOG_SCORE', 50.0),

    /*
    |--------------------------------------------------------------------------
    | Agent rule parameters (all ATR-relative unless noted)
    |--------------------------------------------------------------------------
    */
    'agent' => [
        'min_entry_score' => (float) env('TRADING_MIN_ENTRY_SCORE', 83.33),
        'min_rr' => 2.0,          // reject entries with reward:risk below this
        'max_atr_travel' => 0.60, // skip if price ran > 60% of ATR off the level
        'min_flat_width' => 0.30, // skip if last 5 candles span < ATR*0.30 (dead flat)
        'stop_atr' => 1.0,        // stop sits ATR*1.0 beyond the level
        'target1_r' => 2.0,       // target 1 at 2R
        'target2_r' => 4.0,       // target 2 at 4R
    ],

    /*
    |--------------------------------------------------------------------------
    | Position and evaluation chart rendering
    |--------------------------------------------------------------------------
    | When enabled, each opened/closed position and evaluation gets a PNG chart.
    */
    'chart' => [
        'enabled' => (bool) env('TRADING_CHART', false),
        'python_bin' => env('TRADING_CHART_PYTHON', 'python3'),
        'script' => env('TRADING_CHART_SCRIPT', base_path('scripts/render_position.py')),
        'window' => 60, // candles to plot
        'timeout' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outcome chart rendering (post-evaluation follow-up)
    |--------------------------------------------------------------------------
    | Generates a follow-up chart after `after_candles` have closed since the setup.
    */
    'outcome_chart' => [
        'enabled' => (bool) env('TRADING_OUTCOME_CHART', true),
        'after_candles' => (int) env('TRADING_OUTCOME_AFTER_CANDLES', 30),
        'before_candles' => (int) env('TRADING_OUTCOME_BEFORE_CANDLES', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data & Charts Retention Cleanup
    |--------------------------------------------------------------------------
    | Number of days to keep strategy evaluation records, specs, and chart images.
    */
    'cleanup' => [
        'retention_days' => (int) env('TRADING_RETENTION_DAYS', 7),
    ],

];
