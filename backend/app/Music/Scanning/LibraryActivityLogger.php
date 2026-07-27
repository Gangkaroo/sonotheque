<?php

namespace App\Music\Scanning;

use App\Models\LibraryActivityLog;
use App\Models\LibraryRoot;
use App\Models\ScanRun;

class LibraryActivityLogger
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function record(
        string $source,
        string $severity,
        string $code,
        string $message,
        ?LibraryRoot $libraryRoot = null,
        ?ScanRun $scanRun = null,
        ?string $path = null,
        int $count = 1,
        ?array $context = null,
    ): LibraryActivityLog {
        return LibraryActivityLog::query()->create([
            'library_root_id' => $libraryRoot?->id ?? $scanRun?->library_root_id,
            'scan_run_id' => $scanRun?->id,
            'source' => mb_substr($source, 0, 32),
            'severity' => mb_substr($severity, 0, 16),
            'code' => mb_substr($code, 0, 64),
            'message' => $this->clean($message),
            'path' => $path === null ? null : $this->clean($path),
            'occurrence_count' => max(1, $count),
            'context' => $context,
        ]);
    }

    /**
     * @param  list<array{
     *     scan_run_id: int,
     *     code: string,
     *     severity: string,
     *     message: string,
     *     path: ?string,
     *     occurrence_count: int,
     *     created_at: mixed,
     *     updated_at: mixed
     * }>  $issues
     */
    public function scanIssues(int $libraryRootId, array $issues): void
    {
        if ($issues === []) {
            return;
        }

        LibraryActivityLog::query()->insert(array_map(
            fn (array $issue): array => [
                'library_root_id' => $libraryRootId,
                'scan_run_id' => $issue['scan_run_id'],
                'source' => 'scan',
                'severity' => $issue['severity'],
                'code' => $issue['code'],
                'message' => $issue['message'],
                'path' => $issue['path'],
                'occurrence_count' => $issue['occurrence_count'],
                'context' => null,
                'created_at' => $issue['created_at'],
                'updated_at' => $issue['updated_at'],
            ],
            $issues,
        ));
    }

    private function clean(string $value): string
    {
        return mb_convert_encoding(str_replace("\0", '', $value), 'UTF-8', 'UTF-8');
    }
}
