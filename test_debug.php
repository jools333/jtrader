<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$agent = app(\App\Trading\Agent\TradingAgent::class);

// bounceShortCandles logic
$candles = [];
$start = 120.0;
$end = 90.0;
$step = ($end - $start) / 49;
for ($i = 0; $i < 50; $i++) {
    $c = $start + $step * $i;
    // candle(float $open, float $high, float $low, float $close, float $vol = 1000)
    $candles[] = new \App\Market\DTO\Candle(time()*1000 + $i, $c - 0.1, $c + 1, $c - 1, $c, 1000);
}
$candles[] = new \App\Market\DTO\Candle(time()*1000, 99.0, 100.8, 99.3, 100.1, 1000);
$candles[] = new \App\Market\DTO\Candle(time()*1000, 100.1, 100.6, 99.4, 99.9, 1000);
$candles[] = new \App\Market\DTO\Candle(time()*1000, 99.9, 100.5, 99.2, 100.0, 1000);
$candles[] = new \App\Market\DTO\Candle(time()*1000, 103.5, 103.7, 98.0, 98.2, 2000);

$res = $agent->evaluate($candles, 100.0, 10.0);
var_dump($res->entrySignal);

$strategy = new \App\Trading\Strategies\Entry\BounceStrategy();
$closes = array_map(fn($c) => $c->close, $candles);
$ema8 = \App\Market\Analysis\Support\SeriesMath::ema($closes, 8);
$ema21 = \App\Market\Analysis\Support\SeriesMath::ema($closes, 21);
$ema50 = \App\Market\Analysis\Support\SeriesMath::ema($closes, 50);
$ctx = new \App\Trading\Agent\RuleContext($candles, 100.0, 10.0, $ema8, $ema21, $ema50, ['line'=>[], 'signal'=>[], 'histogram'=>[]]);
$eval = $strategy->diagnoseShort($ctx, app(\App\Trading\Agent\TradePlanner::class), $ctx->slice(25), $ctx->last());
var_dump($eval->missingCriteria);
