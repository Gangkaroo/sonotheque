<?php

namespace App\Jobs;

use App\Models\ScanRun;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Music\Scanning\LibraryActivityLogger;
use App\Music\Scanning\LibraryScanner;
use Throwable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanLibraryRoot implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $scanRunId)
    {
    }

    public function handle(
        LibraryScanner $scanner,
        LibraryActivityLogger $activityLogger,
    ): void {
        $memoryLimit = config('sonotheque.scan_memory_limit');

        if (is_string($memoryLimit) && $memoryLimit !== '') {
            ini_set('memory_limit', $memoryLimit);
        }

        $scanRun = ScanRun::findOrFail($this->scanRunId);
        $scanner->scan($scanRun);
        $scanRun->refresh()->loadMissing('libraryRoot');
        $source = $this->activitySource($scanRun);

        if ($scanRun->status === ScanStatus::Completed) {
            if ($scanRun->trigger === ScanTrigger::Watcher) {
                $this->completeWatcher($scanRun);
            }

            $activityLogger->record(
                source: $source,
                severity: 'info',
                code: 'scan_completed',
                message: 'The library scan completed.',
                scanRun: $scanRun,
                path: $scanRun->subtree_path,
                context: [
                    'filesAdded' => $scanRun->files_added,
                    'filesUpdated' => $scanRun->files_updated,
                    'filesRemoved' => $scanRun->files_removed,
                    'warnings' => $scanRun->warning_count,
                    'errors' => $scanRun->error_count,
                ],
            );
        } elseif ($scanRun->status === ScanStatus::Cancelled) {
            $this->resetWatcher($scanRun);

            $activityLogger->record(
                source: $source,
                severity: 'warning',
                code: 'scan_cancelled',
                message: 'The library scan was cancelled.',
                scanRun: $scanRun,
                path: $scanRun->subtree_path,
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $scanRun = ScanRun::query()->with('libraryRoot')->find($this->scanRunId);

        if ($scanRun === null) {
            return;
        }

        $message = $exception?->getMessage() ?? 'The automatic scan failed.';
        $this->resetWatcher($scanRun, $message);

        app(LibraryActivityLogger::class)->record(
            source: $this->activitySource($scanRun),
            severity: 'error',
            code: 'scan_failed',
            message: $exception?->getMessage() ?? 'The library scan failed.',
            scanRun: $scanRun,
            path: $scanRun->subtree_path,
        );
    }

    private function activitySource(ScanRun $scanRun): string
    {
        return $scanRun->trigger === ScanTrigger::Watcher ? 'watcher' : 'scan';
    }

    private function resetWatcher(ScanRun $scanRun, ?string $error = null): void
    {
        if ($scanRun->trigger !== ScanTrigger::Watcher) {
            return;
        }

        $root = $scanRun->libraryRoot->refresh();
        if (! $root->watch_enabled) {
            $root->update([
                'watch_status' => 'disabled',
                'watch_checked_at' => null,
                'watch_last_path' => null,
                'watch_error' => null,
            ]);

            return;
        }

        $root->watchDirectories()->delete();
        $attributes = [
            'watch_status' => 'pending',
            'watch_checked_at' => null,
        ];

        if ($error !== null) {
            $attributes['watch_error'] = $error;
        }

        $root->update($attributes);
    }

    private function completeWatcher(ScanRun $scanRun): void
    {
        $root = $scanRun->libraryRoot->refresh();
        if (! $root->watch_enabled) {
            $root->update([
                'watch_status' => 'disabled',
                'watch_checked_at' => null,
                'watch_last_path' => null,
                'watch_error' => null,
            ]);

            return;
        }

        $root->update([
            'watch_status' => 'watching',
            'watch_last_scan_at' => now(),
            'watch_error' => null,
        ]);
    }
}
