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
    'symbol_risk_pct'  => [
        'ADA-USDT'  => (float) env('TRADING_RISK_PCT_ADA', 0.75),
        'DOGE-USDT' => (float) env('TRADING_RISK_PCT_DOGE', 0.75),
    ],
    'paper_balance'    => (float) env('TRADING_PAPER_BALANCE', 1_000.0),
    'max_quantity'     => (float) env('TRADING_MAX_QTY', 0.0),
    // Hard cap: notional position value ≤ X% of balance (0 = disabled).
    // Prevents oversized positions when the stop is very tight relative to price.
    'max_position_pct' => (float) env('TRADING_MAX_POSITION_PCT', 10.0),
    // Minimum cooldown in minutes between positions on the same symbol (0 = disabled).
    'entry_cooldown_minutes' => (int) env('TRADING_ENTRY_COOLDOWN_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Excluded symbols from trading
    |--------------------------------------------------------------------------
    | Symbols monitored for market regime / intermarket signals but excluded
    | from opening trades.
    */
    'excluded_symbols' => [
        'BTC-USDT',
    ],

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
        'min_entry_score' => (float) env('TRADING_MIN_ENTRY_SCORE', 80.0),
        'min_rr' => 2.0,          // reject entries with reward:risk below this
        'max_atr_travel' => 0.60, // skip if price ran > 60% of ATR off the level
        'min_flat_width' => 0.30, // skip if last 5 candles span < ATR*0.30 (dead flat)
        'stop_atr' => 1.0,        // stop sits ATR*1.0 beyond the level
        'target1_r' => 2.0,       // target 1 at 2R
        'target2_r' => 4.0,       // target 2 at 4R

        // BTC Anchor (межрыночный фильтр)
        'btc_filter_enabled' => (bool) env('TRADING_BTC_FILTER_ENABLED', true),
        'btc_max_dump_percent' => (float) env('TRADING_BTC_MAX_DUMP_PCT', 0.20), // макс допустимый дамп BTC за 3 свечи для входа в LONG
        'btc_max_pump_percent' => (float) env('TRADING_BTC_MAX_PUMP_PCT', 0.20), // макс допустимый памп BTC за 3 свечи для входа в SHORT
        'btc_fast_exit_dump_percent' => (float) env('TRADING_BTC_FAST_EXIT_DUMP_PCT', 0.35), // импульсный дамп BTC для опережающего выхода из LONG
        'btc_fast_exit_pump_percent' => (float) env('TRADING_BTC_FAST_EXIT_PUMP_PCT', 0.35), // импульсный памп BTC для опережающего выхода из SHORT
        'min_hold_seconds' => (int) env('TRADING_MIN_HOLD_SECONDS', 180), // минимальное время удержания позиции (сек) перед досрочным выходом по рынку

        // Настройки BounceStrategy
        'bounce_lookback_candles' => 10,  // Количество свечей для поиска локального минимума/максимума
        'bounce_level_approach_atr' => 0.50, // Допустимая зона от уровня для теста (в ATR)
        'bounce_entry_zone_atr' => (float) env('TRADING_BOUNCE_ENTRY_ZONE_ATR', 0.65), // Допустимая зона для точки входа с учетом отскока (в ATR)
        'bounce_reversal_atr' => 0.10,    // Требуемый отскок от экстремума (в ATR)
        'bounce_min_atr_percent' => 0.20, // Минимальный ATR в процентах от цены
        'bounce_stop_atr_buffer' => (float) env('TRADING_BOUNCE_STOP_ATR_BUFFER', 0.25), // Буфер стоп-лосса за уровнем/экстремумом (в ATR)
        'bounce_volume_multiplier' => (float) env('TRADING_BOUNCE_VOLUME_MULT', 1.15), // Мин. всплеск объема на триггерной свече отскока
        'bounce_climax_volume_mult' => (float) env('TRADING_BOUNCE_CLIMAX_MULT', 2.20), // Порог кульминации пробоя (падающий нож)
        
        // Настройки тейк-профита, комиссий и защитного стопа
        'tp_order_type' => env('TRADING_TP_ORDER_TYPE', 'TAKE_PROFIT_MARKET'), // TAKE_PROFIT_MARKET (Taker) или TAKE_PROFIT (Maker)
        'tp_percent' => (float) env('TRADING_TP_PCT', 0.35),             // Чистый профит Target 1 (50% объема) в процентах от цены
        'tp_multiplier' => 2.0,           // Во сколько раз Target 2 больше Target 1
        'catastrophic_stop_percent' => 2.0, // Дальний защитный стоп-лосс на случай краха рынка
        'fee_maker_percent' => 0.02,      // Комиссия Maker (лимитный ордер)
        'fee_taker_percent' => 0.05,      // Комиссия Taker (рыночный ордер)

        // Настройки BtcLeadLagStrategy (опережающе-запаздывающий арбитраж за BTC)
        'lead_lag_enabled' => (bool) env('TRADING_LEAD_LAG_ENABLED', false),
        'lead_lag_btc_impulse_pct' => (float) env('TRADING_LEAD_LAG_BTC_IMPULSE_PCT', 0.40), // мин. импульс BTC (%) за 1-2 свечи
        'lead_lag_min_gap_pct' => (float) env('TRADING_LEAD_LAG_MIN_GAP_PCT', 0.25),         // мин. запаздывание альта относительно BTC (%)
        'lead_lag_cooldown_minutes' => (int) env('TRADING_LEAD_LAG_COOLDOWN_MINUTES', 5),    // кулдаун между импульсами (мин)
        'lead_lag_min_score' => (float) env('TRADING_LEAD_LAG_MIN_SCORE', 75.0),             // мин. скор для входа (%)
        'lead_lag_tp_percent' => (float) env('TRADING_LEAD_LAG_TP_PCT', 0.40),                // чистый профит Target 1 для импульса (%)
        'lead_lag_stop_atr' => (float) env('TRADING_LEAD_LAG_STOP_ATR', 1.0),                 // стоп-лосс в ATR от входа
    ],

    /*
    |--------------------------------------------------------------------------
    | Position and evaluation chart rendering
    |--------------------------------------------------------------------------
    | When enabled, each opened/closed position and evaluation gets a PNG chart.
    */
    'chart' => [
        'enabled' => (bool) env('TRADING_CHART', false),
        'queue' => (bool) env('TRADING_CHART_QUEUE', true),
        'max_concurrent' => (int) env('TRADING_CHART_MAX_CONCURRENT', 1),
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
        'queue' => (bool) env('TRADING_OUTCOME_CHART_QUEUE', true),
        'limit' => (int) env('TRADING_OUTCOME_CHART_LIMIT', 5),
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
        'retention_days' => (int) env('TRADING_RETENTION_DAYS', 3),
    ],

];
