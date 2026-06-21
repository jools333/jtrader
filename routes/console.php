<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the local candle store fresh (handled by the `scheduler` container,
// which runs `schedule:work` — sub-minute frequencies are supported).
// withoutOverlapping() guarantees a new sync won't start while the previous one
// is still running (redis-backed mutex). No runInBackground(): it forks the
// process and makes the overlap lock unreliable, and there's only this one task.
// The 5-minute expiry lets a crashed run's stale lock self-heal.
Schedule::command('candles:sync')
    ->everyThirtySeconds()
    ->withoutOverlapping(5);
