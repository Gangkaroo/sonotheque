<?php

namespace App\Music\Metadata;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Track;
use App\Music\Catalog\GenreResolver;
use App\Music\Scanning\ArtistName;
use App\Music\Scanning\AudioMetadata;
use Carbon\CarbonImmutable;

class TrackMetadataCatalogUpdater
{
    public function __construct(
        private readonly ArtistName $artistName,
        private readonly GenreResolver $genreResolver,
    ) {
    }

    public function apply(
        Track $track,
        AudioMetadata $metadata,
        int $fileSize,
        CarbonImmutable $modifiedAt,
    ): void {
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

        $previousArtistIds = $track->artists()->pluck('artists.id');
        $artistPivots = [];
        foreach ($metadata->artists as $position => $name) {
            $artist = Artist::query()->whereRaw('LOWER(name) = LOWER(?)', [$name])->first()
                ?? Artist::create([
                    'name' => $name,
                    'sort_name' => $name,
                    'browse_initial' => $this->artistName->browseInitial($name),
                ]);
            $artistPivots[$artist->id] = ['role' => 'primary', 'position' => $position];
        }
        $track->artists()->sync($artistPivots);
        Artist::query()
            ->whereIn('id', $previousArtistIds)
            ->whereDoesntHave('albums')
            ->whereDoesntHave('tracks')
            ->delete();

        $previousGenreIds = $track->genres()->pluck('genres.id');
        $genreIds = collect($metadata->genres)
            ->map(fn (string $name): int => $this->genreResolver->resolve($name)->id)
            ->all();
        $track->genres()->sync($genreIds);
        Genre::query()
            ->whereIn('id', $previousGenreIds)
            ->whereDoesntHave('tracks')
            ->delete();

        $track->mediaFile->update([
            'file_size' => $fileSize,
            'modified_at' => $modifiedAt,
            'raw_metadata' => $metadata->rawMetadata,
        ]);
    }
}
