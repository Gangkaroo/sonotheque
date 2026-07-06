<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\FavoriteAlbum;
use App\Models\FavoriteTrack;
use App\Models\Track;
use App\Support\CatalogPayloads;
use App\Support\LibraryRootScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoritesController extends Controller
{
    public function __construct(
        private readonly CatalogPayloads $payloads,
        private readonly LibraryRootScope $libraryRootScope,
    ) {
    }

    public function ids(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);

        return response()->json([
            'tracks' => FavoriteTrack::query()
                ->whereHas('track', fn (Builder $tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId))
                ->orderBy('track_id')
                ->pluck('track_id')
                ->values(),
            'albums' => FavoriteAlbum::query()
                ->whereHas('album', fn (Builder $albums) => $this->libraryRootScope->albums($albums, $libraryRootId))
                ->orderBy('album_id')
                ->pluck('album_id')
                ->values(),
        ]);
    }

    public function tracks(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $favorites = FavoriteTrack::query()
            ->whereHas('track', fn (Builder $tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId))
            ->with(['track.album:id,title,original_release_year,artwork_id', 'track.artists:id,name'])
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($this->payloads->paginated($favorites, fn (FavoriteTrack $favorite) => $this->payloads->trackSummary($favorite->track)));
    }

    public function albums(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $favorites = FavoriteAlbum::query()
            ->whereHas('album', fn (Builder $albums) => $this->libraryRootScope->albums($albums, $libraryRootId))
            ->with(['album' => fn ($query) => $query
                ->with(['primaryArtist:id,name', 'artwork:id'])
                ->withCount('tracks')])
            ->orderByDesc('created_at')
            ->paginate(24);

        return response()->json($this->payloads->paginated($favorites, fn (FavoriteAlbum $favorite) => $this->payloads->albumSummary($favorite->album)));
    }

    public function addTrack(Track $track): JsonResponse
    {
        FavoriteTrack::query()->firstOrCreate(['track_id' => $track->id]);

        return response()->json(['trackId' => $track->id], 201);
    }

    public function removeTrack(Track $track): JsonResponse
    {
        FavoriteTrack::query()->where('track_id', $track->id)->delete();

        return response()->json(null, 204);
    }

    public function addAlbum(Album $album): JsonResponse
    {
        FavoriteAlbum::query()->firstOrCreate(['album_id' => $album->id]);

        return response()->json(['albumId' => $album->id], 201);
    }

    public function removeAlbum(Album $album): JsonResponse
    {
        FavoriteAlbum::query()->where('album_id', $album->id)->delete();

        return response()->json(null, 204);
    }
}
