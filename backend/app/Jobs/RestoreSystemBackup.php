<?php

namespace App\Jobs;

use App\System\Backups\SystemBackupManager;
use App\System\Backups\SystemBackupOperationStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RestoreSystemBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(
        public readonly string $operationId,
        public readonly string $archivePath,
    ) {
        $this->onQueue('scans');
    }

    public function handle(
        SystemBackupManager $backups,
        SystemBackupOperationStore $operations,
    ): void {
        $operations->update($this->operationId, [
            'status' => 'running',
            'startedAt' => now()->toJSON(),
        ]);

        try {
            $result = $backups->restore(
                $this->archivePath,
                fn (int $progress, string $phase) => $operations->update(
                    $this->operationId,
                    compact('progress', 'phase'),
                ),
            );
            $operations->update($this->operationId, [
                'status' => 'completed',
                'progress' => 100,
                'phase' => 'completed',
                'message' => null,
                'archiveName' => $result['name'],
                'archivePath' => $result['path'],
                'safetyBackupName' => $result['safetyBackupName'],
                'finishedAt' => now()->toJSON(),
            ]);
        } catch (Throwable $exception) {
            $operations->update($this->operationId, [
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'finishedAt' => now()->toJSON(),
            ]);
            throw $exception;
        }
    }
}
