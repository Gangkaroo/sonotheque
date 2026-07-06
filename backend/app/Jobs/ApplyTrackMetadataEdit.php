<?php

namespace App\Jobs;

use App\Models\MetadataEditJob;
use App\Music\Metadata\MetadataBackupManager;
use App\Music\Metadata\TrackMetadataCatalogUpdater;
use App\Music\Metadata\TrackMetadataEditing;
use App\Music\Metadata\TrackMetadataWriter;
use App\Music\Scanning\LibraryPathGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
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
        TrackMetadataEditing $editing,
        TrackMetadataWriter $writer,
        TrackMetadataCatalogUpdater $catalogUpdater,
        LibraryPathGuard $pathGuard,
        MetadataBackupManager $backups,
    ): void {
        $edit = MetadataEditJob::with(['track.mediaFile.libraryRoot'])->findOrFail($this->metadataEditJobId);
        if ($edit->status === 'completed') {
            return;
        }

        $edit->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            $track = $edit->track;
            if (! hash_equals($edit->fingerprint, $editing->fingerprint($track))) {
                throw new RuntimeException('The track changed after the edit was queued.');
            }

            $mediaFile = $track->mediaFile;
            $path = $pathGuard->resolveExistingFileWithin(
                $mediaFile->libraryRoot->path,
                $mediaFile->relative_path,
            );
            if ($path === null) {
                throw new RuntimeException('The audio file no longer exists.');
            }

            $backups->create($edit, $mediaFile, $path);
            $metadata = $writer->write($path, $edit->requested_changes);
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
