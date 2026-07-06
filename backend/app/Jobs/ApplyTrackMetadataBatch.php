<?php

namespace App\Jobs;

use App\Models\MetadataEditItem;
use App\Models\MetadataEditJob;
use App\Models\Track;
use App\Music\Metadata\MetadataBackupManager;
use App\Music\Metadata\TrackBatchMetadataEditing;
use App\Music\Metadata\TrackMetadataCatalogUpdater;
use App\Music\Metadata\TrackMetadataEditing;
use App\Music\Metadata\TrackMetadataWriter;
use App\Music\Scanning\LibraryPathGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
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
        TrackMetadataEditing $trackEditing,
        TrackMetadataWriter $writer,
        TrackMetadataCatalogUpdater $catalogUpdater,
        LibraryPathGuard $pathGuard,
        MetadataBackupManager $backups,
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
            $this->processItem($edit, $item, $trackEditing, $writer, $catalogUpdater, $pathGuard, $backups);
            $this->refreshProgress($edit);
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
        TrackMetadataEditing $trackEditing,
        TrackMetadataWriter $writer,
        TrackMetadataCatalogUpdater $catalogUpdater,
        LibraryPathGuard $pathGuard,
        MetadataBackupManager $backups,
    ): void {
        $item->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            $track = $item->track;
            if (! hash_equals($item->fingerprint, $trackEditing->fingerprint($track))) {
                throw new RuntimeException('The track changed after the batch edit was queued.');
            }
            $mediaFile = $track->mediaFile;
            $path = $pathGuard->resolveExistingFileWithin(
                $mediaFile->libraryRoot->path,
                $mediaFile->relative_path,
            );
            if ($path === null) {
                throw new RuntimeException('The audio file no longer exists.');
            }

            $backups->create($edit, $mediaFile, $path, $item);
            $metadata = $writer->write($path, $item->requested_changes);
            clearstatcache(true, $path);
            $modifiedAt = filemtime($path);
            $fileSize = filesize($path);
            if ($modifiedAt === false || $fileSize === false) {
                throw new RuntimeException('The updated audio-file fingerprint could not be read.');
            }

            $catalogUpdater->apply(
                $track,
                $metadata,
                $fileSize,
                CarbonImmutable::createFromTimestampUTC($modifiedAt),
            );
            $item->update(['status' => 'completed', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $item->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
            ]);
        }
    }

    private function refreshProgress(MetadataEditJob $edit): void
    {
        $counts = $edit->items()
            ->selectRaw("count(*) filter (where status in ('completed', 'failed')) as processed")
            ->selectRaw("count(*) filter (where status = 'completed') as succeeded")
            ->selectRaw("count(*) filter (where status = 'failed') as failed")
            ->first();
        $edit->update([
            'processed_items' => (int) $counts->processed,
            'succeeded_items' => (int) $counts->succeeded,
            'failed_items' => (int) $counts->failed,
        ]);
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
