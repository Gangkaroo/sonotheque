<?php

namespace App\Music\Enrichment;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WikimediaApiClient
{
    /** @return array{itemUrl: string, fileName: string}|null */
    public function findArtistImage(string $musicBrainzId): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $musicBrainzId)) {
            return null;
        }

        $query = <<<SPARQL
SELECT ?item ?image WHERE {
  ?item wdt:P434 "{$musicBrainzId}" .
  ?item wdt:P18 ?image .
}
LIMIT 1
SPARQL;
        $payload = $this->payload($this->request(
            (string) config('sonotheque.enrichment.wikimedia.wikidata_query_url'),
            ['query' => $query, 'format' => 'json'],
            'application/sparql-results+json',
        ));
        $binding = $payload['results']['bindings'][0] ?? null;
        if (! is_array($binding)) {
            return null;
        }

        $itemUrl = $this->text($binding['item']['value'] ?? null);
        $imageUrl = $this->text($binding['image']['value'] ?? null);
        $fileName = $imageUrl === null ? null : $this->commonsFileName($imageUrl);

        return $itemUrl !== null && $fileName !== null
            ? ['itemUrl' => $itemUrl, 'fileName' => $fileName]
            : null;
    }

    /** @return array<string, mixed>|null */
    public function fileInformation(string $fileName): ?array
    {
        $payload = $this->payload($this->request(
            (string) config('sonotheque.enrichment.wikimedia.commons_api_url'),
            [
                'action' => 'query',
                'format' => 'json',
                'formatversion' => 2,
                'prop' => 'imageinfo',
                'titles' => 'File:'.$fileName,
                'iiprop' => 'url|size|extmetadata',
                'iiurlwidth' => 600,
            ],
        ));
        $page = $payload['query']['pages'][0] ?? null;
        $image = is_array($page) ? ($page['imageinfo'][0] ?? null) : null;

        return is_array($image) ? $image : null;
    }

    /** @param array<string, mixed> $parameters */
    private function request(string $url, array $parameters, string $accept = 'application/json'): Response
    {
        $configuration = config('sonotheque.enrichment.wikimedia');
        $caBundle = trim((string) ($configuration['ca_bundle'] ?? ''));

        try {
            return Http::accept($accept)
                ->withUserAgent((string) ($configuration['user_agent'] ?? ''))
                ->withOptions([
                    'proxy' => (string) ($configuration['proxy'] ?? ''),
                    'verify' => $caBundle !== '' ? $caBundle : true,
                ])
                ->timeout(max(1, (int) ($configuration['timeout_seconds'] ?? 20)))
                ->get($url, $parameters);
        } catch (ConnectionException $exception) {
            $message = strtolower($exception->getMessage());

            throw new EnrichmentProviderException(
                'Wikimedia could not be reached: '.$exception->getMessage(),
                errorCode: match (true) {
                    str_contains($message, 'curl error 60'), str_contains($message, 'certificate') => 'tls_certificate',
                    str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'timeout',
                    default => 'connection',
                },
            );
        }
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        if ($response->failed()) {
            $retryAfter = $response->header('Retry-After');

            throw new EnrichmentProviderException(
                'Wikimedia returned an HTTP error.',
                $response->serverError() || $response->status() === 429,
                match (true) {
                    $response->status() === 429 => 'rate_limited',
                    $response->serverError() => 'provider_unavailable',
                    default => 'provider_error',
                },
                is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null,
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new EnrichmentProviderException(
                'Wikimedia returned an invalid response.',
                false,
                'invalid_response',
            );
        }

        return $payload;
    }

    private function commonsFileName(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $marker = '/Special:FilePath/';
        if (! is_string($path) || ! str_contains($path, $marker)) {
            return null;
        }

        $fileName = rawurldecode(substr($path, strpos($path, $marker) + strlen($marker)));

        return trim($fileName) !== '' ? trim($fileName) : null;
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
