<?php

namespace App\Music\LastFm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class LastFmApiClient
{
    public function requestToken(string $apiKey, string $apiSecret): string
    {
        $payload = $this->call('auth.getToken', $apiKey, $apiSecret);
        $token = $payload['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new LastFmApiException('Last.fm did not return an authorization token.');
        }

        return $token;
    }

    /** @return array{name: string, key: string} */
    public function requestSession(string $apiKey, string $apiSecret, string $token): array
    {
        $payload = $this->call('auth.getSession', $apiKey, $apiSecret, ['token' => $token]);
        $session = $payload['session'] ?? null;

        if (! is_array($session)
            || ! is_string($session['name'] ?? null)
            || ! is_string($session['key'] ?? null)) {
            throw new LastFmApiException('Last.fm did not return a valid session.');
        }

        return [
            'name' => $session['name'],
            'key' => $session['key'],
        ];
    }

    /**
     * @param  array{
     *     artist: string,
     *     track: string,
     *     timestamp: int,
     *     album?: string,
     *     albumArtist?: string,
     *     duration?: int,
     *     trackNumber?: int
     * }  $track
     */
    public function scrobble(
        string $apiKey,
        string $apiSecret,
        string $sessionKey,
        array $track,
    ): LastFmScrobbleResult {
        $payload = $this->call(
            'track.scrobble',
            $apiKey,
            $apiSecret,
            [...$track, 'sk' => $sessionKey],
        );
        $scrobbles = $payload['scrobbles'] ?? [];
        $accepted = (int) ($scrobbles['@attr']['accepted'] ?? 0) > 0;
        $scrobble = $scrobbles['scrobble'] ?? [];
        $ignored = $scrobble['ignoredMessage'] ?? [];
        $ignoredCode = isset($ignored['code']) ? (int) $ignored['code'] : null;
        $message = is_string($ignored['#text'] ?? null) ? $ignored['#text'] : null;

        return new LastFmScrobbleResult($accepted, $ignoredCode, $message);
    }

    public function authorizationUrl(string $apiKey, string $token): string
    {
        return rtrim((string) config('music-library.lastfm.auth_url'), '/').'/?'.http_build_query([
            'api_key' => $apiKey,
            'token' => $token,
        ]);
    }

    /** @param array<string, int|string> $parameters */
    private function call(
        string $method,
        string $apiKey,
        string $apiSecret,
        array $parameters = [],
    ): array {
        $signedParameters = [
            'api_key' => $apiKey,
            'method' => $method,
            ...$parameters,
        ];
        $requestParameters = [
            ...$signedParameters,
            'api_sig' => $this->signature($signedParameters, $apiSecret),
            'format' => 'json',
        ];

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withOptions($this->connectionOptions())
                ->timeout(max(1, (int) config('music-library.lastfm.timeout_seconds', 10)))
                ->post((string) config('music-library.lastfm.api_url'), $requestParameters);
        } catch (ConnectionException $exception) {
            throw new LastFmApiException(
                'Last.fm could not be reached: '.$exception->getMessage(),
                retriable: true,
            );
        }

        return $this->responsePayload($response);
    }

    /** @return array{proxy: string, verify: bool|string} */
    private function connectionOptions(): array
    {
        $caBundle = trim((string) config('music-library.lastfm.ca_bundle'));

        return [
            // An explicit empty value prevents inherited development proxies
            // from silently intercepting this outbound HTTPS request.
            'proxy' => (string) config('music-library.lastfm.proxy', ''),
            'verify' => $caBundle !== '' ? $caBundle : true,
        ];
    }

    /** @param array<string, int|string> $parameters */
    private function signature(array $parameters, string $apiSecret): string
    {
        ksort($parameters, SORT_STRING);
        $signature = '';

        foreach ($parameters as $name => $value) {
            $signature .= $name.$value;
        }

        return md5($signature.$apiSecret);
    }

    /** @return array<string, mixed> */
    private function responsePayload(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new LastFmApiException(
                'Last.fm returned an invalid response.',
                retriable: $response->serverError(),
            );
        }

        if (isset($payload['error'])) {
            $code = (int) $payload['error'];
            $message = is_string($payload['message'] ?? null)
                ? $payload['message']
                : 'Last.fm rejected the request.';

            throw new LastFmApiException($message, $code, in_array($code, [11, 16, 29], true));
        }

        if ($response->failed()) {
            throw new LastFmApiException(
                'Last.fm returned an HTTP error.',
                retriable: $response->serverError() || $response->status() === 429,
            );
        }

        return $payload;
    }
}
