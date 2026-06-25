<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Artist;
use App\Models\FavoriteAlbum;
use App\Models\FavoriteTrack;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FavoritesController extends Controller
{
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

        return $this->paginated($favorites, fn (FavoriteTrack $favorite) => $this->trackPayload($favorite->track));
    }

    public function albums(): JsonResponse
    {
        $favorites = FavoriteAlbum::query()
            ->with(['album.primaryArtist:id,name', 'album.artwork:id'])
            ->orderByDesc('created_at')
            ->paginate(24);

        return $this->paginated($favorites, fn (FavoriteAlbum $favorite) => $this->albumPayload($favorite->album));
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

    private function paginated(LengthAwarePaginator $paginator, callable $map): JsonResponse
    {
        return response()->json([
            'items' => collect($paginator->items())->map($map)->values(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'lastPage' => $paginator->lastPage(),
        ]);
    }

    private function albumPayload(Album $album): array
    {
        $trackCount = DB::table('tracks')->where('album_id', $album->id)->count();

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
        ];
    }

    private function trackPayload(Track $track): array
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
            ] : null,
            'artists' => $track->artists->map(fn (Artist $artist) => [
                'id' => $artist->id,
                'name' => $artist->name,
            ])->values(),
        ];
    }
}
