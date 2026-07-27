<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use App\Music\Scanning\LibraryWatchMonitor;
use App\Models\LibraryActivityLog;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    Cache::forever(
        (string) config('sonotheque.system_health.scheduler_heartbeat_key'),
        now()->toJSON(),
    );
})->name('sonotheque:system-health-heartbeat')->everyMinute();

Schedule::call(
    static fn () => app(LibraryWatchMonitor::class)->run(),
)
    ->name('sonotheque:library-watch')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::call(static function (): void {
    $retentionDays = max(1, (int) config('sonotheque.library_activity_retention_days', 90));

    LibraryActivityLog::query()
        ->where('created_at', '<', now()->subDays($retentionDays))
        ->delete();
})
    ->name('sonotheque:library-activity-cleanup')
    ->daily()
    ->withoutOverlapping();
