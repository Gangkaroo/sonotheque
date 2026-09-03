<?php

namespace App\Music\Catalog;

use App\Models\Album;
use App\Models\AlbumRecordLabel;
use App\Models\ApplicationSetting;
use App\Music\Discogs\DiscogsApiClient;
use App\Music\Discogs\DiscogsApiException;
use App\Music\Enrichment\AlbumMusicianCreditManager;
use App\Music\Enrichment\MusicBrainzTagIdentifierReader;
use App\Music\Enrichment\OnlineEnrichmentManager;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class RecordLabelSuggestionService
{
    public function __construct(
        private readonly RecordLabelNormalizer $normalizer,
        private readonly MusicBrainzTagIdentifierReader $musicBrainzTags,
        private readonly OnlineEnrichmentManager $enrichment,
        private readonly DiscogsApiClient $discogs,
        private readonly RecordLabelImporter $importer,
        private readonly AlbumMusicianCreditManager $musicians,
    ) {
    }

    /** @return array<string, mixed> */
    public function forAlbum(Album $album): array
    {
        $album->loadMissing([
            'musicianEnrichment',
            'recordLabelAssignments.recordLabel',
            'tracks.mediaFile',
            'ownedCopies',
        ]);
        $current = $album->recordLabelAssignments
            ->where('source', RecordLabelImporter::FILE_TAG_SOURCE)
            ->map(fn (AlbumRecordLabel $assignment): array => [
                'name' => $assignment->recordLabel->name,
                'catalogNumber' => $assignment->catalog_number,
            ])
            ->unique(fn (array $label): string => $this->identity($label))
            ->values()
            ->all();
        $suggestions = [];
        $errors = [];

        array_push($suggestions, ...$this->musicBrainzSuggestions($album, $current));

        [$discogsSuggestions, $discogsErrors] = $this->discogsSuggestions($album, $current);
        array_push($suggestions, ...$discogsSuggestions);
        array_push($errors, ...$discogsErrors);

        return [
            'current' => $current,
            'suggestions' => $suggestions,
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    public function confirmSource(Album $album, string $provider, string $sourceReference): array
    {
        $payload = $this->forAlbum($album);
        $suggestion = collect($payload['suggestions'])->first(
            fn (array $candidate): bool => $candidate['provider'] === $provider
                && $candidate['sourceReference'] === $sourceReference,
        );

        if (! is_array($suggestion) || ! $suggestion['matchesCurrent']) {
            throw ValidationException::withMessages([
                'suggestion' => 'The provider suggestion is unavailable or no longer matches the embedded labels.',
            ]);
        }

        $this->importer->confirmProviderAssignments(
            $album,
            array_map(fn (array $recordLabel): array => [
                ...$recordLabel,
                'source' => $provider,
                'sourceReference' => $sourceReference,
            ], $suggestion['recordLabels']),
        );
        $musicianReleaseResolved = $this->resolveMusicianRelease($album, $suggestion);
        $album->unsetRelation('recordLabelAssignments');

        return [
            ...$this->forAlbum($album),
            'musicianReleaseResolved' => $musicianReleaseResolved,
        ];
    }

    /** @return array<string, mixed> */
    public function selectSource(Album $album, string $provider, string $sourceReference): array
    {
        $payload = $this->forAlbum($album);
        $suggestion = collect($payload['suggestions'])->first(
            fn (array $candidate): bool => $candidate['provider'] === $provider
                && $candidate['sourceReference'] === $sourceReference,
        );
        if (! is_array($suggestion)) {
            throw ValidationException::withMessages([
                'suggestion' => 'The provider suggestion is unavailable.',
            ]);
        }

        return [
            ...$payload,
            'musicianReleaseResolved' => $this->resolveMusicianRelease($album, $suggestion),
        ];
    }

    /** @param array<string, mixed> $suggestion */
    private function resolveMusicianRelease(Album $album, array $suggestion): bool
    {
        return $suggestion['provider'] === 'musicbrainz'
            && $this->musicians->resolveReleaseIfCandidate($album, $suggestion['sourceReference']);
    }

    /** @param list<array{name: string, catalogNumber: ?string}> $current */
    /**
     * @param  list<array{name: string, catalogNumber: ?string}>  $current
     * @return list<array<string, mixed>>
     */
    private function musicBrainzSuggestions(Album $album, array $current): array
    {
        $candidates = $this->musicBrainzReleaseCandidates($album);

        return $candidates
            ->map(function (array $candidate) use ($album, $current): ?array {
                $releaseId = $candidate['id'];
                $result = $this->enrichment->albumIdentityForRelease($album, $releaseId);
                $data = is_array($result['data'] ?? null) ? $result['data'] : null;
                if (($result['status'] ?? null) !== 'ready'
                    || ! is_string($data['providerReference'] ?? null)) {
                    return null;
                }

                $labels = $this->labels($data['recordLabels'] ?? []);
                if ($labels === []) {
                    return null;
                }

                return $this->suggestion(
                    $album,
                    'musicbrainz',
                    'MusicBrainz',
                    $data['providerReference'],
                    is_string($data['attribution']['sourceUrl'] ?? null)
                        ? $data['attribution']['sourceUrl']
                        : null,
                    $labels,
                    $current,
                    $this->musicBrainzCandidateDescription($candidate),
                    $candidate['trackCount'],
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return Collection<int, array{id: string, date: ?string, country: ?string, formats: list<string>, trackCount: ?int}> */
    private function musicBrainzReleaseCandidates(Album $album): Collection
    {
        $tagged = $album->tracks
            ->map(function ($track): ?array {
                $releaseId = $this->musicBrainzTags
                    ->read($track->mediaFile?->raw_metadata ?? [])['release'] ?? null;

                return is_string($releaseId) && $releaseId !== ''
                    ? [
                        'id' => $releaseId,
                        'date' => null,
                        'country' => null,
                        'formats' => [],
                        'trackCount' => null,
                    ]
                    : null;
            })
            ->filter();
        $enrichment = $album->musicianEnrichment;
        $resolved = collect([
            $enrichment?->selected_release_id,
            $enrichment?->provider_release_id,
        ])
            ->filter(fn (mixed $releaseId): bool => is_string($releaseId) && $releaseId !== '')
            ->map(fn (string $releaseId): array => [
                'id' => $releaseId,
                'date' => null,
                'country' => null,
                'formats' => [],
                'trackCount' => null,
            ]);
        $ambiguous = collect($enrichment?->candidate_releases ?? [])
            ->filter(fn (mixed $candidate): bool => is_array($candidate)
                && is_string($candidate['id'] ?? null)
                && $candidate['id'] !== '')
            ->map(fn (array $candidate): array => [
                'id' => $candidate['id'],
                'date' => is_string($candidate['date'] ?? null) ? $candidate['date'] : null,
                'country' => is_string($candidate['country'] ?? null) ? $candidate['country'] : null,
                'formats' => collect($candidate['formats'] ?? [])
                    ->filter(fn (mixed $format): bool => is_string($format) && $format !== '')
                    ->values()
                    ->all(),
                'trackCount' => is_numeric($candidate['trackCount'] ?? null)
                    ? (int) $candidate['trackCount']
                    : null,
            ]);

        return $tagged
            ->concat($resolved)
            ->concat($ambiguous)
            ->unique('id')
            ->take(5)
            ->values();
    }

    /** @param array{id: string, date: ?string, country: ?string, formats: list<string>} $candidate */
    private function musicBrainzCandidateDescription(array $candidate): ?string
    {
        $parts = array_filter([
            $candidate['date'],
            $candidate['country'],
            $candidate['formats'] === [] ? null : implode(', ', $candidate['formats']),
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param  list<array{name: string, catalogNumber: ?string}>  $current
     * @return array{list<array<string, mixed>>, list<array{provider: string, message: string}>}
     */
    private function discogsSuggestions(Album $album, array $current): array
    {
        $settings = ApplicationSetting::current();
        if (! $settings->hasDiscogsConnection()) {
            return [[], []];
        }

        $suggestions = [];
        $errors = [];
        $releaseIds = $album->ownedCopies
            ->where('provider', 'discogs')
            ->pluck('external_release_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($releaseIds as $releaseId) {
            try {
                $release = $this->discogs->release($settings->discogs_personal_access_token, $releaseId);
            } catch (DiscogsApiException $exception) {
                $errors[] = ['provider' => 'discogs', 'message' => $exception->getMessage()];

                continue;
            }

            $labels = $this->labels($release['recordLabels'] ?? []);
            if ($labels === []) {
                continue;
            }
            $suggestions[] = $this->suggestion(
                $album,
                'discogs',
                'Discogs',
                (string) $releaseId,
                is_string($release['webUrl'] ?? null) ? $release['webUrl'] : null,
                $labels,
                $current,
            );
        }

        return [$suggestions, $errors];
    }

    /**
     * @param  list<array{name: string, catalogNumber: ?string}>  $labels
     * @param  list<array{name: string, catalogNumber: ?string}>  $current
     * @return array<string, mixed>
     */
    private function suggestion(
        Album $album,
        string $provider,
        string $providerLabel,
        string $sourceReference,
        ?string $sourceUrl,
        array $labels,
        array $current,
        ?string $sourceDescription = null,
        ?int $sourceTrackCount = null,
    ): array {
        return [
            'provider' => $provider,
            'providerLabel' => $providerLabel,
            'sourceReference' => $sourceReference,
            'sourceUrl' => $sourceUrl,
            'sourceDescription' => $sourceDescription,
            'sourceTrackCount' => $sourceTrackCount,
            'recordLabels' => $labels,
            'matchesCurrent' => $this->identities($labels) === $this->identities($current),
            'sourceConfirmed' => $this->sourceConfirmed(
                $album,
                $provider,
                $sourceReference,
                $labels,
            ),
        ];
    }

    /** @param list<array{name: string, catalogNumber: ?string}> $labels */
    private function sourceConfirmed(
        Album $album,
        string $provider,
        string $sourceReference,
        array $labels,
    ): bool {
        $confirmed = $album->recordLabelAssignments
            ->where('source', $provider)
            ->where('source_reference', $sourceReference)
            ->map(fn (AlbumRecordLabel $assignment): array => [
                'name' => $assignment->recordLabel->name,
                'catalogNumber' => $assignment->catalog_number,
            ])
            ->values()
            ->all();

        return $this->identities($confirmed) === $this->identities($labels);
    }

    /** @return list<array{name: string, catalogNumber: ?string}> */
    private function labels(mixed $labels): array
    {
        if (! is_array($labels)) {
            return [];
        }

        return collect($labels)
            ->filter(fn (mixed $label): bool => is_array($label) && is_string($label['name'] ?? null))
            ->map(fn (array $label): array => [
                'name' => $this->normalizer->displayName($label['name']),
                'catalogNumber' => $this->normalizer->catalogNumber($label['catalogNumber'] ?? null),
            ])
            ->filter(fn (array $label): bool => $label['name'] !== '')
            ->unique(fn (array $label): string => $this->identity($label))
            ->values()
            ->all();
    }

    /** @param list<array{name: string, catalogNumber: ?string}> $labels */
    private function identities(array $labels): array
    {
        $identities = array_map(fn (array $label): string => $this->identity($label), $labels);
        sort($identities);

        return array_values(array_unique($identities));
    }

    /** @param array{name: string, catalogNumber: ?string} $label */
    private function identity(array $label): string
    {
        return $this->normalizer->normalizedName($label['name'])
            .'|'.$this->normalizer->catalogNumberHash($label['catalogNumber']);
    }
}
