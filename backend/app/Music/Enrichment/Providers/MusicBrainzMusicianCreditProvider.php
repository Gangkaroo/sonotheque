<?php

namespace App\Music\Enrichment\Providers;

use App\Models\Album;
use App\Models\Track;
use App\Music\Enrichment\AmbiguousMusicBrainzReleaseException;
use App\Music\Enrichment\Data\MusicianCredit;
use App\Music\Enrichment\Data\MusicianCreditCollection;
use App\Music\Enrichment\MusicBrainzApiClient;
use App\Music\Enrichment\MusicBrainzTagIdentifierReader;
use App\Music\Enrichment\ProviderRequestGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MusicBrainzMusicianCreditProvider
{
    private const PERFORMANCE_RELATION_TYPES = [
        'chorus master',
        'concertmaster',
        'conductor',
        'instrument',
        'orchestra',
        'performer',
        'performing orchestra',
        'vocal',
    ];

    private const ROLE_MODIFIERS = ['additional', 'guest'];

    public function __construct(
        private readonly MusicBrainzApiClient $client,
        private readonly MusicBrainzTagIdentifierReader $tagIdentifiers,
        private readonly ProviderRequestGate $requestGate,
    ) {
    }

    public function fetch(Album $album, ?string $selectedReleaseId = null): ?MusicianCreditCollection
    {
        $album->loadMissing([
            'primaryArtist:id,name',
            'tracks:id,album_id,media_file_id,disc_number,track_number,title',
            'tracks.mediaFile:id,raw_metadata',
        ]);

        $releaseId = $selectedReleaseId ?? $this->releaseId($album);
        if ($releaseId === null) {
            return null;
        }

        $release = $this->requestGate->run(
            'musicbrainz',
            fn (): ?array => $this->client->lookup('release', $releaseId, [
                'artist-credits',
                'artist-rels',
                'recordings',
                'release-groups',
                'url-rels',
            ]),
        );
        if (! is_array($release)) {
            return null;
        }

        $releaseId = $this->text($release['id'] ?? null) ?? $releaseId;
        $credits = [];
        $this->appendRelations(
            $credits,
            $release['relations'] ?? null,
            null,
            'release',
            $releaseId,
        );

        $trackMaps = $this->trackMaps($album->tracks);
        foreach ($this->arrays($release['media'] ?? null) as $medium) {
            $discNumber = $this->integer($medium['position'] ?? null);
            foreach ($this->arrays($medium['tracks'] ?? null) as $releaseTrack) {
                $recording = is_array($releaseTrack['recording'] ?? null)
                    ? $releaseTrack['recording']
                    : null;
                if ($recording === null) {
                    continue;
                }

                $recordingId = $this->text($recording['id'] ?? null);
                if ($recordingId === null) {
                    continue;
                }

                $trackId = $this->localTrackId(
                    $trackMaps,
                    $recordingId,
                    $this->text($releaseTrack['id'] ?? null),
                    $discNumber,
                    $this->integer($releaseTrack['position'] ?? null),
                );
                $this->appendRelations(
                    $credits,
                    $recording['relations'] ?? null,
                    $trackId,
                    'recording',
                    $recordingId,
                );
            }
        }

        return new MusicianCreditCollection(
            $releaseId,
            $this->sourceUrl($releaseId),
            array_values($credits),
            $this->discogsReleaseIds($release['relations'] ?? null),
        );
    }

    private function releaseId(Album $album): ?string
    {
        $taggedIds = $album->tracks
            ->map(function (Track $track): ?string {
                $identifiers = $this->tagIdentifiers->read($track->mediaFile?->raw_metadata ?? []);

                return $identifiers['release'] ?? null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($taggedIds->count() > 1) {
            throw new AmbiguousMusicBrainzReleaseException(
                'The album contains several different MusicBrainz release identifiers.',
                $taggedIds
                    ->map(fn (string $releaseId): array => $this->taggedCandidate($album, $releaseId))
                    ->all(),
            );
        }
        if ($taggedIds->count() === 1) {
            return (string) $taggedIds->first();
        }

        $artistName = $album->primaryArtist?->name;
        if (! is_string($artistName) || trim($artistName) === '') {
            return null;
        }

        $matches = $this->matchingReleases(
            $album,
            $artistName,
            $this->client->phrase($album->title),
        );
        if ($matches->isEmpty()) {
            $matches = $this->matchingReleases(
                $album,
                $artistName,
                $this->client->terms($album->title),
            );
        }
        if ($matches->isEmpty()) {
            return null;
        }

        $minimumScore = max(1, (int) config('sonotheque.enrichment.musicbrainz.minimum_match_score', 95));
        $first = $matches->first();
        if (! is_array($first) || (int) ($first['score'] ?? 0) < $minimumScore) {
            return null;
        }

        $eligibleMatches = $matches
            ->filter(fn (array $release): bool => (int) ($release['score'] ?? 0) >= $minimumScore)
            ->take(10)
            ->values();
        $first = $eligibleMatches->first();
        if (! is_array($first)) {
            return null;
        }

        $second = $eligibleMatches->get(1);
        $requiredGap = max(1, (int) config('sonotheque.enrichment.musicbrainz.ambiguity_score_gap', 10));
        if (is_array($second) && ((int) ($first['score'] ?? 0) - (int) ($second['score'] ?? 0)) < $requiredGap) {
            throw new AmbiguousMusicBrainzReleaseException(
                'MusicBrainz returned several similarly ranked release matches.',
                $eligibleMatches
                    ->map(fn (array $release): array => $this->candidate($release))
                    ->filter(fn (array $release): bool => $release['id'] !== null)
                    ->values()
                    ->all(),
            );
        }

        return $this->text($first['id'] ?? null);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function matchingReleases(Album $album, string $artistName, string $titleQuery): Collection
    {
        $payload = $this->requestGate->run(
            'musicbrainz',
            fn (): array => $this->client->search(
                'release',
                'release:'.$titleQuery.' AND artist:'.$this->client->phrase($artistName),
            ),
        );

        return collect($this->arrays($payload['releases'] ?? null))
            ->filter(fn (array $release): bool => $this->normalized($release['title'] ?? null) === $this->normalized($album->title))
            ->filter(fn (array $release): bool => $this->normalized($this->artistCredit($release)) === $this->normalized($artistName))
            ->sortByDesc(fn (array $release): int => (int) ($release['score'] ?? 0))
            ->values();
    }

    /** @return array<string, mixed> */
    private function taggedCandidate(Album $album, string $releaseId): array
    {
        return [
            'id' => $releaseId,
            'title' => $album->title,
            'artistName' => $album->primaryArtist?->name,
            'date' => null,
            'country' => null,
            'status' => null,
            'formats' => [],
            'trackCount' => null,
            'barcode' => null,
            'score' => null,
            'sourceUrl' => $this->sourceUrl($releaseId),
        ];
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    private function candidate(array $release): array
    {
        $releaseId = $this->text($release['id'] ?? null);
        $formats = collect($this->arrays($release['media'] ?? null))
            ->map(fn (array $medium): ?string => $this->text($medium['format'] ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'id' => $releaseId,
            'title' => $this->text($release['title'] ?? null),
            'artistName' => $this->artistCredit($release),
            'date' => $this->text($release['date'] ?? null),
            'country' => $this->text($release['country'] ?? null),
            'status' => $this->text($release['status'] ?? null),
            'formats' => $formats,
            'trackCount' => $this->integer($release['track-count'] ?? null),
            'barcode' => $this->text($release['barcode'] ?? null),
            'score' => $this->integer($release['score'] ?? null),
            'sourceUrl' => $releaseId === null ? null : $this->sourceUrl($releaseId),
        ];
    }

    /**
     * @param array<string, MusicianCredit> $credits
     * @param mixed $relations
     */
    private function appendRelations(
        array &$credits,
        mixed $relations,
        ?int $trackId,
        string $sourceEntityType,
        string $sourceEntityReference,
    ): void {
        foreach ($this->arrays($relations) as $relation) {
            $relationshipType = Str::lower($this->text($relation['type'] ?? null) ?? '');
            $artist = is_array($relation['artist'] ?? null) ? $relation['artist'] : null;
            if (! in_array($relationshipType, self::PERFORMANCE_RELATION_TYPES, true) || $artist === null) {
                continue;
            }

            $providerReference = $this->text($artist['id'] ?? null, 128);
            $name = $this->text($artist['name'] ?? null, 255);
            if ($providerReference === null || $name === null) {
                continue;
            }

            $attributes = collect($this->strings($relation['attributes'] ?? null))
                ->map(fn (string $attribute): string => Str::lower($attribute))
                ->unique()
                ->values()
                ->all();
            $roles = array_values(array_filter(
                $attributes,
                fn (string $attribute): bool => ! in_array($attribute, self::ROLE_MODIFIERS, true),
            ));
            if ($roles === []) {
                $roles = [$relationshipType];
            }

            $attributeCredits = is_array($relation['attribute-credits'] ?? null)
                ? $relation['attribute-credits']
                : [];
            foreach ($roles as $role) {
                $displayRole = $this->text($attributeCredits[$role] ?? null, 255)
                    ?? mb_substr($role, 0, 255);
                $credit = new MusicianCredit(
                    providerReference: $providerReference,
                    name: $name,
                    sortName: $this->text($artist['sort-name'] ?? null, 255),
                    disambiguation: $this->text($artist['disambiguation'] ?? null, 255),
                    entityType: $this->text($artist['type'] ?? null, 64),
                    trackId: $trackId,
                    sourceEntityType: $sourceEntityType,
                    sourceEntityReference: $sourceEntityReference,
                    relationshipType: $relationshipType,
                    role: $displayRole,
                    creditedAs: $this->text($relation['target-credit'] ?? null, 255),
                    attributes: $attributes,
                    guest: in_array('guest', $attributes, true),
                    additional: in_array('additional', $attributes, true),
                );
                $credits[$this->creditKey($credit)] = $credit;
            }
        }
    }

    /**
     * @param Collection<int, Track> $tracks
     * @return array{recording: array<string, int>, releaseTrack: array<string, int>, position: array<string, int>}
     */
    private function trackMaps(Collection $tracks): array
    {
        $maps = ['recording' => [], 'releaseTrack' => [], 'position' => []];
        foreach ($tracks as $track) {
            $identifiers = $this->tagIdentifiers->read($track->mediaFile?->raw_metadata ?? []);
            if (is_string($identifiers['recording'] ?? null)) {
                $maps['recording'][Str::lower($identifiers['recording'])] = $track->id;
            }
            if (is_string($identifiers['releaseTrack'] ?? null)) {
                $maps['releaseTrack'][Str::lower($identifiers['releaseTrack'])] = $track->id;
            }
            if ($track->disc_number !== null && $track->track_number !== null) {
                $maps['position'][$track->disc_number.':'.$track->track_number] = $track->id;
            }
        }

        return $maps;
    }

    /** @param array{recording: array<string, int>, releaseTrack: array<string, int>, position: array<string, int>} $maps */
    private function localTrackId(
        array $maps,
        string $recordingId,
        ?string $releaseTrackId,
        ?int $discNumber,
        ?int $trackNumber,
    ): ?int {
        return $maps['recording'][Str::lower($recordingId)]
            ?? ($releaseTrackId === null ? null : $maps['releaseTrack'][Str::lower($releaseTrackId)] ?? null)
            ?? ($discNumber === null || $trackNumber === null
                ? null
                : $maps['position'][$discNumber.':'.$trackNumber] ?? null);
    }

    /** @param array<string, mixed> $entity */
    private function artistCredit(array $entity): ?string
    {
        $credits = $entity['artist-credit'] ?? null;
        if (! is_array($credits)) {
            return null;
        }

        $name = '';
        foreach ($credits as $credit) {
            if (! is_array($credit)) {
                continue;
            }
            $name .= $this->text($credit['name'] ?? $credit['artist']['name'] ?? null) ?? '';
            $name .= $this->text($credit['joinphrase'] ?? null) ?? '';
        }

        return trim($name) ?: null;
    }

    private function creditKey(MusicianCredit $credit): string
    {
        return hash('sha256', implode('|', [
            $credit->providerReference,
            $credit->trackId ?? 'album',
            $credit->sourceEntityType,
            $credit->sourceEntityReference,
            $credit->relationshipType,
            $credit->role,
            $credit->creditedAs ?? '',
        ]));
    }

    /** @return list<int> */
    private function discogsReleaseIds(mixed $relations): array
    {
        return collect($this->arrays($relations))
            ->map(function (array $relation): ?int {
                $resource = $this->text($relation['url']['resource'] ?? null);
                if ($resource === null) {
                    return null;
                }

                $parts = parse_url($resource);
                $host = Str::lower((string) ($parts['host'] ?? ''));
                $path = (string) ($parts['path'] ?? '');
                if (! in_array($host, ['discogs.com', 'www.discogs.com'], true)
                    || preg_match('~/release/(\d+)(?:-|/|$)~i', $path, $matches) !== 1) {
                    return null;
                }

                $releaseId = (int) $matches[1];

                return $releaseId > 0 ? $releaseId : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function arrays(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter($values, 'is_array'))
            : [];
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter(array_map(fn (mixed $value): ?string => $this->text($value), $values)))
            : [];
    }

    private function normalized(mixed $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    private function sourceUrl(string $releaseId): string
    {
        return rtrim((string) config('sonotheque.enrichment.musicbrainz.web_url'), '/')
            .'/release/'.$releaseId;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function text(mixed $value, ?int $maximumLength = null): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return $maximumLength === null ? $text : mb_substr($text, 0, $maximumLength);
    }
}
