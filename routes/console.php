<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Candle data is now kept live by the `ws` container (candles:ws WebSocket stream).
// candles:sync remains available for manual one-off pulls and as a fallback seed.
