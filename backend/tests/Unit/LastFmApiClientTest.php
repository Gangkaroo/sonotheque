<?php

namespace Tests\Unit;

use App\Music\LastFm\LastFmApiClient;
use App\Music\LastFm\LastFmApiException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LastFmApiClientTest extends TestCase
{
    public function test_it_signs_token_requests_and_excludes_the_response_format_from_the_signature(): void
    {
        Http::fake(['*' => Http::response(['token' => 'token'])]);
        $apiKey = str_repeat('a', 32);
        $secret = str_repeat('b', 32);

        $token = app(LastFmApiClient::class)->requestToken($apiKey, $secret);

        $this->assertSame('token', $token);
        Http::assertSent(function ($request) use ($apiKey, $secret): bool {
            $parameters = [
                'api_key' => $apiKey,
                'method' => 'auth.getToken',
            ];
            $signature = md5('api_key'.$apiKey.'methodauth.getToken'.$secret);

            return $request->isForm()
                && $request['api_sig'] === $signature
                && $request['format'] === 'json'
                && $request->data()['api_key'] === $parameters['api_key'];
        });
    }

    public function test_it_parses_an_accepted_scrobble(): void
    {
        Http::fake(['*' => Http::response([
            'scrobbles' => [
                '@attr' => ['accepted' => '1', 'ignored' => '0'],
                'scrobble' => ['ignoredMessage' => ['code' => '0', '#text' => '']],
            ],
        ])]);

        $result = app(LastFmApiClient::class)->scrobble(
            str_repeat('a', 32),
            str_repeat('b', 32),
            'session-key',
            ['artist' => 'Artist', 'track' => 'Track', 'timestamp' => 1_700_000_000],
        );

        $this->assertTrue($result->accepted);
        $this->assertSame(0, $result->ignoredCode);
    }

    public function test_it_preserves_the_connection_error_details(): void
    {
        Http::fake(['*' => Http::failedConnection('Proxy connection failed')]);

        try {
            app(LastFmApiClient::class)->requestToken(str_repeat('a', 32), str_repeat('b', 32));
            $this->fail('A Last.fm connection exception was expected.');
        } catch (LastFmApiException $exception) {
            $this->assertTrue($exception->retriable);
            $this->assertStringContainsString('Proxy connection failed', $exception->getMessage());
        }
    }
}
