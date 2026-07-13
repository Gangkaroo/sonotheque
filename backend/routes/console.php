<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    Cache::forever(
        (string) config('sonotheque.system_health.scheduler_heartbeat_key'),
        now()->toJSON(),
    );
})->name('sonotheque:system-health-heartbeat')->everyMinute();
