<?php

namespace App\Support;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\MediaFile;
use App\Models\OwnedAlbumCopy;
use App\Models\Track;
use App\Music\Metadata\AdditionalMetadataTags;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CatalogPayloads
{
    public function __construct(private readonly AdditionalMetadataTags $additionalTags)
    {
    }

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
            'personalMetadata' => $this->albumPersonalMetadata($album),
        ];
    }

    /** @return array<string, mixed> */
    public function albumDetail(Album $album): array
    {
        $album->load([
            'primaryArtist:id,name',
            'libraryRoot:id,name',
            'artwork:id,width,height',
            'personalMetadata',
            'ownedCopies',
            'tracks' => fn ($query) => $query
                ->whereHas(
                    'mediaFile',
                    fn ($query) => $query->where('status', MediaFileStatus::Available->value),
                )
                ->select(['id', 'title', 'sort_title', 'duration_ms', 'track_number', 'disc_number', 'year', 'comment', 'album_id', 'media_file_id'])
                ->with([
                    'album:id,title,original_release_year,artwork_id',
                    'album.personalMetadata',
                    'album.ownedCopies',
                    'artists:id,name',
                    'genres:id,name',
                    'playStatistic:track_id,play_count,first_played_at,last_played_at',
                    'mediaFile' => fn ($query) => $query
                        ->select(['id', 'relative_path', 'container', 'codec', 'bitrate', 'status'])
                        ->selectRaw("raw_metadata #>> '{audio,bitrate_mode}' AS bitrate_mode")
                        ->selectRaw("COALESCE(raw_metadata #>> '{audio,encoder_options}', raw_metadata #>> '{audio,streams,0,encoder_options}') AS encoder_settings"),
                ])
                ->orderBy('disc_number')
                ->orderBy('track_number')
                ->orderBy('id'),
        ])->loadCount([
            'tracks' => fn ($query) => $query->whereHas(
                'mediaFile',
                fn ($query) => $query->where('status', MediaFileStatus::Available->value),
            ),
        ]);
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
            'libraryRoot' => $album->libraryRoot ? [
                'id' => $album->libraryRoot->id,
                'name' => $album->libraryRoot->name,
            ] : null,
            'genres' => $genres->map(fn (Genre $genre) => [
                'id' => $genre->id,
                'name' => $genre->name,
            ])->values(),
            'technical' => $this->albumTechnicalSummary($album),
            'tracks' => $album->tracks->map(fn (Track $track) => [
                ...$this->trackSummary($track),
                'comment' => $track->comment,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function trackSummary(Track $track): array
    {
        $available = ! $track->relationLoaded('mediaFile')
            || $track->mediaFile?->status === MediaFileStatus::Available;

        return [
            'id' => $track->id,
            'title' => $track->title,
            'available' => $available,
            'streamUrl' => "/api/tracks/{$track->id}/stream",
            'durationMs' => $track->duration_ms,
            'trackNumber' => $track->track_number,
            'discNumber' => $track->disc_number,
            'year' => $track->year,
            'album' => $track->album ? [
                'id' => $track->album->id,
                'title' => $track->album->title,
                'originalReleaseYear' => $track->album->original_release_year,
                'artworkThumbnailUrl' => $track->album->artwork_id ? "/api/artwork/{$track->album->artwork_id}/thumbnail" : null,
                'personalMetadata' => $this->albumPersonalMetadata($track->album),
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
            'album.ownedCopies',
            'artists:id,name',
            'genres:id,name',
            'mediaFile:id,library_root_id,relative_path,file_size,modified_at,mime_type,container,codec,bitrate,sample_rate,channels,status,scan_error,raw_metadata',
            'mediaFile.libraryRoot:id,name',
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
            'comment' => $track->comment,
            'composers' => $track->composers ?? [],
            'performers' => $track->performers ?? [],
            'genres' => $track->genres->map(fn (Genre $genre) => [
                'id' => $genre->id,
                'name' => $genre->name,
            ])->values(),
            'mediaFile' => $mediaFile ? [
                'id' => $mediaFile->id,
                'libraryRoot' => $mediaFile->libraryRoot ? [
                    'id' => $mediaFile->libraryRoot->id,
                    'name' => $mediaFile->libraryRoot->name,
                ] : null,
                'relativePath' => $mediaFile->relative_path,
                'fileSize' => $mediaFile->file_size,
                'modifiedAt' => $mediaFile->modified_at?->toIso8601String(),
                'mimeType' => $mediaFile->mime_type,
                'container' => $mediaFile->container,
                'codec' => $mediaFile->codec,
                'encoder' => $this->metadataText($audioMetadata['encoder'] ?? $audioStream['encoder'] ?? null),
                'encoderSettings' => $this->encoderSettings($audioMetadata['encoder_options'] ?? $audioStream['encoder_options'] ?? null),
                'bitrate' => $mediaFile->bitrate,
                'sampleRate' => $mediaFile->sample_rate,
                'channels' => $mediaFile->channels,
                'status' => $mediaFile->status?->value,
                'scanError' => $mediaFile->scan_error,
                'additionalTags' => $this->additionalTags->extract($mediaFile->raw_metadata ?? []),
            ] : null,
            'playStatistics' => $this->playStatisticsPayload($track),
        ];
    }

    /** @return array<string, mixed> */
    public function albumPersonalMetadata(Album $album): array
    {
        $metadata = $album->relationLoaded('personalMetadata') ? $album->personalMetadata : null;
        $copies = $album->relationLoaded('ownedCopies') ? $album->ownedCopies : collect();
        $firstCopy = $copies->first();
        $physicalCopy = $copies->first(fn (OwnedAlbumCopy $copy): bool => $copy->is_physical);

        return [
            'purchaseSource' => $firstCopy?->purchase_source,
            'purchaseDate' => $firstCopy?->purchase_date?->toDateString(),
            'hasPhysicalCopy' => $physicalCopy !== null,
            'physicalFormat' => $physicalCopy?->physical_format,
            'notes' => $metadata?->notes,
            'ownedCopies' => $copies->map(fn (OwnedAlbumCopy $copy): array => [
                'id' => $copy->id,
                'isPhysical' => $copy->is_physical,
                'physicalFormat' => $copy->physical_format,
                'purchaseSource' => $copy->purchase_source,
                'purchaseDate' => $copy->purchase_date?->toDateString(),
                'purchasePriceAmount' => $copy->purchase_price_amount,
                'purchasePriceCurrency' => $copy->purchase_price_currency,
                'mediaCondition' => $copy->media_condition,
                'sleeveCondition' => $copy->sleeve_condition,
                'notes' => $copy->notes,
                'provider' => $copy->provider,
                'externalReleaseId' => $copy->external_release_id,
                'externalMasterId' => $copy->external_master_id,
                'externalCollectionInstanceId' => $copy->external_collection_instance_id,
                'externalFolderId' => $copy->external_folder_id,
                'providerSyncedAt' => $copy->provider_synced_at?->toJSON(),
            ])->values(),
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

    /**
     * @return array{
     *     fileTypes: list<string>,
     *     bitrateMinimum: ?int,
     *     bitrateMaximum: ?int,
     *     bitrateModes: list<string>,
     *     encoderSettings: list<string>
     * }
     */
    private function albumTechnicalSummary(Album $album): array
    {
        $files = $album->tracks
            ->map(fn (Track $track) => $track->mediaFile)
            ->filter()
            ->values();
        $bitrates = $files
            ->pluck('bitrate')
            ->filter(fn (mixed $bitrate): bool => is_int($bitrate) && $bitrate > 0);

        return [
            'fileTypes' => $this->distinctTechnicalValues(
                $files->map(fn (MediaFile $file): ?string => $this->fileType($file)),
            ),
            'bitrateMinimum' => $bitrates->min(),
            'bitrateMaximum' => $bitrates->max(),
            'bitrateModes' => $this->distinctTechnicalValues(
                $files->map(fn (MediaFile $file): ?string => $this->metadataText($file->getAttribute('bitrate_mode'))),
            ),
            'encoderSettings' => $this->distinctTechnicalValues(
                $files->map(fn (MediaFile $file): ?string => $this->encoderSettings($file->getAttribute('encoder_settings'))),
            ),
        ];
    }

    private function fileType(MediaFile $file): ?string
    {
        $extension = pathinfo($file->relative_path, PATHINFO_EXTENSION);
        $value = $extension !== '' ? $extension : ($file->container ?? $file->codec);

        return $value ? mb_strtoupper($value) : null;
    }

    /** @return list<string> */
    private function distinctTechnicalValues(Collection $values): array
    {
        return $values
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->sortBy(fn (string $value): string => mb_strtolower($value), SORT_NATURAL)
            ->values()
            ->all();
    }

    private function metadataText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function encoderSettings(mixed $value): ?string
    {
        $settings = $this->metadataText($value);
        if ($settings === null) {
            return null;
        }

        if (preg_match('/^--preset\s+(?:fast\s+)?extreme(?:\s+-b\s*\d+)?$/i', $settings) === 1) {
            return 'V0';
        }

        return $settings;
    }
}
