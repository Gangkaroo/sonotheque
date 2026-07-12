<?php

namespace App\Support;

use App\Models\Album;
use App\Models\AlbumPersonalMetadata;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Track;
use Illuminate\Pagination\LengthAwarePaginator;

class CatalogPayloads
{
    public function paginated(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'items' => collect($paginator->items())->map($map)->values(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    /** @return array<string, mixed> */
    public function albumSummary(Album $album): array
    {
        $trackCount = $album->tracks_count ?? $album->tracks()->count();

        return [
            'id' => $album->id,
            'title' => $album->title,
            'originalReleaseYear' => $album->original_release_year,
            'primaryArtist' => $album->primaryArtist ? [
                'id' => $album->primaryArtist->id,
                'name' => $album->primaryArtist->name,
            ] : null,
            'trackCount' => $trackCount,
            'artworkThumbnailUrl' => $album->artwork_id ? "/api/artwork/{$album->artwork_id}/thumbnail" : null,
            'personalMetadata' => $this->albumPersonalMetadata($album->relationLoaded('personalMetadata') ? $album->personalMetadata : null),
        ];
    }

    /** @return array<string, mixed> */
    public function albumDetail(Album $album): array
    {
        $album->load([
            'primaryArtist:id,name',
            'artwork:id,width,height',
            'personalMetadata',
            'tracks' => fn ($query) => $query
                ->select(['id', 'title', 'sort_title', 'duration_ms', 'track_number', 'disc_number', 'comment', 'album_id'])
                ->with(['album:id,title,original_release_year,artwork_id', 'album.personalMetadata', 'artists:id,name', 'genres:id,name', 'playStatistic:track_id,play_count,first_played_at,last_played_at'])
                ->orderBy('disc_number')
                ->orderBy('track_number')
                ->orderBy('id'),
        ])->loadCount('tracks');
        $genres = $album->tracks
            ->flatMap(fn (Track $track) => $track->genres)
            ->unique('id')
            ->sortBy('name')
            ->values();

        return [
            ...$this->albumSummary($album),
            'discTotal' => $album->disc_total,
            'artworkUrl' => $album->artwork_id ? "/api/albums/{$album->id}/artwork/original" : null,
            'artworkWidth' => $album->artwork?->width,
            'artworkHeight' => $album->artwork?->height,
            'genres' => $genres->map(fn (Genre $genre) => [
                'id' => $genre->id,
                'name' => $genre->name,
            ])->values(),
            'tracks' => $album->tracks->map(fn (Track $track) => [
                ...$this->trackSummary($track),
                'comment' => $track->comment,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function trackSummary(Track $track): array
    {
        return [
            'id' => $track->id,
            'title' => $track->title,
            'streamUrl' => "/api/tracks/{$track->id}/stream",
            'durationMs' => $track->duration_ms,
            'trackNumber' => $track->track_number,
            'discNumber' => $track->disc_number,
            'album' => $track->album ? [
                'id' => $track->album->id,
                'title' => $track->album->title,
                'originalReleaseYear' => $track->album->original_release_year,
                'artworkThumbnailUrl' => $track->album->artwork_id ? "/api/artwork/{$track->album->artwork_id}/thumbnail" : null,
                'personalMetadata' => $this->albumPersonalMetadata($track->album->relationLoaded('personalMetadata') ? $track->album->personalMetadata : null),
            ] : null,
            'artists' => $track->artists->map(fn (Artist $artist) => [
                'id' => $artist->id,
                'name' => $artist->name,
            ])->values(),
            'playStatistics' => $this->playStatisticsPayload($track),
        ];
    }

    /** @return array<string, mixed> */
    public function trackDetail(Track $track): array
    {
        $track->load([
            'album:id,title,original_release_year,artwork_id',
            'album.personalMetadata',
            'artists:id,name',
            'genres:id,name',
            'mediaFile:id,relative_path,file_size,modified_at,mime_type,container,codec,bitrate,sample_rate,channels,status,scan_error,raw_metadata',
            'playStatistic:track_id,play_count,first_played_at,last_played_at',
        ]);

        $mediaFile = $track->mediaFile;
        $audioMetadata = is_array($mediaFile?->raw_metadata['audio'] ?? null)
            ? $mediaFile->raw_metadata['audio']
            : [];
        $audioStream = is_array($audioMetadata['streams'][0] ?? null)
            ? $audioMetadata['streams'][0]
            : [];

        return [
            ...$this->trackSummary($track),
            'year' => $track->year,
            'comment' => $track->comment,
            'composers' => $track->composers ?? [],
            'performers' => $track->performers ?? [],
            'genres' => $track->genres->map(fn (Genre $genre) => [
                'id' => $genre->id,
                'name' => $genre->name,
            ])->values(),
            'mediaFile' => $mediaFile ? [
                'id' => $mediaFile->id,
                'relativePath' => $mediaFile->relative_path,
                'fileSize' => $mediaFile->file_size,
                'modifiedAt' => $mediaFile->modified_at?->toIso8601String(),
                'mimeType' => $mediaFile->mime_type,
                'container' => $mediaFile->container,
                'codec' => $mediaFile->codec,
                'encoder' => $this->metadataText($audioMetadata['encoder'] ?? $audioStream['encoder'] ?? null),
                'encoderSettings' => $this->metadataText($audioMetadata['encoder_options'] ?? $audioStream['encoder_options'] ?? null),
                'bitrate' => $mediaFile->bitrate,
                'sampleRate' => $mediaFile->sample_rate,
                'channels' => $mediaFile->channels,
                'status' => $mediaFile->status?->value,
                'scanError' => $mediaFile->scan_error,
            ] : null,
            'playStatistics' => $this->playStatisticsPayload($track),
        ];
    }

    /** @return array{purchaseSource: ?string, purchaseDate: ?string, hasPhysicalCopy: bool, physicalFormat: ?string, notes: ?string} */
    public function albumPersonalMetadata(?AlbumPersonalMetadata $metadata): array
    {
        return [
            'purchaseSource' => $metadata?->purchase_source,
            'purchaseDate' => $metadata?->purchase_date?->toDateString(),
            'hasPhysicalCopy' => $metadata?->has_physical_copy ?? false,
            'physicalFormat' => $metadata?->physical_format,
            'notes' => $metadata?->notes,
        ];
    }

    /** @return array{playCount: int, firstPlayedAt: ?string, lastPlayedAt: ?string} */
    private function playStatisticsPayload(Track $track): array
    {
        $statistics = $track->relationLoaded('playStatistic') ? $track->playStatistic : null;

        return [
            'playCount' => $statistics?->play_count ?? 0,
            'firstPlayedAt' => $statistics?->first_played_at?->toJSON(),
            'lastPlayedAt' => $statistics?->last_played_at?->toJSON(),
        ];
    }

    private function metadataText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
