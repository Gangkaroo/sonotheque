<?php

namespace App\ApiPlatform\State;

use ApiPlatform\Laravel\ApiResource\ValidationError;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Enums\ScanStatus;
use App\Models\ScanRun;
use App\Models\ScanRunIssue;

/** @implements ProcessorInterface<ScanRun, ScanRun> */
class CancelScanRunProcessor implements ProcessorInterface
{
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ScanRun
    {
        $scanRun = ScanRun::findOrFail($data->id);

        if (! in_array($scanRun->status, [ScanStatus::Pending, ScanStatus::Running], true)) {
            throw new ValidationError(
                'Only pending or running scans can be cancelled.',
                hash('xxh3', 'status'),
                violations: [['propertyPath' => 'status', 'message' => 'This scan is no longer active.']],
            );
        }

        if ($scanRun->status === ScanStatus::Pending) {
            $scanRun->update([
                'status' => ScanStatus::Cancelled,
                'cancel_requested_at' => now(),
                'finished_at' => now(),
                'summary' => ['phase' => 'cancelled'],
            ]);
        } elseif ($scanRun->updated_at->lt(now()->subMinutes((int) config('sonotheque.scan_stale_after_minutes', 15)))) {
            $issues = $scanRun->summary['issues'] ?? [];
            $issue = [
                'code' => 'worker_stopped',
                'severity' => 'error',
                'message' => 'The scan worker stopped before the scan could finish.',
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
            $scanRun->update([
                'status' => ScanStatus::Failed,
                'error_count' => $scanRun->error_count + 1,
                'finished_at' => now(),
                'summary' => [
                    'phase' => 'failed',
                    'error' => 'The scan worker stopped before the scan could finish.',
                    'issues' => array_slice($issues, -50),
                ],
            ]);
        } else {
            $scanRun->update([
                'cancel_requested_at' => now(),
                'summary' => ['phase' => 'cancelling'],
            ]);
        }

        return $scanRun->refresh();
    }
}
