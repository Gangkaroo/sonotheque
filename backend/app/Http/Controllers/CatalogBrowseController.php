<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CatalogBrowseController extends Controller
{
    public function artists(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:512'],
            'initial' => ['sometimes', 'nullable', 'string', 'in:#,A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z'],
        ]);

        $artists = Artist::query()
            ->select(['id', 'name', 'sort_name', 'browse_initial'])
            ->withCount('albums')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'ilike', '%'.$this->escapeLike($search).'%'))
            ->when($filters['initial'] ?? null, fn (Builder $query, string $initial) => $query->where('browse_initial', $initial))
            ->orderByRaw('coalesce(sort_name, name)')
            ->orderBy('name')
            ->paginate(50);

        return $this->paginated($artists, fn (Artist $artist) => [
            'id' => $artist->id,
            'name' => $artist->name,
            'browseInitial' => $artist->browse_initial,
            'albumCount' => $artist->albums_count,
        ]);
    }

    public function albums(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        $albums = Album::query()
            ->select(['id', 'title', 'sort_title', 'original_release_year', 'primary_artist_id', 'artwork_id'])
            ->with(['primaryArtist:id,name', 'artwork:id'])
            ->withCount('tracks')
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $pattern = '%'.$this->escapeLike($search).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->where('title', 'ilike', $pattern)
                        ->orWhereHas('primaryArtist', fn (Builder $artistQuery) => $artistQuery->where('name', 'ilike', $pattern));
                });
            })
            ->orderByRaw('coalesce(sort_title, title)')
            ->orderBy('title')
            ->paginate(24);

        return $this->paginated($albums, fn (Album $album) => [
            'id' => $album->id,
            'title' => $album->title,
            'originalReleaseYear' => $album->original_release_year,
            'primaryArtist' => $album->primaryArtist ? [
                'id' => $album->primaryArtist->id,
                'name' => $album->primaryArtist->name,
            ] : null,
            'trackCount' => $album->tracks_count,
            'artworkThumbnailUrl' => $album->artwork_id ? "/api/artwork/{$album->artwork_id}/thumbnail" : null,
        ]);
    }

    public function tracks(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        $tracks = Track::query()
            ->select(['id', 'title', 'sort_title', 'duration_ms', 'track_number', 'disc_number', 'album_id'])
            ->with(['album:id,title', 'artists:id,name'])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('title', 'ilike', '%'.$this->escapeLike($search).'%'))
            ->orderBy('album_id')
            ->orderBy('disc_number')
            ->orderBy('track_number')
            ->orderBy('id')
            ->paginate(50);

        return $this->paginated($tracks, fn (Track $track) => [
            'id' => $track->id,
            'title' => $track->title,
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
        ]);
    }

    public function genres(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $genres = Genre::query()
            ->select(['id', 'name'])
            ->withCount('tracks')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'ilike', '%'.$this->escapeLike($search).'%'))
            ->orderBy('name')
            ->paginate(50);

        return $this->paginated($genres, fn (Genre $genre) => [
            'id' => $genre->id,
            'name' => $genre->name,
            'trackCount' => $genre->tracks_count,
        ]);
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

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }
}
