<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Market\DTO\Candle;
use App\Trading\Agent\RuleContext;
use App\Trading\Agent\TradePlanner;
use App\Trading\Strategies\Entry\BounceStrategy;

$candles = [];
for ($i=0; $i<45; $i++) {
    $candles[] = new Candle($i*1000, 99.8, 100.5, 99.5, 100.0, 1.0, $i*1000+500);
}
for ($i=0; $i<5; $i++) {
    $candles[] = new Candle(($i+45)*1000, 100.0, 100.5, 99.5, 100.0, 1.0, ($i+45)*1000+500);
}

$ctx = new RuleContext($candles, 100.0, 10.0, [], [], [], ['line'=>[],'signal'=>[],'histogram'=>[]], 'BTC', '5m');
$diag = (new BounceStrategy())->diagnose($ctx, new TradePlanner());
var_dump($diag->isFullSignal, $diag->score, $diag->missingCriteria);
