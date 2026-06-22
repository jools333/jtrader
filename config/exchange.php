<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Active exchange driver
    |--------------------------------------------------------------------------
    | Bound to App\Market\Contracts\ExchangeInterface in ExchangeServiceProvider.
    | Switch this (or add a driver below) to move off BingX without touching the
    | rest of the application.
    */
    'default' => env('EXCHANGE_DRIVER', 'bingx'),

    /*
    |--------------------------------------------------------------------------
    | Traded pairs
    |--------------------------------------------------------------------------
    | Symbols use the exchange's native format (BingX: BASE-QUOTE).
    */
    'pairs' => [
        'BTC-USDT',
        'ETH-USDT',
        'SOL-USDT',
        'BNB-USDT',
        'XRP-USDT',
        'LINK-USDT',
        'ADA-USDT',
        'DOGE-USDT',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported timeframes (interval -> approx. seconds, used for sync math)
    |--------------------------------------------------------------------------
    */
    'timeframes' => [
        '1m'  => 60,
        '5m'  => 300,
        '15m' => 900,
        '1h'  => 3600,
        '4h'  => 14400,
        '1d'  => 86400,
    ],

    'default_pair' => 'BTC-USDT',
    'default_timeframe' => '5m',

    // How many candles to pull/keep per (pair, timeframe).
    'klines_limit' => 500,

    /*
    |--------------------------------------------------------------------------
    | Driver configuration
    |--------------------------------------------------------------------------
    */
    'drivers' => [

        'bingx' => [
            'base_url' => env('BINGX_BASE_URL', 'https://open-api.bingx.com'),
            'api_key' => env('BINGX_API_KEY', ''),
            'api_secret' => env('BINGX_API_SECRET', ''),
            // BingX USDT-M perpetual ("swap") public market data is used.
            'market' => env('BINGX_MARKET', 'swap'),
            'timeout' => 15,

            // Demo (paper) trading. BingX exposes a separate perpetual-futures
            // demo environment that settles in virtual USDT (VST); orders are
            // routed to its own host with demo API keys. Public market data is
            // identical, so only order routing switches hosts.
            'demo' => (bool) env('BINGX_DEMO', false),
            'base_url_demo' => env('BINGX_BASE_URL_DEMO', 'https://open-api-vst.bingx.com'),
        ],

    ],

];
