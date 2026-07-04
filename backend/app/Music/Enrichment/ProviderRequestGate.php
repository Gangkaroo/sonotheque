<?php

namespace App\Music\Enrichment;

use Illuminate\Support\Facades\RateLimiter;

class ProviderRequestGate
{
    /** @template T
     *  @param callable(): T $request
     *  @return T
     */
    public function run(string $provider, callable $request): mixed
    {
        $key = "online-enrichment:provider:{$provider}";
        $maximum = max(1, (int) config(
            "music-library.enrichment.providers.{$provider}.max_requests_per_minute",
            60,
        ));
        $result = RateLimiter::attempt(
            $key,
            $maximum,
            static fn (): array => ['value' => $request()],
            60,
        );

        if ($result === false) {
            throw new EnrichmentProviderException(
                'The provider request limit has been reached.',
                errorCode: 'rate_limited',
                retryAfterSeconds: max(1, RateLimiter::availableIn($key)),
            );
        }

        return $result['value'];
    }
}
