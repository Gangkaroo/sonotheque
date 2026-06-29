<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\MetadataEditItem;
use App\Models\MetadataEditJob;
use App\Music\Metadata\AlbumMetadataEditing;
use App\Music\Metadata\TrackMetadataEditing;
use App\Music\Metadata\TrackMetadataWriter;
use App\Music\Scanning\ArtistName;
use App\Music\Scanning\LibraryPathGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ApplyAlbumMetadataEdit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $metadataEditJobId) {}

    public function handle(
        AlbumMetadataEditing $albumEditing,
        TrackMetadataEditing $trackEditing,
        TrackMetadataWriter $writer,
        LibraryPathGuard $pathGuard,
        ArtistName $artistName,
    ): void {
        $edit = MetadataEditJob::with(['album.tracks', 'items.track.mediaFile.libraryRoot'])
            ->findOrFail($this->metadataEditJobId);
        if (in_array($edit->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }

        if ($edit->processed_items === 0
            && ! hash_equals($edit->fingerprint, $albumEditing->fingerprint($edit->album))) {
            $edit->update([
                'status' => 'failed',
                'error' => 'The album changed after the edit was queued.',
                'finished_at' => now(),
            ]);

            return;
        }

        $edit->update(['status' => 'running', 'started_at' => $edit->started_at ?? now(), 'error' => null]);
        foreach ($edit->items->where('status', 'pending') as $item) {
            $this->processItem($item, $trackEditing, $writer, $pathGuard);
            $this->refreshProgress($edit);
        }

        $edit->refresh();
        if ($edit->failed_items === 0) {
            $this->applyCatalogChanges($edit, $artistName);
            $edit->update(['status' => 'completed', 'finished_at' => now()]);
        } else {
            $edit->update([
                'status' => $edit->succeeded_items > 0 ? 'partial' : 'failed',
                'error' => 'One or more files could not be updated.',
                'finished_at' => now(),
            ]);
        }
    }

    private function processItem(
        MetadataEditItem $item,
        TrackMetadataEditing $trackEditing,
        TrackMetadataWriter $writer,
        LibraryPathGuard $pathGuard,
    ): void {
        $item->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            $track = $item->track;
            if (! hash_equals($item->fingerprint, $trackEditing->fingerprint($track))) {
                throw new RuntimeException('The track changed after the album edit was queued.');
            }
            $mediaFile = $track->mediaFile;
            $path = $pathGuard->resolveExistingFileWithin(
                $mediaFile->libraryRoot->path,
                $mediaFile->relative_path,
            );
            if ($path === null) {
                throw new RuntimeException('The audio file no longer exists.');
            }

            $metadata = $writer->write($path, $item->requested_changes);
            clearstatcache(true, $path);
            $modifiedAt = filemtime($path);
            $fileSize = filesize($path);
            if ($modifiedAt === false || $fileSize === false) {
                throw new RuntimeException('The updated audio-file fingerprint could not be read.');
            }

            $track->update([
                'year' => $metadata->year,
                'disc_number' => $metadata->discNumber,
                'metadata' => $metadata->rawMetadata,
            ]);
            $mediaFile->update([
                'file_size' => $fileSize,
                'modified_at' => CarbonImmutable::createFromTimestampUTC($modifiedAt),
                'raw_metadata' => $metadata->rawMetadata,
            ]);
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

    private function applyCatalogChanges(MetadataEditJob $edit, ArtistName $artistName): void
    {
        $values = $edit->requested_changes;
        $changedFields = array_fill_keys(array_column($edit->preview['changes'], 'field'), true);
        $attributes = [];
        if (isset($changedFields['albumTitle'])) {
            $attributes['title'] = $values['albumTitle'];
            $attributes['sort_title'] = $values['albumTitle'];
        }
        if (isset($changedFields['albumArtist'])) {
            $artist = Artist::query()->whereRaw('LOWER(name) = LOWER(?)', [$values['albumArtist']])->first()
                ?? Artist::create([
                    'name' => $values['albumArtist'],
                    'sort_name' => $values['albumArtist'],
                    'browse_initial' => $artistName->browseInitial($values['albumArtist']),
                ]);
            $attributes['primary_artist_id'] = $artist->id;
        }
        if (isset($changedFields['releaseYear'])) {
            $attributes['original_release_year'] = $values['releaseYear'];
        }
        if (isset($changedFields['totalDiscs'])) {
            $attributes['disc_total'] = $values['totalDiscs'];
        }
        if ($attributes !== []) {
            $edit->album->update($attributes);
        }
        if (isset($changedFields['genres'])) {
            $genreIds = collect($values['genres'])->map(function (string $name): int {
                return Genre::query()->whereRaw('LOWER(name) = LOWER(?)', [$name])->first()?->id
                    ?? Genre::create(['name' => $name])->id;
            })->all();
            foreach ($edit->album->tracks as $track) {
                $track->genres()->sync($genreIds);
            }
        }
    }
}
