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
            ini_set('memory_limit', $memoryLimit);
        }

        try {
            LibraryRoot::query()
                ->where('enabled', true)
                ->where('watch_enabled', true)
                ->orderBy('id')
                ->each(fn (LibraryRoot $root) => $this->inspect($root));
        } finally {
            if (is_string($previousMemoryLimit) && $previousMemoryLimit !== '') {
                ini_set('memory_limit', $previousMemoryLimit);
            }
        }
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
            ->get(['relative_path', 'relative_path_hash', 'signature', 'artwork_signature'])
            ->keyBy('relative_path_hash');
        $firstSnapshot = $stored->isEmpty();
        $changes = [];
        $artworkChanged = false;

        foreach ($snapshot->directories as $directory) {
            $previous = $stored->get($directory['relative_path_hash']);

            if ($previous === null || $previous->signature !== $directory['signature']) {
                $changes[] = $directory['relative_path'];
            }
            if ($previous !== null && $previous->artwork_signature !== $directory['artwork_signature']) {
                $artworkChanged = true;
            }

            $stored->forget($directory['relative_path_hash']);
        }

        foreach ($stored as $removed) {
            $changes[] = $this->parentPath($removed->relative_path);
            if ($removed->artwork_signature !== hash('sha256', '')) {
                $artworkChanged = true;
            }
        }

        $changes = array_values(array_unique($changes));
        $reconciliationDue = $root->last_scanned_at === null
            || $root->last_scanned_at->lte(
                now()->subMinutes($root->watch_reconcile_interval_minutes),
            );
        $needsScan = $firstSnapshot || $changes !== [] || $reconciliationDue;

        if (! $needsScan) {
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

        $subtreePath = $firstSnapshot || $reconciliationDue || $artworkChanged
            ? null
            : $this->commonAncestor($changes);

        try {
            $scan = $this->scanDispatcher->dispatch(
                $root,
                ScanTrigger::Watcher,
                $subtreePath,
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

    private function parentPath(string $path): string
    {
        $parent = str_replace('\\', '/', dirname($path));

        return $parent === '.' ? '' : trim($parent, '/');
    }
}
