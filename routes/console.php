<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Candle data is now kept live by the `ws` container (candles:ws WebSocket stream).
// candles:sync remains available for manual one-off pulls and as a fallback seed.

// Активная стратегия работает на закрытых 1h-свечах: сканировать чаще, чем
// раз в 5 минут, бессмысленно (раньше каждый скан ещё и плодил записи в
// strategy_evaluations). Стопы/тейки при этом живут на бирже, а позиции
// синхронизируются отдельной командой positions:sync раз в минуту.
Schedule::command('agent:scan')
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
