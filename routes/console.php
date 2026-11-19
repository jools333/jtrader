<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Candle data is now kept live by the `ws` container (candles:ws WebSocket stream).
// candles:sync remains available for manual one-off pulls and as a fallback seed.

// Стратегия №1 (LEVEL_RETEST, 1h): скан каждую минуту для входов и управления позициями.
Schedule::command('agent:scan --interval=1h')
    ->everyMinute()
    ->withoutOverlapping(5);

// Стратегия №2 (SQUEEZE_BREAKOUT, 4h): скан раз в 5 минут для входов по закрытию 4h-свечи
// и подтягивания Chandelier ATR трейлинг-стопа.
Schedule::command('agent:scan --interval=4h')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

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
    ->timezone(config('services.telegram.report_timezone', 'Europe/Moscow'))
    ->withoutOverlapping(10);
