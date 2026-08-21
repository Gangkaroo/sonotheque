<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\MetadataEditItem;
use App\Models\MetadataEditJob;
use App\Music\Catalog\GenreResolver;
use App\Music\Metadata\AlbumMetadataEditing;
use App\Music\Metadata\MetadataBackupManager;
use App\Music\Metadata\MetadataEditProgress;
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

    public int $tries = 3;

    public int $timeout = 1800;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public function __construct(public readonly int $metadataEditJobId)
    {
    }

    public function handle(
        AlbumMetadataEditing $albumEditing,
        TrackMetadataEditing $trackEditing,
        TrackMetadataWriter $writer,
        LibraryPathGuard $pathGuard,
        ArtistName $artistName,
        MetadataBackupManager $backups,
        MetadataEditProgress $progress,
        GenreResolver $genreResolver,
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
            $this->processItem($edit, $item, $trackEditing, $writer, $pathGuard, $backups);
            $progress->refresh($edit);
        }

        $edit->refresh();
        if ($edit->failed_items === 0) {
            $this->applyCatalogChanges($edit, $artistName, $genreResolver);
            $edit->update(['status' => 'completed', 'finished_at' => now()]);
        } else {
            $edit->update([
                'status' => $edit->succeeded_items > 0 ? 'partial' : 'failed',
                'error' => 'One or more files could not be updated.',
                'finished_at' => now(),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        MetadataEditJob::query()
            ->whereKey($this->metadataEditJobId)
            ->whereNotIn('status', ['completed', 'partial', 'failed'])
            ->update([
                'status' => 'failed',
                'error' => mb_substr(
                    $exception?->getMessage() ?? 'The album metadata edit failed.',
                    0,
                    4000,
                ),
                'finished_at' => now(),
            ]);
    }

    private function processItem(
        MetadataEditJob $edit,
        MetadataEditItem $item,
        TrackMetadataEditing $trackEditing,
        TrackMetadataWriter $writer,
        LibraryPathGuard $pathGuard,
        MetadataBackupManager $backups,
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

            $backups->create($edit, $mediaFile, $path, $item);
            $metadata = $writer->write($path, $item->requested_changes);
            clearstatcache(true, $path);
            $modifiedAt = filemtime($path);
            $fileSize = filesize($path);
            if ($modifiedAt === false || $fileSize === false) {
                throw new RuntimeException('The updated audio-file fingerprint could not be read.');
            }

            $trackAttributes = [
                'year' => $metadata->year,
                'disc_number' => $metadata->discNumber,
                'metadata' => $metadata->rawMetadata,
            ];
            if (array_key_exists('comment', $item->requested_changes)) {
                $trackAttributes['comment'] = $metadata->comment;
            }
            $track->update($trackAttributes);
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

    private function applyCatalogChanges(
        MetadataEditJob $edit,
        ArtistName $artistName,
        GenreResolver $genreResolver,
    ): void {
        $values = $edit->requested_changes;
        $changedFields = array_fill_keys(array_column($edit->preview['changes'], 'field'), true);
        $attributes = [];
        $artistIdsToClean = collect();
        $albumArtist = null;
        if (isset($changedFields['albumTitle'])) {
            $attributes['title'] = $values['albumTitle'];
            $attributes['sort_title'] = $values['albumTitle'];
        }
        if (isset($changedFields['albumArtist'])) {
            $previousArtistId = $edit->album->primary_artist_id;
            $artistIdsToClean->push($previousArtistId);
            $albumArtist = Artist::query()->whereRaw('LOWER(name) = LOWER(?)', [$values['albumArtist']])->first();
            if ($albumArtist === null) {
                $albumArtist = Artist::create([
                    'name' => $values['albumArtist'],
                    'sort_name' => $values['albumArtist'],
                    'browse_initial' => $artistName->browseInitial($values['albumArtist']),
                ]);
            } elseif ($albumArtist->name !== $values['albumArtist']) {
                $albumArtist->update([
                    'name' => $values['albumArtist'],
                    'sort_name' => $values['albumArtist'],
                    'browse_initial' => $artistName->browseInitial($values['albumArtist']),
                ]);
            }
            $attributes['primary_artist_id'] = $albumArtist->id;
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
        if (($edit->preview['trackArtistsWillChange'] ?? false) && $albumArtist !== null) {
            foreach ($edit->album->tracks as $track) {
                $artistIdsToClean->push(...$track->artists()->pluck('artists.id')->all());
                $track->artists()->sync([
                    $albumArtist->id => ['role' => 'primary', 'position' => 0],
                ]);
            }
        }
        if ($artistIdsToClean->isNotEmpty()) {
            Artist::query()
                ->whereIn('id', $artistIdsToClean->unique()->all())
                ->whereDoesntHave('albums')
                ->whereDoesntHave('tracks')
                ->delete();
        }
        if (isset($changedFields['genres'])) {
            $previousGenreIds = Genre::query()
                ->whereHas('tracks', fn ($query) => $query->where('album_id', $edit->album->id))
                ->pluck('id');
            $genreIds = collect($values['genres'])
                ->map(fn (string $name): int => $genreResolver->resolve($name)->id)
                ->all();
            foreach ($edit->album->tracks as $track) {
                $track->genres()->sync($genreIds);
            }
            Genre::query()
                ->whereIn('id', $previousGenreIds)
                ->whereDoesntHave('tracks')
                ->delete();
        }
    }
}
