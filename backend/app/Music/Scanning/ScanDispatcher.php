<?php

namespace App\Music\Scanning;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanLibraryRoot as ScanLibraryRootJob;
use App\Models\LibraryRoot;
use App\Models\ScanRun;
use App\Models\ScanRunIssue;
use Illuminate\Support\Facades\DB;

class ScanDispatcher
{
    public function __construct(
        private readonly LibraryDirectoryResolver $directoryResolver,
        private readonly LibraryPathGuard $pathGuard,
        private readonly LibraryActivityLogger $activityLogger,
    ) {
    }

    public function dispatch(
        LibraryRoot $root,
        ScanTrigger $trigger = ScanTrigger::Manual,
        ?string $subtreePath = null,
        ?array $scanPaths = null,
        ?array $missingPaths = null,
    ): ScanRun {
        if (! $root->enabled) {
            throw new ScanDispatchException('The requested library root is disabled.');
        }

        $isDeltaScan = $scanPaths !== null || $missingPaths !== null;
        $scanPaths = $isDeltaScan ? $this->existingPaths($root, $scanPaths ?? []) : null;
        $missingPaths = $isDeltaScan ? $this->missingPaths($missingPaths ?? []) : null;

        if ($subtreePath !== null && trim($subtreePath) !== '') {
            try {
                $subtreePath = $isDeltaScan
                    ? $this->pathGuard->normalizeRelativeDirectoryPath($subtreePath)
                    : $this->directoryResolver->resolve($root, $subtreePath)->relativePath;
            } catch (InvalidLibraryPath $exception) {
                throw new ScanDispatchException($exception->getMessage(), 'subtreePath');
            }
        } else {
            $subtreePath = null;
        }

        $scanRun = DB::transaction(function () use (
            $root,
            $trigger,
            $subtreePath,
            $scanPaths,
            $missingPaths,
        ): ScanRun {
            $lockedRoot = LibraryRoot::query()
                ->lockForUpdate()
                ->findOrFail($root->id);

            if (! $lockedRoot->enabled) {
                throw new ScanDispatchException('The requested library root is disabled.');
            }

            $this->failStaleScans($lockedRoot);

            $active = $lockedRoot->scanRuns()
                ->whereIn('status', [ScanStatus::Pending->value, ScanStatus::Running->value])
                ->latest('id')
                ->first();

            if ($active !== null) {
                throw new ScanDispatchException("Scan {$active->id} is already active for this library root.");
            }

            return $lockedRoot->scanRuns()->create([
                'status' => ScanStatus::Pending,
                'trigger' => $trigger,
                'subtree_path' => $subtreePath,
                'scan_paths' => $scanPaths,
                'missing_paths' => $missingPaths,
                'summary' => array_filter([
                    'phase' => 'queued',
                    'subtreePath' => $subtreePath,
                    'scanPaths' => $scanPaths,
                    'missingPaths' => $missingPaths,
                ]),
            ]);
        });

        ScanLibraryRootJob::dispatch($scanRun->id);
        $this->activityLogger->record(
            source: $trigger === ScanTrigger::Watcher ? 'watcher' : 'scan',
            severity: 'info',
            code: 'scan_queued',
            message: $isDeltaScan
                ? 'A targeted library change scan was queued.'
                : ($subtreePath === null
                ? 'A complete library scan was queued.'
                : 'A library subtree scan was queued.'),
            libraryRoot: $root,
            scanRun: $scanRun,
            path: $subtreePath,
        );

        return $scanRun->refresh();
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function existingPaths(LibraryRoot $root, array $paths): array
    {
        try {
            return collect($paths)
                ->map(fn (string $path): ?string => $this->directoryResolver
                    ->resolve($root, $path)
                    ->relativePath)
                ->map(fn (?string $path): string => $path ?? '')
                ->unique()
                ->values()
                ->all();
        } catch (InvalidLibraryPath $exception) {
            throw new ScanDispatchException($exception->getMessage(), 'scanPaths');
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function missingPaths(array $paths): array
    {
        try {
            return collect($paths)
                ->map(fn (string $path): ?string => $this->pathGuard
                    ->normalizeRelativeDirectoryPath($path))
                ->map(fn (?string $path): string => $path ?? '')
                ->unique()
                ->values()
                ->all();
        } catch (InvalidLibraryPath $exception) {
            throw new ScanDispatchException($exception->getMessage(), 'missingPaths');
        }
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
