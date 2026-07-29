<?php

namespace App\Music\Scanning;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\LibraryRoot;
use App\Models\LibraryWatchDirectory;
use Illuminate\Support\Facades\DB;
use Throwable;

class LibraryWatchMonitor
{
    public function __construct(
        private readonly LibraryWatchSnapshotter $snapshotter,
        private readonly ScanDispatcher $scanDispatcher,
        private readonly LibraryActivityLogger $activityLogger,
    ) {
    }

    public function run(): void
    {
        $previousMemoryLimit = ini_get('memory_limit');
        $memoryLimit = config('sonotheque.scan_memory_limit');

        if (is_string($memoryLimit) && $memoryLimit !== '') {
            $this->applyMemoryLimit($memoryLimit);
        }

        try {
            LibraryRoot::query()
                ->where('enabled', true)
                ->where('watch_enabled', true)
                ->orderBy('id')
                ->each(fn (LibraryRoot $root) => $this->inspect($root));
        } finally {
            if (is_string($previousMemoryLimit) && $previousMemoryLimit !== '') {
                $this->applyMemoryLimit($previousMemoryLimit);
            }
        }
    }

    private function applyMemoryLimit(string $memoryLimit): void
    {
        $bytes = ini_parse_quantity($memoryLimit);

        if ($bytes >= 0 && $bytes < memory_get_usage(true)) {
            return;
        }

        ini_set('memory_limit', $memoryLimit);
    }

    private function inspect(LibraryRoot $root): void
    {
        if (! $this->isDue($root)) {
            return;
        }

        try {
            $snapshot = $this->snapshotter->capture($root);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $changed = $root->watch_status !== 'unavailable' || $root->watch_error !== $message;
            $root->update([
                'watch_status' => 'unavailable',
                'watch_checked_at' => now(),
                'watch_error' => $message,
            ]);

            if ($changed) {
                $this->activityLogger->record(
                    source: 'watcher',
                    severity: 'error',
                    code: 'watch_root_unavailable',
                    message: $message,
                    libraryRoot: $root,
                );
            }

            return;
        }

        $stored = $root->watchDirectories()
            ->get([
                'relative_path',
                'relative_path_hash',
                'signature',
                'file_signature',
                'artwork_signature',
            ])
            ->keyBy('relative_path_hash');
        $firstSnapshot = $stored->isEmpty();
        $scanPaths = [];
        $missingPaths = [];
        $artworkChanged = false;
        $snapshotUpgradeNeeded = false;

        foreach ($snapshot->directories as $directory) {
            $previous = $stored->get($directory['relative_path_hash']);

            if ($previous === null) {
                $scanPaths[] = $directory['relative_path'];
            } elseif ($previous->file_signature === null) {
                $snapshotUpgradeNeeded = true;

                if ($previous->signature !== $directory['signature']) {
                    $scanPaths[] = $directory['relative_path'];
                }
            } elseif ($previous->file_signature !== $directory['file_signature']) {
                $scanPaths[] = $directory['relative_path'];
            }

            if ($previous !== null
                && $previous->artwork_signature !== $directory['artwork_signature']) {
                $artworkChanged = true;
                $scanPaths[] = $directory['relative_path'];
            }

            $stored->forget($directory['relative_path_hash']);
        }

        foreach ($stored as $removed) {
            $missingPaths[] = $removed->relative_path;
        }

        $scanPaths = $this->collapseNestedPaths($scanPaths);
        $missingPaths = $this->collapseNestedPaths($missingPaths);
        $changes = array_values(array_unique([...$scanPaths, ...$missingPaths]));
        $reconciliationDue = $root->last_scanned_at === null
            || $root->last_scanned_at->lte(
                now()->subMinutes($root->watch_reconcile_interval_minutes),
            );
        $needsScan = $firstSnapshot || $changes !== [] || $reconciliationDue;

        if (! $needsScan) {
            if ($snapshotUpgradeNeeded) {
                $this->replaceSnapshot($root, $snapshot);
            }

            $root->update([
                'watch_status' => 'watching',
                'watch_checked_at' => now(),
                'watch_error' => null,
            ]);

            return;
        }

        if ($this->hasActiveScan($root)) {
            $this->markPending($root);

            return;
        }

        $fullReconciliation = $firstSnapshot || $reconciliationDue;
        $subtreePath = $fullReconciliation ? null : $this->commonAncestor($changes);

        try {
            $scan = $fullReconciliation
                ? $this->scanDispatcher->dispatch(
                    $root,
                    ScanTrigger::Watcher,
                )
                : $this->scanDispatcher->dispatch(
                    $root,
                    ScanTrigger::Watcher,
                    $subtreePath,
                    $scanPaths,
                    $missingPaths,
                );
        } catch (ScanDispatchException) {
            $this->markPending($root);

            return;
        }

        $this->replaceSnapshot($root, $snapshot);
        $eventPath = $subtreePath ?? ($changes === [] ? null : '');
        $root->update([
            'watch_status' => 'scanning',
            'watch_checked_at' => now(),
            'watch_last_event_at' => now(),
            'watch_last_path' => $eventPath,
            'watch_error' => null,
        ]);

        $this->activityLogger->record(
            source: 'watcher',
            severity: 'info',
            code: $reconciliationDue || $firstSnapshot
                ? 'watch_reconciliation_queued'
                : 'watch_change_detected',
            message: $reconciliationDue || $firstSnapshot
                ? 'A periodic library reconciliation scan was queued.'
                : 'Filesystem changes were detected and an automatic scan was queued.',
            libraryRoot: $root,
            scanRun: $scan,
            path: $eventPath,
            context: [
                'changedDirectoryCount' => count($changes),
                'scanPathCount' => count($scanPaths),
                'missingPathCount' => count($missingPaths),
                'artworkChanged' => $artworkChanged,
            ],
        );
    }

    private function isDue(LibraryRoot $root): bool
    {
        return $root->watch_checked_at === null
            || $root->watch_checked_at->lte(
                now()->subMinutes($root->watch_poll_interval_minutes),
            );
    }

    private function hasActiveScan(LibraryRoot $root): bool
    {
        return $root->scanRuns()
            ->whereIn('status', [ScanStatus::Pending->value, ScanStatus::Running->value])
            ->exists();
    }

    private function markPending(LibraryRoot $root): void
    {
        $root->update([
            'watch_status' => 'pending',
            'watch_checked_at' => now(),
            'watch_error' => null,
        ]);
    }

    private function replaceSnapshot(LibraryRoot $root, LibraryWatchSnapshot $snapshot): void
    {
        DB::transaction(function () use ($root, $snapshot): void {
            $root->watchDirectories()->delete();
            $timestamp = now();

            foreach (array_chunk($snapshot->directories, 500) as $chunk) {
                LibraryWatchDirectory::query()->insert(array_map(
                    fn (array $directory): array => [
                        'library_root_id' => $root->id,
                        ...$directory,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    $chunk,
                ));
            }
        });
    }

    /** @param list<string> $paths */
    private function commonAncestor(array $paths): ?string
    {
        $paths = array_values(array_unique($paths));

        if ($paths === [] || in_array('', $paths, true)) {
            return null;
        }

        $segments = array_map(
            static fn (string $path): array => explode('/', trim($path, '/')),
            $paths,
        );
        $common = array_shift($segments) ?? [];

        foreach ($segments as $candidate) {
            $length = min(count($common), count($candidate));
            $matched = 0;

            while ($matched < $length
                && $this->segmentsEqual($common[$matched], $candidate[$matched])) {
                $matched++;
            }

            $common = array_slice($common, 0, $matched);
        }

        return $common === [] ? null : implode('/', $common);
    }

    private function segmentsEqual(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? mb_strtolower($left) === mb_strtolower($right)
            : $left === $right;
    }

    /** @param list<string> $paths
     *  @return list<string>
     */
    private function collapseNestedPaths(array $paths): array
    {
        $paths = array_values(array_unique($paths));
        usort(
            $paths,
            static fn (string $left, string $right): int => substr_count($left, '/')
                <=> substr_count($right, '/'),
        );
        $collapsed = [];

        foreach ($paths as $path) {
            $nested = false;

            foreach ($collapsed as $parent) {
                if ($parent === '' || $this->pathWithin($path, $parent)) {
                    $nested = true;

                    break;
                }
            }

            if (! $nested) {
                $collapsed[] = $path;
            }
        }

        return $collapsed;
    }

    private function pathWithin(string $path, string $parent): bool
    {
        $comparisonPath = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($path) : $path;
        $comparisonParent = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($parent) : $parent;

        return $comparisonPath === $comparisonParent
            || str_starts_with($comparisonPath, $comparisonParent.'/');
    }
}
