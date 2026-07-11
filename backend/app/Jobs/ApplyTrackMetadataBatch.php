<?php

namespace App\Jobs;

use App\Models\MetadataEditItem;
use App\Models\MetadataEditJob;
use App\Models\Track;
use App\Music\Metadata\MetadataEditProgress;
use App\Music\Metadata\TrackBatchMetadataEditing;
use App\Music\Metadata\TrackMetadataEditExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyTrackMetadataBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $metadataEditJobId)
    {
    }

    public function handle(
        TrackBatchMetadataEditing $batchEditing,
        TrackMetadataEditExecutor $executor,
        MetadataEditProgress $progress,
    ): void {
        $edit = MetadataEditJob::with(['album', 'items.track.mediaFile.libraryRoot'])
            ->findOrFail($this->metadataEditJobId);
        if (in_array($edit->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }

        $trackIds = $edit->preview['trackIds'] ?? [];
        $tracks = Track::query()->whereIn('id', $trackIds)->get();
        if ($edit->processed_items === 0
            && ($edit->album === null
                || count($trackIds) !== $tracks->count()
                || ! hash_equals($edit->fingerprint, $batchEditing->fingerprint($edit->album, $tracks)))) {
            $edit->update([
                'status' => 'failed',
                'error' => 'The selected tracks changed after the edit was queued.',
                'finished_at' => now(),
            ]);

            return;
        }

        $edit->update(['status' => 'running', 'started_at' => $edit->started_at ?? now(), 'error' => null]);
        foreach ($edit->items->where('status', 'pending') as $item) {
            $this->processItem($edit, $item, $executor);
            $progress->refresh($edit);
        }

        $edit->refresh();
        $edit->update([
            'status' => match (true) {
                $edit->failed_items === 0 => 'completed',
                $edit->succeeded_items > 0 => 'partial',
                default => 'failed',
            },
            'error' => $edit->failed_items > 0 ? 'One or more files could not be updated.' : null,
            'finished_at' => now(),
        ]);
    }

    private function processItem(
        MetadataEditJob $edit,
        MetadataEditItem $item,
        TrackMetadataEditExecutor $executor,
    ): void {
        $item->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            $executor->applyBatchItem($edit, $item);
            $item->update(['status' => 'completed', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $item->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $edit = MetadataEditJob::find($this->metadataEditJobId);
        if ($edit === null || in_array($edit->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }

        $edit->update([
            'status' => 'failed',
            'error' => mb_substr($exception?->getMessage() ?? 'The metadata batch could not be completed.', 0, 4000),
            'finished_at' => now(),
        ]);
    }
}
