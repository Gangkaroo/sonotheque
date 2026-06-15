<?php

namespace App\Music\Scanning;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanLibraryRoot as ScanLibraryRootJob;
use App\Models\LibraryRoot;
use App\Models\ScanRun;

class ScanDispatcher
{
    public function dispatch(LibraryRoot $root, ScanTrigger $trigger = ScanTrigger::Manual): ScanRun
    {
        if (! $root->enabled) {
            throw new ScanDispatchException('The requested library root is disabled.');
        }

        $this->failStaleScans($root);

        $active = $root->scanRuns()
            ->whereIn('status', [ScanStatus::Pending->value, ScanStatus::Running->value])
            ->latest('id')
            ->first();

        if ($active !== null) {
            throw new ScanDispatchException("Scan {$active->id} is already active for this library root.");
        }

        $scanRun = $root->scanRuns()->create([
            'status' => ScanStatus::Pending,
            'trigger' => $trigger,
            'summary' => ['phase' => 'queued'],
        ]);

        ScanLibraryRootJob::dispatch($scanRun->id);

        return $scanRun->refresh();
    }

    private function failStaleScans(LibraryRoot $root): void
    {
        $staleBefore = now()->subMinutes((int) config('music-library.scan_stale_after_minutes', 15));

        $root->scanRuns()
            ->where('status', ScanStatus::Running->value)
            ->where('updated_at', '<', $staleBefore)
            ->each(function (ScanRun $scanRun): void {
                $summary = $scanRun->summary ?? [];
                $issues = $summary['issues'] ?? [];
                $message = 'The scan worker stopped before the scan could finish.';
                $issues[] = [
                    'code' => 'worker_stopped',
                    'severity' => 'error',
                    'message' => $message,
                    'count' => 1,
                ];

                $scanRun->update([
                    'status' => ScanStatus::Failed,
                    'error_count' => $scanRun->error_count + 1,
                    'finished_at' => now(),
                    'summary' => [
                        'phase' => 'failed',
                        'error' => $message,
                        'issues' => array_slice($issues, -50),
                    ],
                ]);
            });
    }
}
