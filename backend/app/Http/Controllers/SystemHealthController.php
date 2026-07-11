<?php

namespace App\Http\Controllers;

use App\Enums\ScanStatus;
use App\Models\LibraryRoot;
use App\Models\ScanRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->database();
        $queue = $this->queue();
        $storage = $this->storage();
        $roots = $database['status'] === 'ok' ? $this->roots() : [];
        $scans = $database['status'] === 'ok' ? $this->scans() : $this->emptyScans();

        return response()->json([
            'status' => $this->overallStatus($database, $queue, $storage, $roots),
            'checkedAt' => now()->toJSON(),
            'app' => [
                'environment' => app()->environment(),
                'url' => config('app.url'),
                'lanEnabled' => (bool) config('music-library.lan.enabled'),
                'localProxyEnabled' => (bool) config('music-library.lan.local_proxy_enabled'),
                'queueConnection' => config('queue.default'),
                'cacheStore' => config('cache.default'),
            ],
            'database' => $database,
            'queue' => $queue,
            'scheduler' => [
                'status' => 'unknown',
                'observable' => false,
                'message' => 'No scheduler heartbeat has been recorded yet.',
            ],
            'storage' => $storage,
            'libraryRoots' => $roots,
            'scans' => $scans,
        ]);
    }

    /** @return array{status: string, connection: string, message: string|null} */
    private function database(): array
    {
        try {
            DB::connection()->select('select 1');

            return [
                'status' => 'ok',
                'connection' => (string) config('database.default'),
                'message' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'error',
                'connection' => (string) config('database.default'),
                'message' => $exception->getMessage(),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        $connection = (string) config('queue.default');
        $payload = [
            'status' => 'ok',
            'connection' => $connection,
            'pending' => null,
            'reserved' => null,
            'delayed' => null,
            'failed' => null,
            'latestFailed' => [],
            'message' => null,
        ];

        if ($connection !== 'database') {
            $payload['status'] = 'unknown';
            $payload['message'] = 'Only database queues can be inspected from this health check.';

            return $payload;
        }

        try {
            $jobsTable = (string) config('queue.connections.database.table', 'jobs');
            $failedJobsTable = (string) config('queue.failed.table', 'failed_jobs');
            $now = now()->timestamp;

            $payload['pending'] = DB::table($jobsTable)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->count();
            $payload['reserved'] = DB::table($jobsTable)->whereNotNull('reserved_at')->count();
            $payload['delayed'] = DB::table($jobsTable)
                ->whereNull('reserved_at')
                ->where('available_at', '>', $now)
                ->count();
            $payload['failed'] = DB::table($failedJobsTable)->count();
            $payload['latestFailed'] = DB::table($failedJobsTable)
                ->select(['id', 'queue', 'exception', 'failed_at'])
                ->orderByDesc('failed_at')
                ->limit(5)
                ->get()
                ->map(fn (object $job): array => [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'failedAt' => $job->failed_at,
                    'message' => $this->firstExceptionLine((string) $job->exception),
                ])
                ->values()
                ->all();

            if ($payload['failed'] > 0) {
                $payload['status'] = 'warning';
            }
        } catch (Throwable $exception) {
            $payload['status'] = 'error';
            $payload['message'] = $exception->getMessage();
        }

        return $payload;
    }

    /** @return list<array{name: string, path: string, exists: bool, writable: bool, status: string}> */
    private function storage(): array
    {
        return collect([
            'Application storage' => storage_path('app/private'),
            'Artwork cache' => storage_path('app/artwork'),
            'Framework cache' => storage_path('framework/cache'),
            'Sessions' => storage_path('framework/sessions'),
            'Logs' => storage_path('logs'),
        ])->map(fn (string $path, string $name): array => [
            'name' => $name,
            'path' => $path,
            'exists' => is_dir($path),
            'writable' => is_dir($path) && is_writable($path),
            'status' => is_dir($path) && is_writable($path) ? 'ok' : 'error',
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function roots(): array
    {
        return LibraryRoot::query()
            ->withCount(['albums', 'mediaFiles'])
            ->orderBy('name')
            ->get()
            ->map(function (LibraryRoot $root): array {
                $exists = is_dir($root->path);
                $readable = $exists && is_readable($root->path);

                return [
                    'id' => $root->id,
                    'name' => $root->name,
                    'path' => $root->path,
                    'enabled' => $root->enabled,
                    'exists' => $exists,
                    'readable' => $readable,
                    'writable' => $exists && is_writable($root->path),
                    'albums' => $root->albums_count,
                    'tracks' => $root->media_files_count,
                    'lastScannedAt' => $root->last_scanned_at?->toJSON(),
                    'status' => ! $root->enabled || $readable ? 'ok' : 'error',
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function scans(): array
    {
        $activeStatuses = [ScanStatus::Pending->value, ScanStatus::Running->value];

        return [
            'active' => ScanRun::query()->whereIn('status', $activeStatuses)->count(),
            'latestFailed' => ScanRun::query()
                ->where('status', ScanStatus::Failed->value)
                ->orderByDesc('finished_at')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn (ScanRun $scan): array => [
                    'id' => $scan->id,
                    'libraryRootId' => $scan->library_root_id,
                    'createdAt' => $scan->created_at?->toJSON(),
                    'startedAt' => $scan->started_at?->toJSON(),
                    'finishedAt' => $scan->finished_at?->toJSON(),
                    'message' => $scan->summary['error'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array{active: int, latestFailed: list<array<string, mixed>>} */
    private function emptyScans(): array
    {
        return [
            'active' => 0,
            'latestFailed' => [],
        ];
    }

    /** @param list<array<string, mixed>> $storage */
    private function overallStatus(array $database, array $queue, array $storage, array $roots): string
    {
        if ($database['status'] === 'error'
            || $queue['status'] === 'error'
            || collect($storage)->contains(fn (array $entry): bool => $entry['status'] === 'error')
            || collect($roots)->contains(fn (array $root): bool => $root['status'] === 'error')) {
            return 'error';
        }

        if ($queue['status'] === 'warning') {
            return 'warning';
        }

        return 'ok';
    }

    private function firstExceptionLine(string $exception): string
    {
        $line = strtok($exception, "\r\n");

        return mb_substr($line === false ? $exception : $line, 0, 300);
    }
}
