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
            'initial' => ['sometimes', 'nullable', 'string', 'in:#,A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'genre' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $albums = Album::query()
            ->leftJoin('artists as primary_artists', 'primary_artists.id', '=', 'albums.primary_artist_id')
            ->select([
                'albums.id',
                'albums.title',
                'albums.sort_title',
                'albums.original_release_year',
                'albums.primary_artist_id',
                'albums.artwork_id',
            ])
            ->with(['primaryArtist:id,name', 'artwork:id'])
            ->withCount('tracks')
            ->has('tracks')
            ->when($filters['year'] ?? null, fn (Builder $query, int $year) => $query->where('albums.original_release_year', $year))
            ->when($filters['genre'] ?? null, fn (Builder $query, int $genre) => $query->whereHas('tracks.genres', fn (Builder $genreQuery) => $genreQuery->whereKey($genre)))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $pattern = '%'.$this->escapeLike($search).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->where('albums.title', 'ilike', $pattern)
                        ->orWhereHas('primaryArtist', fn (Builder $artistQuery) => $artistQuery->where('name', 'ilike', $pattern));
                });
            })
            ->when($filters['initial'] ?? null, fn (Builder $query, string $initial) => $query->where('primary_artists.browse_initial', $initial))
            ->orderByRaw('primary_artists.name is null')
            ->orderByRaw('coalesce(primary_artists.sort_name, primary_artists.name)')
            ->orderBy('primary_artists.name')
            ->orderByRaw('coalesce(albums.sort_title, albums.title)')
            ->orderBy('albums.title')
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

    public function album(Album $album): JsonResponse
    {
        return response()->json($this->albumPayload($album));
    }

    public function randomAlbum(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'exclude' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $query = Album::query()->has('tracks');
        if (Album::query()->has('tracks')->count() > 1 && ($filters['exclude'] ?? null)) {
            $query->whereKeyNot($filters['exclude']);
        }

        $album = $query->inRandomOrder()->firstOrFail();

        return response()->json($this->albumPayload($album));
    }

    public function nextAlbum(Album $album): JsonResponse
    {
        $ids = $this->orderedAlbumIds();
        abort_if($ids === [], 404);

        $index = array_search($album->id, $ids, true);
        $nextId = $ids[$index === false ? 0 : ($index + 1) % count($ids)];

        return response()->json($this->albumPayload(Album::findOrFail($nextId)));
    }

    public function randomTrack(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'exclude' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $query = Track::query();
        if (Track::query()->count() > 1 && ($filters['exclude'] ?? null)) {
            $query->whereKeyNot($filters['exclude']);
        }

        $track = $query->inRandomOrder()->firstOrFail();

        return response()->json($this->trackPayload($this->loadPlayableTrack($track)));
    }

    public function nextTrack(Track $track): JsonResponse
    {
        $ids = $this->orderedTrackIds();
        abort_if($ids === [], 404);

        $index = array_search($track->id, $ids, true);
        $nextId = $ids[$index === false ? 0 : ($index + 1) % count($ids)];

        return response()->json($this->trackPayload($this->loadPlayableTrack(Track::findOrFail($nextId))));
    }

    public function tracks(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:512'],
            'genre' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $tracks = Track::query()
            ->select(['id', 'title', 'sort_title', 'duration_ms', 'track_number', 'disc_number', 'album_id'])
            ->with(['album:id,title', 'artists:id,name'])
            ->when($filters['genre'] ?? null, fn (Builder $query, int $genre) => $query->whereHas('genres', fn (Builder $genreQuery) => $genreQuery->whereKey($genre)))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $pattern = '%'.$this->escapeLike($search).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->where('title', 'ilike', $pattern)
                        ->orWhereHas('artists', fn (Builder $artistQuery) => $artistQuery->where('name', 'ilike', $pattern));
                });
            })
            ->orderBy('album_id')
            ->orderBy('disc_number')
            ->orderBy('track_number')
            ->orderBy('id')
            ->paginate(50);

        return $this->paginated($tracks, fn (Track $track) => [
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
        ]);
    }

    public function track(Track $track): JsonResponse
    {
        return response()->json($this->trackDetailPayload($track));
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

    private function albumPayload(Album $album): array
    {
        $album->load([
            'primaryArtist:id,name',
            'artwork:id,width,height',
            'tracks' => fn ($query) => $query
                ->select(['id', 'title', 'sort_title', 'duration_ms', 'track_number', 'disc_number', 'album_id'])
                ->with(['album:id,title', 'artists:id,name', 'genres:id,name'])
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
            'id' => $album->id,
            'title' => $album->title,
            'originalReleaseYear' => $album->original_release_year,
            'primaryArtist' => $album->primaryArtist ? [
                'id' => $album->primaryArtist->id,
                'name' => $album->primaryArtist->name,
            ] : null,
            'trackCount' => $album->tracks_count,
            'artworkThumbnailUrl' => $album->artwork_id ? "/api/artwork/{$album->artwork_id}/thumbnail" : null,
            'artworkUrl' => $album->artwork_id ? "/api/artwork/{$album->artwork_id}/original" : null,
            'artworkWidth' => $album->artwork?->width,
            'artworkHeight' => $album->artwork?->height,
            'genres' => $genres->map(fn (Genre $genre) => [
                'id' => $genre->id,
                'name' => $genre->name,
            ])->values(),
            'tracks' => $album->tracks->map(fn (Track $track) => $this->trackPayload($track))->values(),
        ];
    }

    private function loadPlayableTrack(Track $track): Track
    {
        return $track->load(['album:id,title', 'artists:id,name']);
    }

    private function trackDetailPayload(Track $track): array
    {
        $track->load([
            'album:id,title,original_release_year',
            'artists:id,name',
            'genres:id,name',
            'mediaFile:id,relative_path,file_size,modified_at,mime_type,container,codec,bitrate,sample_rate,channels,status,scan_error',
        ]);

        $mediaFile = $track->mediaFile;

        return [
            ...$this->trackPayload($track),
            'year' => $track->year,
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
                'bitrate' => $mediaFile->bitrate,
                'sampleRate' => $mediaFile->sample_rate,
                'channels' => $mediaFile->channels,
                'status' => $mediaFile->status?->value,
                'scanError' => $mediaFile->scan_error,
            ] : null,
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

    /** @return list<int> */
    private function orderedAlbumIds(): array
    {
        return Album::query()
            ->leftJoin('artists as primary_artists', 'primary_artists.id', '=', 'albums.primary_artist_id')
            ->has('tracks')
            ->orderByRaw('primary_artists.name is null')
            ->orderByRaw('coalesce(primary_artists.sort_name, primary_artists.name)')
            ->orderBy('primary_artists.name')
            ->orderByRaw('coalesce(albums.sort_title, albums.title)')
            ->orderBy('albums.title')
            ->pluck('albums.id')
            ->all();
    }

    /** @return list<int> */
    private function orderedTrackIds(): array
    {
        return Track::query()
            ->join('albums', 'albums.id', '=', 'tracks.album_id')
            ->leftJoin('artists as primary_artists', 'primary_artists.id', '=', 'albums.primary_artist_id')
            ->orderByRaw('primary_artists.name is null')
            ->orderByRaw('coalesce(primary_artists.sort_name, primary_artists.name)')
            ->orderBy('primary_artists.name')
            ->orderByRaw('coalesce(albums.sort_title, albums.title)')
            ->orderBy('albums.title')
            ->orderBy('tracks.disc_number')
            ->orderBy('tracks.track_number')
            ->orderBy('tracks.id')
            ->pluck('tracks.id')
            ->all();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }
}
