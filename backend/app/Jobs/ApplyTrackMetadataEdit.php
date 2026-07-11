<?php

namespace App\Jobs;

use App\Models\MetadataEditJob;
use App\Music\Metadata\TrackMetadataEditExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyTrackMetadataEdit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $metadataEditJobId)
    {
    }

    public function handle(
        TrackMetadataEditExecutor $executor,
    ): void {
        $edit = MetadataEditJob::with(['track.mediaFile.libraryRoot'])->findOrFail($this->metadataEditJobId);
        if ($edit->status === 'completed') {
            return;
        }

        $edit->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            $executor->applyTrackEdit($edit);
            $edit->update(['status' => 'completed', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $edit->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $edit = MetadataEditJob::find($this->metadataEditJobId);
        if ($edit === null || $edit->status === 'completed') {
            return;
        }

        $edit->update([
            'status' => 'failed',
            'error' => mb_substr($exception?->getMessage() ?? 'The metadata edit could not be completed.', 0, 4000),
            'finished_at' => now(),
        ]);
    }
}
