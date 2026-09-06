<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Candle data is now kept live by the `ws` container (candles:ws WebSocket stream).
// candles:sync remains available for manual one-off pulls and as a fallback seed.

Schedule::command('agent:scan')
    ->everyThirtySeconds()
    ->withoutOverlapping(2);

Schedule::command('strategy:render-outcomes')
    ->everyTwoMinutes()
    ->withoutOverlapping(10);

Schedule::command('strategy:prune')
    ->daily()
    ->withoutOverlapping(60);

Schedule::command('positions:sync')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('report:daily-telegram --sync')
    ->dailyAt('23:55')
    ->withoutOverlapping(10);
