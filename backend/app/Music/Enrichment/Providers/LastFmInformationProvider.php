<?php

namespace App\Music\Enrichment\Providers;

use App\Music\Enrichment\Contracts\AlbumInformationProvider;
use App\Music\Enrichment\Contracts\ArtistInformationProvider;
use App\Music\Enrichment\Data\AlbumInformation;
use App\Music\Enrichment\Data\AlbumLookup;
use App\Music\Enrichment\Data\ArtistInformation;
use App\Music\Enrichment\Data\ArtistLookup;
use App\Music\Enrichment\Data\ProviderAttribution;
use App\Music\Enrichment\EnrichmentProviderException;
use App\Music\LastFm\LastFmApiClient;
use App\Music\LastFm\LastFmApiException;

class LastFmInformationProvider implements AlbumInformationProvider, ArtistInformationProvider
{
    public function __construct(private readonly LastFmApiClient $client)
    {
    }

    public function key(): string
    {
        return 'lastfm';
    }

    public function fetchArtist(ArtistLookup $lookup): ?ArtistInformation
    {
        try {
            $artist = $this->client->artistInfo($this->apiKey(), $lookup->name, $lookup->language)['artist'] ?? null;
        } catch (LastFmApiException $exception) {
            if (in_array($exception->apiCode, [6, 7], true)) {
                return null;
            }

            throw $this->providerException($exception);
        }

        if (! is_array($artist) || ! is_string($artist['name'] ?? null)) {
            return null;
        }

        $url = $this->text($artist['url'] ?? null);

        return new ArtistInformation(
            name: $artist['name'],
            biography: $this->description(
                $artist['bio']['content'] ?? null,
                $artist['bio']['summary'] ?? null,
            ),
            country: null,
            activeFrom: null,
            activeTo: null,
            tags: $this->tags($artist['tags']['tag'] ?? []),
            attribution: new ProviderAttribution($this->key(), 'Last.fm', $url),
            providerReference: $this->text($artist['mbid'] ?? null),
        );
    }

    public function fetchAlbum(AlbumLookup $lookup): ?AlbumInformation
    {
        try {
            $album = $this->client->albumInfo(
                $this->apiKey(),
                $lookup->artistName,
                $lookup->title,
                $lookup->language,
            )['album'] ?? null;
        } catch (LastFmApiException $exception) {
            if (in_array($exception->apiCode, [6, 7], true)) {
                return null;
            }

            throw $this->providerException($exception);
        }

        if (! is_array($album) || ! is_string($album['name'] ?? null)) {
            return null;
        }

        $url = $this->text($album['url'] ?? null);

        return new AlbumInformation(
            title: $album['name'],
            artistName: $this->text($album['artist'] ?? null) ?? $lookup->artistName,
            summary: $this->description(
                $album['wiki']['content'] ?? null,
                $album['wiki']['summary'] ?? null,
            ),
            releaseDate: null,
            label: null,
            releaseType: null,
            tags: $this->tags($album['tags']['tag'] ?? []),
            attribution: new ProviderAttribution($this->key(), 'Last.fm', $url),
            providerReference: $this->text($album['mbid'] ?? null),
        );
    }

    private function apiKey(): string
    {
        return (string) \App\Models\ApplicationSetting::current()->lastfm_api_key;
    }

    public function testConnection(): void
    {
        try {
            $this->client->artistInfo($this->apiKey(), 'Cher', 'en');
        } catch (LastFmApiException $exception) {
            throw $this->providerException($exception);
        }
    }

    private function providerException(LastFmApiException $exception): EnrichmentProviderException
    {
        $message = strtolower($exception->getMessage());
        $errorCode = match (true) {
            $exception->apiCode === 29 => 'rate_limited',
            in_array($exception->apiCode, [11, 16], true) => 'provider_unavailable',
            str_contains($message, 'curl error 60'), str_contains($message, 'certificate') => 'tls_certificate',
            str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'timeout',
            str_contains($message, 'could not be reached') => 'connection',
            default => 'provider_error',
        };

        return new EnrichmentProviderException(
            $exception->getMessage(),
            $exception->retriable,
            $errorCode,
            $exception->apiCode === 29 ? 60 : null,
        );
    }

    private function description(mixed $content, mixed $summary): ?string
    {
        $description = $this->text($content) ?? $this->text($summary);
        if ($description === null) {
            return null;
        }

        $description = html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = preg_replace('/\s*Read more on Last\.fm.*$/iu', '', $description) ?? $description;

        return trim($description) ?: null;
    }

    /** @return list<string> */
    private function tags(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (isset($value['name'])) {
            $value = [$value];
        }

        return collect($value)
            ->map(fn (mixed $tag): ?string => is_array($tag) ? $this->text($tag['name'] ?? null) : null)
            ->filter()
            ->values()
            ->all();
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
