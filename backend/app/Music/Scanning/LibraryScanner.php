<?php

namespace App\Music\Scanning;

use App\Enums\MediaFileStatus;
use App\Enums\ScanStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\MediaFile;
use App\Models\ScanRun;
use App\Models\Track;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class LibraryScanner
{
    public function __construct(
        private readonly AudioFileDiscoverer $discoverer,
        private readonly AudioMetadataReader $metadataReader,
        private readonly ArtistName $artistName,
    ) {}

    public function scan(ScanRun $scanRun): void
    {
        $scanRun->loadMissing('libraryRoot');
        $startedAt = CarbonImmutable::now();
        $scanRun->update([
            'status' => ScanStatus::Running,
            'started_at' => $startedAt,
            'finished_at' => null,
            'summary' => ['phase' => 'discovering'],
        ]);

        try {
            foreach ($this->discoverer->discover($scanRun->libraryRoot) as $file) {
                if ($scanRun->fresh()->cancel_requested_at !== null) {
                    $scanRun->update([
                        'status' => ScanStatus::Cancelled,
                        'finished_at' => now(),
                        'summary' => ['phase' => 'cancelled'],
                    ]);

                    return;
                }

                $scanRun->increment('files_discovered');

                try {
                    $result = $this->processFile($scanRun, $file, $startedAt);
                    $scanRun->increment($result['created'] ? 'files_added' : 'files_updated', $result['changed'] ? 1 : 0);

                    if ($result['warning_count'] > 0) {
                        $scanRun->increment('warning_count', $result['warning_count']);
                    }
                } catch (Throwable $exception) {
                    $this->recordFileError($scanRun, $file, $startedAt, $exception);
                    $scanRun->increment('error_count');
                }

                $scanRun->increment('files_processed');
            }

            $missing = $scanRun->libraryRoot->mediaFiles()
                ->where('last_seen_at', '<', $startedAt)
                ->where('status', '!=', MediaFileStatus::Missing->value)
                ->update(['status' => MediaFileStatus::Missing->value]);

            $scanRun->libraryRoot->update(['last_scanned_at' => now()]);
            $scanRun->update([
                'status' => ScanStatus::Completed,
                'files_missing' => $missing,
                'finished_at' => now(),
                'summary' => ['phase' => 'completed'],
            ]);
        } catch (Throwable $exception) {
            $scanRun->update([
                'status' => ScanStatus::Failed,
                'finished_at' => now(),
                'summary' => [
                    'phase' => 'failed',
                    'error' => $exception->getMessage(),
                ],
            ]);

            throw $exception;
        }
    }

    /** @return array{created: bool, changed: bool, warning_count: int} */
    private function processFile(ScanRun $scanRun, DiscoveredAudioFile $file, CarbonImmutable $seenAt): array
    {
        $pathHash = $this->pathHash($file->relativePath);
        $existing = $scanRun->libraryRoot->mediaFiles()->where('relative_path_hash', $pathHash)->first();

        if ($existing !== null
            && $existing->status === MediaFileStatus::Available
            && $existing->file_size === $file->fileSize
            && $existing->modified_at->getTimestamp() === $file->modifiedAt) {
            $existing->update(['last_seen_at' => $seenAt]);

            return ['created' => false, 'changed' => false, 'warning_count' => 0];
        }

        $metadata = $this->metadataReader->read($file->absolutePath);

        return DB::transaction(function () use ($scanRun, $file, $seenAt, $pathHash, $existing, $metadata): array {
            $artistName = $metadata->albumArtist ?? $metadata->artists[0] ?? $file->artistFolder;
            $artist = $this->findOrCreateArtist($artistName);
            $album = Album::firstOrNew(
                [
                    'library_root_id' => $scanRun->library_root_id,
                    'relative_path_hash' => $this->pathHash($file->albumRelativePath),
                ],
            );
            $albumTitle = $this->limited($metadata->album ?? $file->albumFolder, 512);
            $album->fill([
                'primary_artist_id' => $artist->id,
                'title' => $albumTitle,
                'sort_title' => $albumTitle,
                'relative_path' => $file->albumRelativePath,
                'original_release_year' => $metadata->originalReleaseYear ?? $album->original_release_year,
                'disc_total' => $metadata->discTotal ?? $album->disc_total,
                'metadata' => [
                    'folder_artist' => $file->artistFolder,
                    'folder_album' => $file->albumFolder,
                ],
            ]);
            $album->save();

            $mediaFile = MediaFile::updateOrCreate(
                [
                    'library_root_id' => $scanRun->library_root_id,
                    'relative_path_hash' => $pathHash,
                ],
                [
                    'album_id' => $album->id,
                    'relative_path' => $file->relativePath,
                    'file_size' => $file->fileSize,
                    'modified_at' => CarbonImmutable::createFromTimestamp($file->modifiedAt),
                    'mime_type' => $metadata->mimeType,
                    'container' => $metadata->container,
                    'codec' => $metadata->codec,
                    'bitrate' => $metadata->bitrate,
                    'sample_rate' => $metadata->sampleRate,
                    'channels' => $metadata->channels,
                    'status' => MediaFileStatus::Available,
                    'last_seen_at' => $seenAt,
                    'scan_error' => null,
                    'raw_metadata' => $metadata->rawMetadata,
                ],
            );

            $track = Track::updateOrCreate(
                ['media_file_id' => $mediaFile->id],
                [
                    'album_id' => $album->id,
                    'title' => $this->limited($metadata->title ?? pathinfo($file->absolutePath, PATHINFO_FILENAME), 512),
                    'sort_title' => $this->limited($metadata->title ?? pathinfo($file->absolutePath, PATHINFO_FILENAME), 512),
                    'duration_ms' => $metadata->durationMs,
                    'track_number' => $metadata->trackNumber,
                    'disc_number' => $metadata->discNumber,
                    'year' => $metadata->year,
                    'metadata' => null,
                ],
            );

            $trackArtists = $metadata->artists === [] ? [$artistName] : $metadata->artists;
            $artistPivots = [];

            foreach (array_values(array_unique($trackArtists)) as $position => $trackArtistName) {
                $trackArtist = $this->findOrCreateArtist($trackArtistName);
                $artistPivots[$trackArtist->id] = ['role' => 'primary', 'position' => $position];
            }

            $track->artists()->sync($artistPivots);
            $genreIds = collect($metadata->genres)
                ->map(fn (string $genre): string => trim($genre))
                ->filter()
                ->map(fn (string $genre): int => $this->findOrCreateGenre($genre)->id)
                ->unique()
                ->values()
                ->all();

            $track->genres()->sync($genreIds);

            return [
                'created' => $existing === null,
                'changed' => true,
                'warning_count' => count($metadata->warnings),
            ];
        });
    }

    private function recordFileError(ScanRun $scanRun, DiscoveredAudioFile $file, CarbonImmutable $seenAt, Throwable $exception): void
    {
        DB::transaction(function () use ($scanRun, $file, $seenAt, $exception): void {
            $artist = $this->findOrCreateArtist($file->artistFolder);
            $album = Album::firstOrCreate(
                [
                    'library_root_id' => $scanRun->library_root_id,
                    'relative_path_hash' => $this->pathHash($file->albumRelativePath),
                ],
                [
                    'primary_artist_id' => $artist->id,
                    'title' => $this->limited($file->albumFolder, 512),
                    'sort_title' => $this->limited($file->albumFolder, 512),
                    'relative_path' => $file->albumRelativePath,
                    'metadata' => [
                        'folder_artist' => $file->artistFolder,
                        'folder_album' => $file->albumFolder,
                    ],
                ],
            );

            MediaFile::updateOrCreate(
                [
                    'library_root_id' => $scanRun->library_root_id,
                    'relative_path_hash' => $this->pathHash($file->relativePath),
                ],
                [
                    'album_id' => $album->id,
                    'relative_path' => $file->relativePath,
                    'file_size' => $file->fileSize,
                    'modified_at' => CarbonImmutable::createFromTimestamp($file->modifiedAt),
                    'status' => MediaFileStatus::Error,
                    'last_seen_at' => $seenAt,
                    'scan_error' => mb_substr($exception->getMessage(), 0, 65535),
                ],
            );
        });
    }

    private function findOrCreateArtist(string $name): Artist
    {
        $name = $this->limited(trim($name) ?: 'Unknown Artist', 512);
        $artist = Artist::query()->whereRaw('LOWER(name) = LOWER(?)', [$name])->first();

        return $artist ?? Artist::create([
            'name' => $name,
            'sort_name' => $name,
            'browse_initial' => $this->artistName->browseInitial($name),
        ]);
    }

    private function findOrCreateGenre(string $name): Genre
    {
        $name = $this->limited(trim($name), 255);
        $genre = Genre::query()->whereRaw('LOWER(name) = LOWER(?)', [$name])->first();

        return $genre ?? Genre::create(['name' => $name]);
    }

    private function pathHash(string $relativePath): string
    {
        return hash('sha256', mb_strtolower(str_replace('\\', '/', $relativePath)));
    }

    private function limited(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
