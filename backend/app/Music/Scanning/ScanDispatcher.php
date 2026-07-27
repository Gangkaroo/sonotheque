<?php

namespace App\Music\Scanning;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanLibraryRoot as ScanLibraryRootJob;
use App\Models\LibraryRoot;
use App\Models\ScanRun;
use App\Models\ScanRunIssue;

class ScanDispatcher
{
    public function __construct(
        private readonly LibraryDirectoryResolver $directoryResolver,
        private readonly LibraryActivityLogger $activityLogger,
    ) {
    }

    public function dispatch(
        LibraryRoot $root,
        ScanTrigger $trigger = ScanTrigger::Manual,
        ?string $subtreePath = null,
    ): ScanRun {
        if (! $root->enabled) {
            throw new ScanDispatchException('The requested library root is disabled.');
        }

        if ($subtreePath !== null && trim($subtreePath) !== '') {
            try {
                $subtreePath = $this->directoryResolver->resolve($root, $subtreePath)->relativePath;
            } catch (InvalidLibraryPath $exception) {
                throw new ScanDispatchException($exception->getMessage(), 'subtreePath');
            }
        } else {
            $subtreePath = null;
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
            'subtree_path' => $subtreePath,
            'summary' => array_filter([
                'phase' => 'queued',
                'subtreePath' => $subtreePath,
            ]),
        ]);

        ScanLibraryRootJob::dispatch($scanRun->id);
        $this->activityLogger->record(
            source: $trigger === ScanTrigger::Watcher ? 'watcher' : 'scan',
            severity: 'info',
            code: 'scan_queued',
            message: $subtreePath === null
                ? 'A complete library scan was queued.'
                : 'A library subtree scan was queued.',
            libraryRoot: $root,
            scanRun: $scanRun,
            path: $subtreePath,
        );

        return $scanRun->refresh();
    }

    private function failStaleScans(LibraryRoot $root): void
    {
        $staleBefore = now()->subMinutes((int) config('sonotheque.scan_stale_after_minutes', 15));

        $root->scanRuns()
            ->where('status', ScanStatus::Running->value)
            ->where('updated_at', '<', $staleBefore)
            ->each(function (ScanRun $scanRun) use ($root): void {
                $summary = $scanRun->summary ?? [];
                $issues = $summary['issues'] ?? [];
                $message = 'The scan worker stopped before the scan could finish.';
                $issue = [
                    'code' => 'worker_stopped',
                    'severity' => 'error',
                    'message' => $message,
                    'count' => 1,
                ];
                $issues[] = $issue;
                ScanRunIssue::query()->create([
                    'scan_run_id' => $scanRun->id,
                    'code' => $issue['code'],
                    'severity' => $issue['severity'],
                    'message' => $issue['message'],
                    'occurrence_count' => $issue['count'],
                ]);
                $this->activityLogger->record(
                    source: 'scan',
                    severity: $issue['severity'],
                    code: $issue['code'],
                    message: $issue['message'],
                    libraryRoot: $root,
                    scanRun: $scanRun,
                    count: $issue['count'],
                );

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
