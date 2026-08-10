<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Track;
use App\Models\TrackPlayStatistic;
use App\Support\LibraryRootScope;
use App\Support\MusicianCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardMetricsController extends Controller
{
    public function __construct(
        private readonly LibraryRootScope $libraryRootScope,
        private readonly MusicianCatalog $musicians,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);

        return response()->json([
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
        ]);
    }
}
