<?php

namespace App\Music\Enrichment\Providers;

use App\Music\Enrichment\AmbiguousEnrichmentMatchException;
use App\Music\Enrichment\Contracts\AlbumInformationProvider;
use App\Music\Enrichment\Contracts\ArtistInformationProvider;
use App\Music\Enrichment\Data\AlbumInformation;
use App\Music\Enrichment\Data\AlbumLookup;
use App\Music\Enrichment\Data\ArtistInformation;
use App\Music\Enrichment\Data\ArtistLookup;
use App\Music\Enrichment\Data\ProviderAttribution;
use App\Music\Enrichment\EnrichmentProviderException;
use App\Music\Enrichment\MusicBrainzApiClient;
use Illuminate\Support\Str;

class MusicBrainzInformationProvider implements AlbumInformationProvider, ArtistInformationProvider
{
    public function __construct(private readonly MusicBrainzApiClient $client)
    {
    }

    public function key(): string
    {
        return 'musicbrainz';
    }

    public function testConnection(): void
    {
        if ($this->client->lookup('artist', '5b11f4ce-a62d-471e-81fc-a69a8278c7da') === null) {
            throw new EnrichmentProviderException(
                'MusicBrainz did not return the expected test artist.',
                errorCode: 'invalid_response',
            );
        }
    }

    public function fetchArtist(ArtistLookup $lookup): ?ArtistInformation
    {
        $identifier = $lookup->externalIds['musicbrainz_artist'] ?? null;
        $matchMethod = 'tag';
        $confidence = 100;

        if (is_string($identifier) && $identifier !== '') {
            $artist = $this->client->lookup('artist', $identifier, ['aliases', 'tags']);
        } else {
            $matchMethod = 'search';
            $artist = $this->searchCandidate(
                $this->client->search('artist', 'artist:'.$this->client->phrase($lookup->name))['artists'] ?? [],
                $lookup->name,
                'name',
            );
            $confidence = (int) ($artist['score'] ?? 0);
        }

        if (! is_array($artist) || ! is_string($artist['id'] ?? null) || ! is_string($artist['name'] ?? null)) {
            return null;
        }

        $lifeSpan = is_array($artist['life-span'] ?? null) ? $artist['life-span'] : [];

        return new ArtistInformation(
            name: $artist['name'],
            biography: null,
            country: $this->text($artist['country'] ?? $artist['area']['name'] ?? null),
            activeFrom: $this->text($lifeSpan['begin'] ?? null),
            activeTo: $this->text($lifeSpan['end'] ?? null),
            tags: $this->tags($artist['tags'] ?? []),
            attribution: new ProviderAttribution(
                $this->key(),
                'MusicBrainz',
                $this->sourceUrl('artist', $artist['id']),
            ),
            providerReference: $artist['id'],
            matchMethod: $matchMethod,
            matchConfidence: $confidence,
        );
    }

    public function fetchAlbum(AlbumLookup $lookup): ?AlbumInformation
    {
        $releaseIdentifier = $lookup->externalIds['musicbrainz_release'] ?? null;
        $groupIdentifier = $lookup->externalIds['musicbrainz_release_group'] ?? null;
        $matchMethod = 'tag';
        $confidence = 100;
        $entity = 'release-group';

        if (is_string($releaseIdentifier) && $releaseIdentifier !== '') {
            $entity = 'release';
            $album = $this->client->lookup('release', $releaseIdentifier, [
                'artist-credits',
                'labels',
                'release-groups',
            ]);
        } elseif (is_string($groupIdentifier) && $groupIdentifier !== '') {
            $album = $this->client->lookup('release-group', $groupIdentifier, ['artist-credits', 'tags']);
        } else {
            $matchMethod = 'search';
            $album = $this->searchCandidate(
                $this->client->search(
                    'release-group',
                    'releasegroup:'.$this->client->phrase($lookup->title)
                    .' AND artist:'.$this->client->phrase($lookup->artistName),
                )['release-groups'] ?? [],
                $lookup->title,
                'title',
            );
            if (is_array($album) && ! $this->artistMatches($album, $lookup->artistName)) {
                return null;
            }
            $confidence = (int) ($album['score'] ?? 0);
        }

        if (! is_array($album) || ! is_string($album['id'] ?? null) || ! is_string($album['title'] ?? null)) {
            return null;
        }

        $releaseGroup = is_array($album['release-group'] ?? null) ? $album['release-group'] : $album;
        $types = array_filter([
            $this->text($releaseGroup['primary-type'] ?? null),
            ...$this->strings($releaseGroup['secondary-types'] ?? []),
        ]);

        return new AlbumInformation(
            title: $album['title'],
            artistName: $this->artistCredit($album) ?? $lookup->artistName,
            summary: null,
            releaseDate: $this->text($album['date'] ?? $album['first-release-date'] ?? null),
            label: $this->text($album['label-info'][0]['label']['name'] ?? null),
            releaseType: $types === [] ? null : implode(' / ', $types),
            tags: $this->tags($album['tags'] ?? []),
            attribution: new ProviderAttribution(
                $this->key(),
                'MusicBrainz',
                $this->sourceUrl($entity, $album['id']),
            ),
            providerReference: $album['id'],
            matchMethod: $matchMethod,
            matchConfidence: $confidence,
        );
    }

    /** @param mixed $results
     *  @return array<string, mixed>|null
     */
    private function searchCandidate(mixed $results, string $expectedName, string $nameKey): ?array
    {
        if (! is_array($results) || ! is_array($results[0] ?? null)) {
            return null;
        }

        $candidate = $results[0];
        $score = (int) ($candidate['score'] ?? 0);
        $minimumScore = max(1, (int) config('sonotheque.enrichment.musicbrainz.minimum_match_score', 95));
        if ($score < $minimumScore || $this->normalized($candidate[$nameKey] ?? null) !== $this->normalized($expectedName)) {
            return null;
        }

        $secondScore = is_array($results[1] ?? null) ? (int) ($results[1]['score'] ?? 0) : 0;
        $requiredGap = max(1, (int) config('sonotheque.enrichment.musicbrainz.ambiguity_score_gap', 10));
        if ($secondScore > 0 && ($score - $secondScore) < $requiredGap) {
            throw new AmbiguousEnrichmentMatchException('MusicBrainz returned several similarly ranked matches.');
        }

        return $candidate;
    }

    /** @param array<string, mixed> $album */
    private function artistMatches(array $album, string $artistName): bool
    {
        return $this->normalized($this->artistCredit($album)) === $this->normalized($artistName);
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

    /** @param mixed $tags
     *  @return list<string>
     */
    private function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return collect($tags)
            ->map(fn (mixed $tag): ?string => is_array($tag) ? $this->text($tag['name'] ?? null) : null)
            ->filter()
            ->values()
            ->all();
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

    private function sourceUrl(string $entity, string $identifier): string
    {
        return rtrim((string) config('sonotheque.enrichment.musicbrainz.web_url'), '/')
            ."/{$entity}/{$identifier}";
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return trim((string) $value) ?: null;
    }
}
