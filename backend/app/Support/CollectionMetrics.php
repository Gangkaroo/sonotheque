<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Track;
use App\Models\TrackPlayStatistic;

class CollectionMetrics
{
    /** @var list<string> */
    public const METRICS = [
        'artists',
        'musicians',
        'albums',
        'tracks',
        'genres',
        'playedAlbums',
        'playedTracks',
    ];

    public function __construct(
        private readonly LibraryRootScope $libraryRootScope,
        private readonly MusicianCatalog $musicians,
    ) {
    }

    /** @return array<string, int> */
    public function forLibraryRoot(?int $libraryRootId, ?array $metrics = null): array
    {
        $counts = [];
        foreach ($metrics ?? self::METRICS as $metric) {
            $counts[$metric] = match ($metric) {
                'artists' => Artist::query()
                    ->where(fn ($query) => $query
                        ->whereHas('albums', fn ($albums) => $this->libraryRootScope->albums($albums, $libraryRootId))
                        ->orWhereHas('tracks', fn ($tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId)))
                    ->count(),
                'musicians' => $this->musicians->count($libraryRootId),
                'albums' => $this->libraryRootScope->albums(Album::query(), $libraryRootId)->count(),
                'tracks' => $this->libraryRootScope->tracks(Track::query(), $libraryRootId)->count(),
                'genres' => Genre::query()
                    ->whereHas('tracks', fn ($tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId))
                    ->count(),
                'playedAlbums' => $this->libraryRootScope->albums(Album::query(), $libraryRootId)
                    ->whereHas('tracks.playStatistic', fn ($query) => $query->where('play_count', '>', 0))
                    ->count(),
                'playedTracks' => TrackPlayStatistic::query()
                    ->where('play_count', '>', 0)
                    ->whereHas('track', fn ($tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId))
                    ->count(),
            };
        }

        return $counts;
    }
}
