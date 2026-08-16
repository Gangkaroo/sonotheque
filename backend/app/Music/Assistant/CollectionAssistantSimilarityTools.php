<?php

namespace App\Music\Assistant;

use App\Models\ApplicationSetting;
use App\Models\LibraryRoot;
use App\Models\Track;
use App\Music\Intelligence\AudioAnalysisProfileSelector;
use App\Music\Intelligence\AudioAnalysisRunPlanner;
use App\Music\Intelligence\AudioSimilarityEvaluator;
use App\Support\LibraryRootScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;

class CollectionAssistantSimilarityTools
{
    public function __construct(
        private readonly AudioSimilarityEvaluator $evaluator,
        private readonly LibraryRootScope $libraryRootScope,
        private readonly AudioAnalysisProfileSelector $profileSelector,
        private readonly AudioAnalysisRunPlanner $runPlanner,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        return [[
            'type' => 'function',
            'function' => [
                'name' => 'find_similar_tracks',
                'description' => 'Find analyzed tracks that sound similar to one named reference track in the active library-root scope.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'The title of the reference track.',
                            'minLength' => 1,
                            'maxLength' => 255,
                        ],
                        'artist_name' => [
                            'type' => 'string',
                            'description' => 'Optional exact artist name used to identify the reference track.',
                            'minLength' => 1,
                            'maxLength' => 255,
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of similar tracks.',
                            'minimum' => 1,
                            'maximum' => 10,
                            'default' => 5,
                        ],
                        'exclude_same_album' => [
                            'type' => 'boolean',
                            'description' => 'Exclude tracks from the reference album. Defaults to true.',
                            'default' => true,
                        ],
                        'exclude_same_artist' => [
                            'type' => 'boolean',
                            'description' => 'Exclude tracks by the reference artist. Defaults to true.',
                            'default' => true,
                        ],
                    ],
                    'required' => ['title'],
                    'additionalProperties' => false,
                ],
            ],
        ]];
    }

    public function supports(string $name): bool
    {
        return $name === 'find_similar_tracks';
    }

    /** @param array<string, mixed> $arguments */
    public function execute(array $arguments, ?int $libraryRootId): array
    {
        if (array_diff(array_keys($arguments), [
            'title',
            'artist_name',
            'limit',
            'exclude_same_album',
            'exclude_same_artist',
        ]) !== []) {
            throw new CollectionAssistantToolException('invalid_arguments');
        }

        $validator = Validator::make($arguments, [
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'artist_name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'exclude_same_album' => ['sometimes', 'boolean'],
            'exclude_same_artist' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            throw new CollectionAssistantToolException('invalid_arguments');
        }

        $validated = $validator->validated();
        $title = trim($validated['title']);
        $artistName = isset($validated['artist_name'])
            ? trim($validated['artist_name'])
            : null;
        $references = $this->referenceTracks($title, $artistName, $libraryRootId);

        if ($references->isEmpty()) {
            return [
                'status' => 'reference_not_found',
                'scope' => $this->scope($libraryRootId),
                'title' => $title,
                'artistName' => $artistName,
            ];
        }

        if ($references->count() > 1) {
            return [
                'status' => 'ambiguous_reference',
                'scope' => $this->scope($libraryRootId),
                'message' => 'More than one track matches. Ask the user to identify the intended reference.',
                'candidates' => $references->map($this->trackPayload(...))->all(),
            ];
        }

        $settings = ApplicationSetting::current();
        if (! $settings->audio_intelligence_enabled) {
            return [
                'status' => 'audio_intelligence_disabled',
                'scope' => $this->scope($libraryRootId),
                'reference' => $this->trackPayload($references->first()),
            ];
        }

        $result = $this->evaluator->evaluate(
            $references->first()->id,
            (int) ($validated['limit'] ?? 5),
            (bool) ($validated['exclude_same_album'] ?? true),
            (bool) ($validated['exclude_same_artist'] ?? true),
            $settings->audioSimilarityReranking(),
            $settings->audio_similarity_personalization_enabled,
            $libraryRootId,
        );

        if ($result === null) {
            return [
                'status' => 'reference_not_analyzed',
                'scope' => $this->scope($libraryRootId),
                'reference' => $this->trackPayload($references->first()),
            ];
        }

        $totalTrackCount = $this->libraryRootScope
            ->tracks(Track::query(), $libraryRootId)
            ->count();
        $profile = $this->profileSelector->current();
        $analyzedTrackCount = $profile === null
            ? 0
            : $this->runPlanner->analyzedTrackCount($profile, $libraryRootId);

        return [
            'status' => 'ok',
            'scope' => $this->scope($libraryRootId),
            'basis' => [
                'rankingMethod' => data_get($result, 'ranking.method'),
                'candidateCount' => (int) $result['candidateCount'],
                'calculationMs' => (float) $result['calculationMs'],
                'interpretation' => 'Scores rank analyzed audio similarity within this collection scope; they are not probabilities.',
            ],
            'coverage' => [
                'analyzedTrackCount' => $analyzedTrackCount,
                'totalTrackCount' => $totalTrackCount,
                'percentage' => $totalTrackCount === 0
                    ? 0.0
                    : round(($analyzedTrackCount / $totalTrackCount) * 100, 1),
            ],
            'reference' => $this->similarityTrackPayload($result['source']),
            'results' => collect($result['matches'])
                ->map(fn (array $track): array => $this->similarityTrackPayload($track))
                ->all(),
        ];
    }

    /** @return Collection<int, Track> */
    private function referenceTracks(
        string $title,
        ?string $artistName,
        ?int $libraryRootId,
    ): Collection {
        $query = $this->libraryRootScope
            ->tracks(Track::query(), $libraryRootId)
            ->whereRaw('lower(tracks.title) = lower(?)', [$title])
            ->with([
                'album:id,title,primary_artist_id',
                'album.primaryArtist:id,name',
                'artists:id,name',
            ]);
        if ($artistName !== null) {
            $query->where(fn (Builder $query) => $query
                ->whereHas('artists', fn (Builder $artists) => $artists
                    ->whereRaw('lower(artists.name) = lower(?)', [$artistName]))
                ->orWhereHas('album.primaryArtist', fn (Builder $artists) => $artists
                    ->whereRaw('lower(artists.name) = lower(?)', [$artistName])));
        }

        return $query->orderBy('tracks.id')->limit(6)->get();
    }

    /** @return array<string, mixed> */
    private function trackPayload(Track $track): array
    {
        return [
            'id' => $track->id,
            'title' => $track->title,
            'artist' => $track->artists->pluck('name')->join(', ')
                ?: $track->album?->primaryArtist?->name,
            'album' => $track->album?->title,
            'albumId' => $track->album?->id,
            'path' => '/tracks/'.$track->id,
        ];
    }

    /** @param array<string, mixed> $track */
    private function similarityTrackPayload(array $track): array
    {
        return [
            'id' => $track['id'],
            'title' => $track['title'],
            'artist' => $track['artistName'] ?? null,
            'album' => $track['albumTitle'] ?? null,
            'albumId' => $track['albumId'] ?? null,
            'libraryRoot' => $track['libraryRootName'] ?? null,
            'similarity' => isset($track['similarity']) ? (float) $track['similarity'] : null,
            'rankingScore' => isset($track['rankingScore']) ? (float) $track['rankingScore'] : null,
            'featureCompatibility' => $track['featureCompatibility'] ?? null,
            'features' => $track['features'] ?? null,
            'path' => '/tracks/'.$track['id'],
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
}
