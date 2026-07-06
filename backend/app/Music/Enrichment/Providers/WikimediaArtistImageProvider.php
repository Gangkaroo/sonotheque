<?php

namespace App\Music\Enrichment\Providers;

use App\Music\Enrichment\Contracts\ArtistImageProvider;
use App\Music\Enrichment\Data\ArtistImageInformation;
use App\Music\Enrichment\Data\ArtistLookup;
use App\Music\Enrichment\Data\ProviderAttribution;
use App\Music\Enrichment\WikimediaApiClient;

class WikimediaArtistImageProvider implements ArtistImageProvider
{
    public function __construct(private readonly WikimediaApiClient $client)
    {
    }

    public function key(): string
    {
        return 'wikimedia';
    }

    public function fetchArtistImage(ArtistLookup $lookup): ?ArtistImageInformation
    {
        $musicBrainzId = $lookup->externalIds['musicbrainz_artist'] ?? null;
        if (! is_string($musicBrainzId)) {
            return null;
        }

        $match = $this->client->findArtistImage($musicBrainzId);
        if ($match === null) {
            return null;
        }

        $image = $this->client->fileInformation($match['fileName']);
        $imageUrl = $this->text($image['thumburl'] ?? $image['url'] ?? null);
        $sourceUrl = $this->text($image['descriptionurl'] ?? null);
        if ($imageUrl === null || $sourceUrl === null) {
            return null;
        }

        $metadata = is_array($image['extmetadata'] ?? null) ? $image['extmetadata'] : [];

        return new ArtistImageInformation(
            imageUrl: $imageUrl,
            width: $this->integer($image['thumbwidth'] ?? $image['width'] ?? null),
            height: $this->integer($image['thumbheight'] ?? $image['height'] ?? null),
            author: $this->metadataText($metadata['Artist'] ?? $metadata['Credit'] ?? null),
            licenseName: $this->metadataText($metadata['LicenseShortName'] ?? null),
            licenseUrl: $this->httpsUrl($this->metadataText($metadata['LicenseUrl'] ?? null)),
            attribution: new ProviderAttribution($this->key(), 'Wikimedia Commons', $sourceUrl),
            providerReference: $match['fileName'],
        );
    }

    private function metadataText(mixed $value): ?string
    {
        return is_array($value) ? $this->cleanText($value['value'] ?? null) : null;
    }

    private function cleanText(mixed $value): ?string
    {
        $text = $this->text($value);
        if ($text === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text) ?: null;
    }

    private function httpsUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, 'http://')) {
            $url = 'https://'.substr($url, 7);
        }

        return str_starts_with($url, 'https://') ? $url : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
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
