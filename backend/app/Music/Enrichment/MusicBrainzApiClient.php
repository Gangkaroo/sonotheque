<?php

namespace App\Music\Enrichment;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MusicBrainzApiClient
{
    /** @return array<string, mixed>|null */
    public function lookup(string $entity, string $identifier, array $includes = []): ?array
    {
        $response = $this->request("{$entity}/{$identifier}", array_filter([
            'fmt' => 'json',
            'inc' => $includes === [] ? null : implode('+', $includes),
        ]));

        if ($response->status() === 404) {
            return null;
        }

        return $this->payload($response);
    }

    /** @return array<string, mixed> */
    public function search(string $entity, string $query, int $limit = 3): array
    {
        return $this->payload($this->request($entity, [
            'fmt' => 'json',
            'query' => $query,
            'limit' => max(1, min(10, $limit)),
        ]));
    }

    public function phrase(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], trim($value)).'"';
    }

    /** @param array<string, mixed> $parameters */
    private function request(string $path, array $parameters): Response
    {
        $configuration = config('music-library.enrichment.musicbrainz');
        $caBundle = trim((string) ($configuration['ca_bundle'] ?? ''));

        try {
            return Http::acceptJson()
                ->withUserAgent((string) ($configuration['user_agent'] ?? ''))
                ->withOptions([
                    'proxy' => (string) ($configuration['proxy'] ?? ''),
                    'verify' => $caBundle !== '' ? $caBundle : true,
                ])
                ->timeout(max(1, (int) ($configuration['timeout_seconds'] ?? 20)))
                ->get(rtrim((string) $configuration['api_url'], '/').'/'.ltrim($path, '/'), $parameters);
        } catch (ConnectionException $exception) {
            $message = strtolower($exception->getMessage());

            throw new EnrichmentProviderException(
                'MusicBrainz could not be reached: '.$exception->getMessage(),
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
                'MusicBrainz returned an HTTP error.',
                $response->serverError() || $response->status() === 429,
                match (true) {
                    in_array($response->status(), [429, 503], true) => 'rate_limited',
                    $response->serverError() => 'provider_unavailable',
                    default => 'provider_error',
                },
                is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null,
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new EnrichmentProviderException(
                'MusicBrainz returned an invalid response.',
                false,
                'invalid_response',
            );
        }

        return $payload;
    }
}
