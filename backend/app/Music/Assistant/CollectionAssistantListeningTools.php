<?php

namespace App\Music\Assistant;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\LibraryRoot;
use App\Models\Track;
use App\Models\TrackPlayEvent;
use App\Models\TrackPlayStatistic;
use App\Support\LibraryRootScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CollectionAssistantListeningTools
{
    /** @var list<string> */
    private const PERIODS = [
        'today',
        'current_week',
        'current_month',
        'current_year',
        '7_days',
        '30_days',
        '90_days',
        '365_days',
        'all_time',
    ];

    /** @var list<string> */
    private const TOP_ENTITY_TYPES = ['tracks', 'albums', 'artists', 'genres'];

    /** @var list<string> */
    private const TOOL_NAMES = [
        'listening_summary',
        'top_listened',
        'recent_listening_history',
        'find_unplayed_albums',
    ];

    public function __construct(private readonly LibraryRootScope $libraryRootScope)
    {
    }

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        return [
            $this->definition(
                'listening_summary',
                'Return play, played-track, and played-album totals. Date-limited periods use timestamped Sonotheque play events; all_time also includes imported aggregate play counts.',
                [
                    'period' => [
                        'type' => 'string',
                        'enum' => self::PERIODS,
                        'description' => 'Time period for the listening totals.',
                    ],
                ],
                ['period'],
            ),
            $this->definition(
                'top_listened',
                'Return the most-played tracks, albums, artists, or genres. Date-limited periods use timestamped Sonotheque play events; all_time also includes imported aggregate play counts.',
                [
                    'entity_type' => [
                        'type' => 'string',
                        'enum' => self::TOP_ENTITY_TYPES,
                    ],
                    'period' => [
                        'type' => 'string',
                        'enum' => self::PERIODS,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 10,
                        'default' => 5,
                    ],
                ],
                ['entity_type', 'period'],
            ),
            $this->definition(
                'recent_listening_history',
                'Return the latest counted Sonotheque play events in the active library-root scope.',
                [
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 10,
                        'default' => 5,
                    ],
                ],
            ),
            $this->definition(
                'find_unplayed_albums',
                'Find available albums with no positive aggregate play count in the active library-root scope.',
                [
                    'artist_name' => [
                        'type' => 'string',
                        'description' => 'Optional exact album artist name.',
                        'minLength' => 1,
                        'maxLength' => 255,
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Optional album-title text.',
                        'minLength' => 1,
                        'maxLength' => 100,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 10,
                        'default' => 5,
                    ],
                ],
            ),
        ];
    }

    public function supports(string $name): bool
    {
        return in_array($name, self::TOOL_NAMES, true);
    }

    /** @param array<string, mixed> $arguments */
    public function execute(string $name, array $arguments, ?int $libraryRootId): array
    {
        return match ($name) {
            'listening_summary' => $this->summary($arguments, $libraryRootId),
            'top_listened' => $this->topListened($arguments, $libraryRootId),
            'recent_listening_history' => $this->recentHistory($arguments, $libraryRootId),
            'find_unplayed_albums' => $this->unplayedAlbums($arguments, $libraryRootId),
            default => throw new CollectionAssistantToolException('unknown_tool'),
        };
    }

    /** @param array<string, mixed> $arguments */
    private function summary(array $arguments, ?int $libraryRootId): array
    {
        $validated = $this->validated($arguments, ['period'], [
            'period' => ['required', 'string', Rule::in(self::PERIODS)],
        ]);
        $period = $validated['period'];
        $since = $this->periodStart($period);

        if ($since === null) {
            $statistics = TrackPlayStatistic::query()
                ->join('tracks', 'tracks.id', '=', 'track_play_statistics.track_id')
                ->join('media_files', 'media_files.id', '=', 'tracks.media_file_id')
                ->join('library_roots', 'library_roots.id', '=', 'media_files.library_root_id')
                ->where('play_count', '>', 0)
                ->where('media_files.status', MediaFileStatus::Available->value)
                ->where('library_roots.enabled', true)
                ->when(
                    $libraryRootId,
                    fn (Builder $query, int $id) => $query->where('library_roots.id', $id),
                )
                ->selectRaw('coalesce(sum(track_play_statistics.play_count), 0) as plays')
                ->selectRaw('count(distinct track_play_statistics.track_id) as tracks')
                ->selectRaw('count(distinct tracks.album_id) as albums')
                ->firstOrFail();

            $counts = [
                'plays' => (int) $statistics->plays,
                'tracks' => (int) $statistics->tracks,
                'albums' => (int) $statistics->albums,
            ];
        } else {
            $events = $this->events($libraryRootId, $since);
            $counts = [
                'plays' => (int) (clone $events)->count(),
                'tracks' => (int) (clone $events)->distinct()->count('track_play_events.track_id'),
                'albums' => (int) (clone $events)
                    ->join('tracks', 'tracks.id', '=', 'track_play_events.track_id')
                    ->distinct()
                    ->count('tracks.album_id'),
            ];
        }

        return [
            'scope' => $this->scope($libraryRootId),
            'period' => $this->periodPayload($period, $since),
            'counts' => $counts,
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function topListened(array $arguments, ?int $libraryRootId): array
    {
        $validated = $this->validated($arguments, ['entity_type', 'period', 'limit'], [
            'entity_type' => ['required', 'string', Rule::in(self::TOP_ENTITY_TYPES)],
            'period' => ['required', 'string', Rule::in(self::PERIODS)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);
        $period = $validated['period'];
        $since = $this->periodStart($period);
        $limit = (int) ($validated['limit'] ?? 5);
        $entityType = $validated['entity_type'];

        $results = match ($entityType) {
            'tracks' => $this->topTracks($libraryRootId, $since, $limit),
            'albums' => $this->topAlbums($libraryRootId, $since, $limit),
            'artists' => $this->topArtists($libraryRootId, $since, $limit),
            'genres' => $this->topGenres($libraryRootId, $since, $limit),
        };

        return [
            'scope' => $this->scope($libraryRootId),
            'period' => $this->periodPayload($period, $since),
            'entityType' => $entityType,
            'results' => $results,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function topTracks(?int $libraryRootId, ?Carbon $since, int $limit): array
    {
        $query = $this->libraryRootScope->tracks(Track::query(), $libraryRootId)
            ->with(['album:id,title', 'artists:id,name']);
        if ($since === null) {
            $query
                ->join('track_play_statistics', 'track_play_statistics.track_id', '=', 'tracks.id')
                ->select(['tracks.id', 'tracks.title', 'tracks.album_id'])
                ->selectRaw('track_play_statistics.play_count as play_count')
                ->selectRaw('track_play_statistics.last_played_at as last_played_at')
                ->where('track_play_statistics.play_count', '>', 0);
        } else {
            $query
                ->join('track_play_events', 'track_play_events.track_id', '=', 'tracks.id')
                ->select(['tracks.id', 'tracks.title', 'tracks.album_id'])
                ->selectRaw('count(track_play_events.id) as play_count')
                ->selectRaw('max(track_play_events.played_at) as last_played_at')
                ->where('track_play_events.counted', true)
                ->where('track_play_events.played_at', '>=', $since)
                ->groupBy(['tracks.id', 'tracks.title', 'tracks.album_id']);
        }

        return $query
            ->orderByDesc('play_count')
            ->orderByDesc('last_played_at')
            ->limit($limit)
            ->get()
            ->map(fn (Track $track): array => [
                'id' => $track->id,
                'title' => $track->title,
                'artist' => $track->artists->first()?->name,
                'artists' => $track->artists->pluck('name')->values()->all(),
                'album' => $track->album?->title,
                'albumId' => $track->album?->id,
                'playCount' => (int) $track->play_count,
                'lastPlayedAt' => $track->last_played_at
                    ? Carbon::parse($track->last_played_at)->toJSON()
                    : null,
                'path' => '/tracks/'.$track->id,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function topAlbums(?int $libraryRootId, ?Carbon $since, int $limit): array
    {
        $query = $this->libraryRootScope
            ->albums(Album::query(), $libraryRootId, 'albums.library_root_id')
            ->join('tracks', 'tracks.album_id', '=', 'albums.id')
            ->with('primaryArtist:id,name')
            ->select(['albums.id', 'albums.title', 'albums.original_release_year', 'albums.primary_artist_id']);
        if ($since === null) {
            $query
                ->join('track_play_statistics', 'track_play_statistics.track_id', '=', 'tracks.id')
                ->selectRaw('sum(track_play_statistics.play_count) as play_count')
                ->selectRaw('max(track_play_statistics.last_played_at) as last_played_at')
                ->where('track_play_statistics.play_count', '>', 0);
        } else {
            $query
                ->join('track_play_events', 'track_play_events.track_id', '=', 'tracks.id')
                ->selectRaw('count(track_play_events.id) as play_count')
                ->selectRaw('max(track_play_events.played_at) as last_played_at')
                ->where('track_play_events.counted', true)
                ->where('track_play_events.played_at', '>=', $since);
        }

        return $query
            ->groupBy([
                'albums.id',
                'albums.title',
                'albums.original_release_year',
                'albums.primary_artist_id',
            ])
            ->orderByDesc('play_count')
            ->orderByDesc('last_played_at')
            ->limit($limit)
            ->get()
            ->map(fn (Album $album): array => [
                'id' => $album->id,
                'title' => $album->title,
                'artist' => $album->primaryArtist?->name,
                'year' => $album->original_release_year,
                'playCount' => (int) $album->play_count,
                'lastPlayedAt' => $album->last_played_at
                    ? Carbon::parse($album->last_played_at)->toJSON()
                    : null,
                'path' => '/albums/'.$album->id,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function topArtists(?int $libraryRootId, ?Carbon $since, int $limit): array
    {
        $scopedTrackIds = $this->libraryRootScope
            ->tracks(Track::query(), $libraryRootId)
            ->select('tracks.id');
        $query = Artist::query()
            ->join('artist_track', 'artist_track.artist_id', '=', 'artists.id')
            ->join('tracks', 'tracks.id', '=', 'artist_track.track_id')
            ->whereIn('tracks.id', $scopedTrackIds)
            ->select(['artists.id', 'artists.name']);
        if ($since === null) {
            $query
                ->join('track_play_statistics', 'track_play_statistics.track_id', '=', 'tracks.id')
                ->selectRaw('sum(track_play_statistics.play_count) as play_count')
                ->selectRaw('max(track_play_statistics.last_played_at) as last_played_at')
                ->where('track_play_statistics.play_count', '>', 0);
        } else {
            $query
                ->join('track_play_events', 'track_play_events.track_id', '=', 'tracks.id')
                ->selectRaw('count(track_play_events.id) as play_count')
                ->selectRaw('max(track_play_events.played_at) as last_played_at')
                ->where('track_play_events.counted', true)
                ->where('track_play_events.played_at', '>=', $since);
        }

        return $query
            ->groupBy(['artists.id', 'artists.name'])
            ->orderByDesc('play_count')
            ->orderByDesc('last_played_at')
            ->limit($limit)
            ->get()
            ->map(fn (Artist $artist): array => [
                'id' => $artist->id,
                'name' => $artist->name,
                'playCount' => (int) $artist->play_count,
                'lastPlayedAt' => $artist->last_played_at
                    ? Carbon::parse($artist->last_played_at)->toJSON()
                    : null,
                'path' => '/artists/'.$artist->id,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function topGenres(?int $libraryRootId, ?Carbon $since, int $limit): array
    {
        $query = Genre::query()
            ->join('genre_track', 'genre_track.genre_id', '=', 'genres.id')
            ->join('tracks', 'tracks.id', '=', 'genre_track.track_id')
            ->join('media_files', 'media_files.id', '=', 'tracks.media_file_id')
            ->join('library_roots', 'library_roots.id', '=', 'media_files.library_root_id')
            ->where('media_files.status', MediaFileStatus::Available->value)
            ->where('library_roots.enabled', true)
            ->when(
                $libraryRootId,
                fn (Builder $query, int $id) => $query->where('library_roots.id', $id),
            )
            ->select(['genres.id', 'genres.name']);
        if ($since === null) {
            $query
                ->join('track_play_statistics', 'track_play_statistics.track_id', '=', 'tracks.id')
                ->selectRaw('sum(track_play_statistics.play_count) as play_count')
                ->selectRaw('max(track_play_statistics.last_played_at) as last_played_at')
                ->where('track_play_statistics.play_count', '>', 0);
        } else {
            $query
                ->join('track_play_events', 'track_play_events.track_id', '=', 'tracks.id')
                ->selectRaw('count(track_play_events.id) as play_count')
                ->selectRaw('max(track_play_events.played_at) as last_played_at')
                ->where('track_play_events.counted', true)
                ->where('track_play_events.played_at', '>=', $since);
        }

        return $query
            ->groupBy(['genres.id', 'genres.name'])
            ->orderByDesc('play_count')
            ->orderByDesc('last_played_at')
            ->limit($limit)
            ->get()
            ->map(fn (Genre $genre): array => [
                'id' => $genre->id,
                'name' => $genre->name,
                'playCount' => (int) $genre->play_count,
                'lastPlayedAt' => $genre->last_played_at
                    ? Carbon::parse($genre->last_played_at)->toJSON()
                    : null,
                'path' => '/albums?genre='.$genre->id,
            ])
            ->all();
    }

    /** @param array<string, mixed> $arguments */
    private function recentHistory(array $arguments, ?int $libraryRootId): array
    {
        $validated = $this->validated($arguments, ['limit'], [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);
        $limit = (int) ($validated['limit'] ?? 5);
        $results = $this->events($libraryRootId)
            ->with(['track:id,title,album_id', 'track.album:id,title', 'track.artists:id,name'])
            ->orderByDesc('played_at')
            ->orderByDesc('track_play_events.id')
            ->limit($limit)
            ->get(['track_play_events.id', 'track_play_events.track_id', 'track_play_events.played_at'])
            ->map(fn (TrackPlayEvent $event): array => [
                'eventId' => $event->id,
                'playedAt' => $event->played_at?->toJSON(),
                'id' => $event->track?->id,
                'title' => $event->track?->title,
                'artist' => $event->track?->artists->first()?->name,
                'artists' => $event->track?->artists->pluck('name')->values()->all() ?? [],
                'album' => $event->track?->album?->title,
                'albumId' => $event->track?->album?->id,
                'path' => $event->track ? '/tracks/'.$event->track->id : null,
            ])
            ->all();

        return [
            'scope' => $this->scope($libraryRootId),
            'basis' => 'counted_play_events',
            'results' => $results,
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function unplayedAlbums(array $arguments, ?int $libraryRootId): array
    {
        $validated = $this->validated($arguments, ['artist_name', 'query', 'limit'], [
            'artist_name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'query' => ['sometimes', 'string', 'min:1', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);
        $query = $this->libraryRootScope
            ->albums(Album::query(), $libraryRootId)
            ->has('tracks')
            ->whereDoesntHave(
                'tracks.playStatistic',
                fn (Builder $statistics) => $statistics->where('play_count', '>', 0),
            )
            ->with('primaryArtist:id,name')
            ->withCount('tracks');
        if (isset($validated['artist_name'])) {
            $query->whereHas(
                'primaryArtist',
                fn (Builder $artists) => $artists->whereRaw(
                    'lower(artists.name) = lower(?)',
                    [trim($validated['artist_name'])],
                ),
            );
        }
        if (isset($validated['query'])) {
            $query->where('albums.title', 'ilike', '%'.$this->escapeLike($validated['query']).'%');
        }

        $results = $query
            ->orderByRaw('(select coalesce(artists.sort_name, artists.name) from artists where artists.id = albums.primary_artist_id)')
            ->orderByRaw('coalesce(albums.sort_title, albums.title)')
            ->limit((int) ($validated['limit'] ?? 5))
            ->get()
            ->map(fn (Album $album): array => [
                'id' => $album->id,
                'title' => $album->title,
                'artist' => $album->primaryArtist?->name,
                'year' => $album->original_release_year,
                'trackCount' => (int) $album->tracks_count,
                'path' => '/albums/'.$album->id,
            ])
            ->all();

        return [
            'scope' => $this->scope($libraryRootId),
            'basis' => 'aggregate_play_count_is_zero',
            'results' => $results,
        ];
    }

    private function events(?int $libraryRootId, ?Carbon $since = null): Builder
    {
        return TrackPlayEvent::query()
            ->where('counted', true)
            ->when($since, fn (Builder $events) => $events->where('played_at', '>=', $since))
            ->whereHas('track', fn (Builder $tracks) => $this->libraryRootScope->tracks(
                $tracks,
                $libraryRootId,
            ));
    }

    private function periodStart(string $period): ?Carbon
    {
        return match ($period) {
            'all_time' => null,
            'today' => now()->startOfDay(),
            'current_week' => now()->startOfWeek(),
            'current_month' => now()->startOfMonth(),
            'current_year' => now()->startOfYear(),
            '7_days' => now()->subDays(7),
            '30_days' => now()->subDays(30),
            '90_days' => now()->subDays(90),
            '365_days' => now()->subDays(365),
        };
    }

    /** @return array{key: string, since: ?string, basis: string} */
    private function periodPayload(string $period, ?Carbon $since): array
    {
        return [
            'key' => $period,
            'since' => $since?->toJSON(),
            'basis' => $since === null ? 'aggregate_play_statistics' : 'counted_play_events',
        ];
    }

    /** @return array{id: ?int, name: string} */
    private function scope(?int $libraryRootId): array
    {
        if ($libraryRootId === null) {
            return ['id' => null, 'name' => 'All library roots'];
        }

        return [
            'id' => $libraryRootId,
            'name' => LibraryRoot::query()->find($libraryRootId)?->name ?? 'Unknown library root',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $allowed
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validated(array $arguments, array $allowed, array $rules): array
    {
        if (array_diff(array_keys($arguments), $allowed) !== []) {
            throw new CollectionAssistantToolException('invalid_arguments');
        }

        $validator = Validator::make($arguments, $rules);
        if ($validator->fails()) {
            throw new CollectionAssistantToolException('invalid_arguments');
        }

        return $validator->validated();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function definition(
        string $name,
        string $description,
        array $properties,
        array $required = [],
    ): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties === [] ? (object) [] : $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
