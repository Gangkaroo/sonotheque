<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\MetadataEditJob;
use App\Music\Metadata\MetadataBackupManager;
use App\Music\Metadata\TrackMetadataEditing;
use App\Music\Metadata\TrackMetadataWriter;
use App\Music\Scanning\ArtistName;
use App\Music\Scanning\LibraryPathGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ApplyTrackMetadataEdit implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public readonly int $metadataEditJobId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        TrackMetadataEditing $editing,
        TrackMetadataWriter $writer,
        LibraryPathGuard $pathGuard,
        ArtistName $artistName,
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

            $track->update([
                'title' => $metadata->title,
                'sort_title' => $metadata->title,
                'track_number' => $metadata->trackNumber,
                'disc_number' => $metadata->discNumber,
                'year' => $metadata->year,
                'comment' => $metadata->comment,
                'composers' => $metadata->composers ?: null,
                'performers' => $metadata->performers ?: null,
                'metadata' => $metadata->rawMetadata,
            ]);
            $artistPivots = [];
            foreach ($metadata->artists as $position => $name) {
                $artist = Artist::query()->whereRaw('LOWER(name) = LOWER(?)', [$name])->first()
                    ?? Artist::create([
                        'name' => $name,
                        'sort_name' => $name,
                        'browse_initial' => $artistName->browseInitial($name),
                    ]);
                $artistPivots[$artist->id] = ['role' => 'primary', 'position' => $position];
            }
            $track->artists()->sync($artistPivots);
            $mediaFile->update([
                'file_size' => $fileSize,
                'modified_at' => CarbonImmutable::createFromTimestampUTC($modifiedAt),
                'raw_metadata' => $metadata->rawMetadata,
            ]);
            $edit->update(['status' => 'completed', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $willRetry = $this->attempts() < $this->tries;
            $edit->update([
                'status' => $willRetry ? 'pending' : 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => $willRetry ? null : now(),
            ]);

            throw $exception;
        }
    }
}
