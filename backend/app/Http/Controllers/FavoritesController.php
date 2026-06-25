<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\FavoriteAlbum;
use App\Models\FavoriteTrack;
use App\Models\Track;
use App\Support\CatalogPayloads;
use Illuminate\Http\JsonResponse;

class FavoritesController extends Controller
{
    public function __construct(private readonly CatalogPayloads $payloads) {}

    public function ids(): JsonResponse
    {
        return response()->json([
            'tracks' => FavoriteTrack::query()->orderBy('track_id')->pluck('track_id')->values(),
            'albums' => FavoriteAlbum::query()->orderBy('album_id')->pluck('album_id')->values(),
        ]);
    }

    public function tracks(): JsonResponse
    {
        $favorites = FavoriteTrack::query()
            ->with(['track.album:id,title', 'track.artists:id,name'])
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($this->payloads->paginated($favorites, fn (FavoriteTrack $favorite) => $this->payloads->trackSummary($favorite->track)));
    }

    public function albums(): JsonResponse
    {
        $favorites = FavoriteAlbum::query()
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
