<?php

namespace App\Music\Enrichment\Providers;

use App\Music\Enrichment\Contracts\LyricsProvider;
use App\Music\Enrichment\Data\LyricsContent;
use App\Music\Enrichment\Data\LyricsLookup;
use App\Music\Enrichment\Data\ProviderAttribution;
use App\Music\Enrichment\EnrichmentProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class LrclibLyricsProvider implements LyricsProvider
{
    public function key(): string
    {
        return 'lrclib';
    }

    public function fetchLyrics(LyricsLookup $lookup): ?LyricsContent
    {
        if ($lookup->albumTitle === null || $lookup->durationSeconds === null) {
            return null;
        }

        $response = $this->request([
            'track_name' => $lookup->title,
            'artist_name' => $lookup->artistName,
            'album_name' => $lookup->albumTitle,
            'duration' => $lookup->durationSeconds,
        ]);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw $this->responseException($response);
        }

        $payload = $response->json();
        if (! is_array($payload) || ! is_numeric($payload['id'] ?? null)) {
            throw new EnrichmentProviderException(
                'LRCLIB returned an invalid response.',
                false,
                'invalid_response',
            );
        }

        $id = (string) $payload['id'];

        return new LyricsContent(
            plainLyrics: $this->text($payload['plainLyrics'] ?? null),
            synchronizedLyrics: $this->text($payload['syncedLyrics'] ?? null),
            language: null,
            attribution: new ProviderAttribution(
                $this->key(),
                'LRCLIB',
                'https://lrclib.net/api/get/'.$id,
            ),
            providerReference: $id,
            instrumental: (bool) ($payload['instrumental'] ?? false),
        );
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) ?: null;
    }

    public function testConnection(): void
    {
        $response = $this->request([
            'track_name' => '__music_library_connection_test__',
            'artist_name' => '__music_library_connection_test__',
            'album_name' => '__music_library_connection_test__',
            'duration' => 1,
        ]);

        if ($response->status() !== 404 && $response->failed()) {
            throw $this->responseException($response);
        }
    }

    /** @param array<string, int|string> $parameters */
    private function request(array $parameters): Response
    {
        $configuration = config('music-library.enrichment.lrclib');
        $caBundle = trim((string) ($configuration['ca_bundle'] ?? ''));

        try {
            return Http::acceptJson()
                ->withUserAgent((string) config('music-library.enrichment.user_agent'))
                ->withOptions([
                    'proxy' => (string) ($configuration['proxy'] ?? ''),
                    'verify' => $caBundle !== '' ? $caBundle : true,
                ])
                ->timeout(max(1, (int) ($configuration['timeout_seconds'] ?? 10)))
                ->get(rtrim((string) $configuration['api_url'], '/').'/get', $parameters);
        } catch (ConnectionException $exception) {
            throw new EnrichmentProviderException(
                'LRCLIB could not be reached: '.$exception->getMessage(),
                errorCode: $this->connectionErrorCode($exception),
            );
        }
    }

    private function responseException(Response $response): EnrichmentProviderException
    {
        $retryAfter = $response->header('Retry-After');

        return new EnrichmentProviderException(
            'LRCLIB returned an HTTP error.',
            $response->serverError() || $response->status() === 429,
            match (true) {
                $response->status() === 429 => 'rate_limited',
                $response->serverError() => 'provider_unavailable',
                default => 'provider_error',
            },
            is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null,
        );
    }

    private function connectionErrorCode(ConnectionException $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'curl error 60'),
            str_contains($message, 'certificate') => 'tls_certificate',
            str_contains($message, 'timed out'),
            str_contains($message, 'timeout') => 'timeout',
            default => 'connection',
        };
    }
}
