<?php

namespace App\Music\Assistant;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\LibraryRoot;
use App\Models\Track;
use App\Support\CollectionMetrics;
use App\Support\LibraryRootScope;
use App\Support\MusicianCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CollectionAssistantToolRegistry
{
    /** @var list<string> */
    private const ENTITY_TYPES = ['artists', 'albums', 'tracks', 'genres', 'musicians'];

    public function __construct(
        private readonly CollectionMetrics $metrics,
        private readonly LibraryRootScope $libraryRootScope,
        private readonly MusicianCatalog $musicians,
        private readonly CollectionAssistantListeningTools $listeningTools,
        private readonly CollectionAssistantSimilarityTools $similarityTools,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        return [
            $this->definition(
                'collection_summary',
                'Return selected catalog and playback counts for the active Sonotheque library-root scope.',
                [
                    'metrics' => [
                        'type' => 'array',
                        'description' => 'Only the counts needed to answer the question.',
                        'items' => ['type' => 'string', 'enum' => CollectionMetrics::METRICS],
                        'minItems' => 1,
                        'maxItems' => count(CollectionMetrics::METRICS),
                        'uniqueItems' => true,
                    ],
                ],
                ['metrics'],
            ),
            $this->definition(
                'search_catalog',
                'Search Sonotheque artists, albums, tracks, genres, or musicians by word beginnings.',
                [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Words or word beginnings to search for.',
                        'minLength' => 1,
                        'maxLength' => 100,
                    ],
                    'entity_types' => [
                        'type' => 'array',
                        'description' => 'Catalog sections to search. Omit to search every supported section.',
                        'items' => ['type' => 'string', 'enum' => self::ENTITY_TYPES],
                        'minItems' => 1,
                        'maxItems' => count(self::ENTITY_TYPES),
                        'uniqueItems' => true,
                    ],
                    'artist_name' => [
                        'type' => 'string',
                        'description' => 'Exact artist name constraint for album or track results.',
                        'minLength' => 1,
                        'maxLength' => 255,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum results per catalog section.',
                        'minimum' => 1,
                        'maximum' => 10,
                        'default' => 5,
                    ],
                ],
                ['query'],
            ),
            $this->definition(
                'search_albums_by_artist',
                'Find albums by one exact artist name. Use this for questions asking for albums by an artist.',
                [
                    'artist_name' => [
                        'type' => 'string',
                        'description' => 'Exact album artist name.',
                        'minLength' => 1,
                        'maxLength' => 255,
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Optional album-title words or word beginnings.',
                        'minLength' => 1,
                        'maxLength' => 100,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of albums.',
                        'minimum' => 1,
                        'maximum' => 10,
                        'default' => 5,
                    ],
                ],
                ['artist_name'],
            ),
            ...$this->listeningTools->definitions(),
            ...$this->similarityTools->definitions(),
        ];
    }

    /** @param array<string, mixed> $arguments */
    public function execute(string $name, array $arguments, ?int $libraryRootId): array
    {
        return match ($name) {
            'collection_summary' => $this->summary($arguments, $libraryRootId),
            'search_catalog' => $this->search($arguments, $libraryRootId),
            'search_albums_by_artist' => $this->artistAlbums($arguments, $libraryRootId),
            default => $this->executeExtension($name, $arguments, $libraryRootId),
        };
    }

    /** @param array<string, mixed> $arguments */
    private function executeExtension(
        string $name,
        array $arguments,
        ?int $libraryRootId,
    ): array {
        if ($this->listeningTools->supports($name)) {
            return $this->listeningTools->execute($name, $arguments, $libraryRootId);
        }

        if ($this->similarityTools->supports($name)) {
            return $this->similarityTools->execute($arguments, $libraryRootId);
        }

        throw new CollectionAssistantToolException('unknown_tool');
    }

    /** @param array<string, mixed> $arguments */
    private function summary(array $arguments, ?int $libraryRootId): array
    {
        $this->rejectUnknownArguments($arguments, ['metrics']);
        $validator = Validator::make($arguments, [
            'metrics' => ['required', 'array', 'min:1', 'max:'.count(CollectionMetrics::METRICS)],
            'metrics.*' => ['string', 'distinct', Rule::in(CollectionMetrics::METRICS)],
        ]);
        if ($validator->fails()) {
            throw new CollectionAssistantToolException('invalid_arguments');
        }
        $metrics = $validator->validated()['metrics'];

        return [
            'scope' => $this->scope($libraryRootId),
            'counts' => $this->metrics->forLibraryRoot($libraryRootId, $metrics),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function search(array $arguments, ?int $libraryRootId): array
    {
        $this->rejectUnknownArguments($arguments, ['query', 'entity_types', 'artist_name', 'limit']);
        $validator = Validator::make($arguments, [
            'query' => ['required', 'string', 'min:1', 'max:100'],
            'entity_types' => ['sometimes', 'array', 'min:1', 'max:'.count(self::ENTITY_TYPES)],
            'entity_types.*' => ['string', 'distinct', Rule::in(self::ENTITY_TYPES)],
            'artist_name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);
        if ($validator->fails()) {
            throw new CollectionAssistantToolException('invalid_arguments');
        }

        $validated = $validator->validated();
        $terms = $this->searchTerms($validated['query']);
        if ($terms === []) {
            throw new CollectionAssistantToolException('invalid_arguments');
        }
        $entityTypes = $validated['entity_types'] ?? self::ENTITY_TYPES;
        $artistName = isset($validated['artist_name']) ? trim($validated['artist_name']) : null;
        $limit = (int) ($validated['limit'] ?? 5);
        $results = [];

        foreach ($entityTypes as $entityType) {
            $results[$entityType] = match ($entityType) {
                'artists' => $this->artists($terms, $libraryRootId, $limit),
                'albums' => $this->albums($terms, $libraryRootId, $limit, $artistName),
                'tracks' => $this->tracks($terms, $libraryRootId, $limit, $artistName),
                'genres' => $this->genres($terms, $libraryRootId, $limit),
                'musicians' => $this->musicianResults($terms, $libraryRootId, $limit),
            };
        }

        return [
            'scope' => $this->scope($libraryRootId),
            'query' => $validated['query'],
            'results' => $results,
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function artistAlbums(array $arguments, ?int $libraryRootId): array
    {
        $this->rejectUnknownArguments($arguments, ['artist_name', 'query', 'limit']);
        $validator = Validator::make($arguments, [
            'artist_name' => ['required', 'string', 'min:1', 'max:255'],
            'query' => ['sometimes', 'string', 'min:1', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);
        if ($validator->fails()) {
            throw new CollectionAssistantToolException('invalid_arguments');
        }
        $validated = $validator->validated();
        $artistName = trim($validated['artist_name']);
        $terms = isset($validated['query'])
            ? $this->searchTerms($validated['query'])
            : $this->searchTerms($artistName);

        return [
            'scope' => $this->scope($libraryRootId),
            'artistName' => $artistName,
            'results' => $this->albums(
                $terms,
                $libraryRootId,
                (int) ($validated['limit'] ?? 5),
                $artistName,
            ),
        ];
    }

    /** @param list<string> $terms */
    private function artists(array $terms, ?int $libraryRootId, int $limit): array
    {
        $query = Artist::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('albums', fn (Builder $albums) => $this->libraryRootScope->albums($albums, $libraryRootId))
                ->orWhereHas('tracks', fn (Builder $tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId)));
        $this->applyPrefixTerms($query, 'artists.name', $terms);

        return $query
            ->orderByRaw('coalesce(artists.sort_name, artists.name)')
            ->limit($limit)
            ->get(['artists.id', 'artists.name'])
            ->map(fn (Artist $artist): array => [
                'id' => $artist->id,
                'name' => $artist->name,
                'path' => '/artists/'.$artist->id,
            ])
            ->all();
    }

    /** @param list<string> $terms */
    private function albums(
        array $terms,
        ?int $libraryRootId,
        int $limit,
        ?string $artistName,
    ): array {
        $query = $this->libraryRootScope
            ->albums(Album::query(), $libraryRootId)
            ->has('tracks')
            ->with(['primaryArtist:id,name', 'libraryRoot:id,name'])
            ->withCount('tracks');
        if ($artistName !== null) {
            $query->whereHas(
                'primaryArtist',
                fn (Builder $artists) => $artists->whereRaw('lower(artists.name) = lower(?)', [$artistName]),
            );
        }
        foreach ($terms as $term) {
            $pattern = '%'.$this->escapeLike($term).'%';
            $query->where(fn (Builder $query) => $query
                ->where('albums.title', 'ilike', $pattern)
                ->orWhereHas('primaryArtist', fn (Builder $artists) => $artists->where('name', 'ilike', $pattern)));
        }

        return $query
            ->orderByRaw('coalesce(albums.sort_title, albums.title)')
            ->limit($limit)
            ->get()
            ->map(fn (Album $album): array => [
                'id' => $album->id,
                'title' => $album->title,
                'artist' => $album->primaryArtist?->name,
                'year' => $album->original_release_year,
                'trackCount' => (int) $album->tracks_count,
                'libraryRoot' => $album->libraryRoot?->name,
                'path' => '/albums/'.$album->id,
            ])
            ->all();
    }

    /** @param list<string> $terms */
    private function tracks(
        array $terms,
        ?int $libraryRootId,
        int $limit,
        ?string $artistName,
    ): array {
        $query = $this->libraryRootScope
            ->tracks(Track::query(), $libraryRootId)
            ->with([
                'album:id,title,original_release_year,primary_artist_id',
                'album.primaryArtist:id,name',
                'artists:id,name',
                'playStatistic:track_id,play_count',
            ]);
        if ($artistName !== null) {
            $query->whereHas(
                'artists',
                fn (Builder $artists) => $artists->whereRaw('lower(artists.name) = lower(?)', [$artistName]),
            );
        }
        foreach ($terms as $term) {
            $query->where(fn (Builder $query) => $query
                ->whereRaw(
                    "to_tsvector('simple', coalesce(tracks.title, '')) @@ to_tsquery('simple', quote_literal(?) || ':*')",
                    [$term],
                )
                ->orWhereHas('artists', fn (Builder $artists) => $this->applyPrefixTerms(
                    $artists,
                    'artists.name',
                    [$term],
                )));
        }

        return $query
            ->orderByRaw('coalesce(tracks.sort_title, tracks.title)')
            ->limit($limit)
            ->get()
            ->map(fn (Track $track): array => [
                'id' => $track->id,
                'title' => $track->title,
                'artists' => $track->artists->pluck('name')->values()->all(),
                'album' => $track->album?->title,
                'albumId' => $track->album?->id,
                'year' => $track->year ?? $track->album?->original_release_year,
                'playCount' => (int) ($track->playStatistic?->play_count ?? 0),
                'path' => '/tracks/'.$track->id,
            ])
            ->all();
    }

    /** @param list<string> $terms */
    private function genres(array $terms, ?int $libraryRootId, int $limit): array
    {
        $query = Genre::query()
            ->whereHas('tracks', fn (Builder $tracks) => $this->libraryRootScope->tracks($tracks, $libraryRootId));
        foreach ($terms as $term) {
            $query->where('genres.name', 'ilike', '%'.$this->escapeLike($term).'%');
        }

        return $query
            ->orderBy('genres.name')
            ->limit($limit)
            ->get(['genres.id', 'genres.name'])
            ->map(fn (Genre $genre): array => [
                'id' => $genre->id,
                'name' => $genre->name,
                'path' => '/albums?genre='.$genre->id,
            ])
            ->all();
    }

    /** @param list<string> $terms */
    private function musicianResults(array $terms, ?int $libraryRootId, int $limit): array
    {
        $query = $this->musicians->query($libraryRootId);
        foreach ($terms as $term) {
            $query->where('musicians.name', 'ilike', '%'.$this->escapeLike($term).'%');
        }

        return $query
            ->orderBy('musicians.name')
            ->limit($limit)
            ->get()
            ->map(fn ($musician): array => [
                'id' => $musician->id,
                'name' => $musician->name,
                'albumCount' => (int) $musician->album_count,
                'trackCount' => (int) $musician->track_count,
                'path' => '/musicians/'.$musician->id,
            ])
            ->all();
    }

    /** @param list<string> $terms */
    private function applyPrefixTerms(Builder $query, string $column, array $terms): Builder
    {
        foreach ($terms as $term) {
            $query->whereRaw(
                "to_tsvector('simple', coalesce({$column}, '')) @@ to_tsquery('simple', quote_literal(?) || ':*')",
                [$term],
            );
        }

        return $query;
    }

    /** @return array{id: ?int, name: string} */
    private function scope(?int $libraryRootId): array
    {
        if ($libraryRootId === null) {
            return ['id' => null, 'name' => 'All library roots'];
        }

        $root = LibraryRoot::query()->find($libraryRootId);

        return ['id' => $libraryRootId, 'name' => $root?->name ?? 'Unknown library root'];
    }

    /** @return list<string> */
    private function searchTerms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($query), $matches);

        return array_slice(array_values(array_unique($matches[0] ?? [])), 0, 8);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }

    /** @param list<string> $allowed */
    private function rejectUnknownArguments(array $arguments, array $allowed): void
    {
        if (array_diff(array_keys($arguments), $allowed) !== []) {
            throw new CollectionAssistantToolException('invalid_arguments');
        }
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
