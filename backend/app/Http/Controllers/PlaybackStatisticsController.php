<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Track;
use App\Models\TrackPlayEvent;
use App\Support\CatalogPayloads;
use App\Support\LibraryRootScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PlaybackStatisticsController extends Controller
{
    public function __construct(
        private readonly CatalogPayloads $payloads,
        private readonly LibraryRootScope $libraryRootScope,
    ) {
    }

    public function recentPlays(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $events = TrackPlayEvent::query()
            ->select(['id', 'track_id', 'played_at'])
            ->where('counted', true)
            ->whereHas('track', fn (Builder $tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId))
            ->with(['track' => fn ($query) => $query
                ->select(['id', 'title', 'sort_title', 'duration_ms', 'track_number', 'disc_number', 'album_id'])
                ->with(['album:id,title,original_release_year,artwork_id', 'album.personalMetadata', 'artists:id,name', 'playStatistic:track_id,play_count,first_played_at,last_played_at'])])
            ->orderByDesc('played_at')
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json($this->payloads->paginated($events, fn (TrackPlayEvent $event) => [
            'id' => $event->id,
            'playedAt' => $event->played_at?->toJSON(),
            'track' => $this->payloads->trackSummary($event->track),
        ]));
    }

    public function trackRecentPlays(Request $request, Track $track): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        abort_unless(
            $this->libraryRootScope->tracks(Track::query(), $libraryRootId)->whereKey($track->id)->exists(),
            404,
        );
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $events = TrackPlayEvent::query()
            ->select(['id', 'track_id', 'played_at'])
            ->whereBelongsTo($track)
            ->where('counted', true)
            ->with(['track' => fn ($query) => $query
                ->select(['id', 'title', 'sort_title', 'duration_ms', 'track_number', 'disc_number', 'album_id'])
                ->with(['album:id,title,original_release_year,artwork_id', 'album.personalMetadata', 'artists:id,name', 'playStatistic:track_id,play_count,first_played_at,last_played_at'])])
            ->orderByDesc('played_at')
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json($this->payloads->paginated($events, fn (TrackPlayEvent $event) => [
            'id' => $event->id,
            'playedAt' => $event->played_at?->toJSON(),
            'track' => $this->payloads->trackSummary($event->track),
        ]));
    }

    public function mostPlayedTracks(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $tracks = $this->libraryRootScope->tracks(Track::query(), $libraryRootId)
            ->join('track_play_statistics', 'track_play_statistics.track_id', '=', 'tracks.id')
            ->select(['tracks.id', 'tracks.title', 'tracks.sort_title', 'tracks.duration_ms', 'tracks.track_number', 'tracks.disc_number', 'tracks.album_id'])
            ->with(['album:id,title,original_release_year,artwork_id', 'album.personalMetadata', 'artists:id,name', 'playStatistic:track_id,play_count,first_played_at,last_played_at'])
            ->where('track_play_statistics.play_count', '>', 0)
            ->orderByDesc('track_play_statistics.play_count')
            ->orderByDesc('track_play_statistics.last_played_at')
            ->orderBy('tracks.title')
            ->paginate(50);

        return response()->json($this->payloads->paginated($tracks, fn (Track $track) => $this->payloads->trackSummary($track)));
    }

    public function mostPlayedAlbums(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $albums = $this->libraryRootScope->albums(Album::query(), $libraryRootId, 'albums.library_root_id')
            ->join('tracks', 'tracks.album_id', '=', 'albums.id')
            ->join('track_play_statistics', 'track_play_statistics.track_id', '=', 'tracks.id')
            ->select([
                'albums.id',
                'albums.title',
                'albums.sort_title',
                'albums.original_release_year',
                'albums.primary_artist_id',
                'albums.artwork_id',
            ])
            ->selectRaw('sum(track_play_statistics.play_count) as play_count')
            ->selectRaw('max(track_play_statistics.last_played_at) as last_played_at')
            ->with(['primaryArtist:id,name', 'artwork:id', 'personalMetadata'])
            ->withCount('tracks')
            ->where('track_play_statistics.play_count', '>', 0)
            ->groupBy([
                'albums.id',
                'albums.title',
                'albums.sort_title',
                'albums.original_release_year',
                'albums.primary_artist_id',
                'albums.artwork_id',
            ])
            ->orderByDesc('play_count')
            ->orderByDesc('last_played_at')
            ->orderBy('albums.title')
            ->paginate(24);

        return response()->json($this->payloads->paginated($albums, fn (Album $album) => [
            ...$this->payloads->albumSummary($album),
            'playCount' => (int) $album->play_count,
            'lastPlayedAt' => $album->last_played_at ? Carbon::parse($album->last_played_at)->toJSON() : null,
        ]));
    }
}
