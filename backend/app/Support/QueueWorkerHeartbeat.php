<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

class QueueWorkerHeartbeat
{
    /** @var array<string, float> */
    private array $lastWrittenAt = [];

    /** @return list<string> */
    public function expectedQueues(): array
    {
        return ['default', 'scans', 'analysis'];
    }

    public function record(string $queue): void
    {
        $queue = trim($queue);
        if ($queue === '' || strlen($queue) > 64) {
            return;
        }

        $now = microtime(true);
        $interval = max(
            1,
            (int) config('sonotheque.system_health.worker_heartbeat_interval_seconds', 10),
        );
        if ($now - ($this->lastWrittenAt[$queue] ?? 0) < $interval) {
            return;
        }

        try {
            $timestamp = now();
            DB::table('queue_worker_heartbeats')->upsert(
                [[
                    'queue' => $queue,
                    'host' => gethostname() ?: null,
                    'process_id' => getmypid() ?: null,
                    'last_seen_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]],
                ['queue'],
                ['host', 'process_id', 'last_seen_at', 'updated_at'],
            );
            $this->lastWrittenAt[$queue] = $now;
        } catch (Throwable) {
            // A health check must never stop a queue worker during database recovery.
        }
    }
}
