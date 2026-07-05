<?php

namespace App\Music\Enrichment;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class ProviderRequestGate
{
    /** @template T
     *  @param callable(): T $request
     *  @return T
     */
    public function run(string $provider, callable $request): mixed
    {
        $this->pace($provider);

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

    private function pace(string $provider): void
    {
        $minimumInterval = max(0, (int) config(
            "music-library.enrichment.providers.{$provider}.minimum_interval_ms",
            0,
        ));
        if ($minimumInterval === 0) {
            return;
        }

        $timestampKey = "online-enrichment:provider:{$provider}:last-request-ms";

        try {
            Cache::lock(
                "online-enrichment:provider:{$provider}:pace",
                max(5, (int) ceil($minimumInterval / 1000) + 2),
            )->block(5, function () use ($timestampKey, $minimumInterval): void {
                $now = (int) floor(microtime(true) * 1000);
                $lastRequest = (int) Cache::get($timestampKey, 0);
                $waitMilliseconds = $minimumInterval - ($now - $lastRequest);
                if ($waitMilliseconds > 0) {
                    usleep($waitMilliseconds * 1000);
                }

                Cache::put($timestampKey, (int) floor(microtime(true) * 1000), 120);
            });
        } catch (LockTimeoutException) {
            throw new EnrichmentProviderException(
                'The provider request pacing lock timed out.',
                errorCode: 'rate_limited',
                retryAfterSeconds: max(1, (int) ceil($minimumInterval / 1000)),
            );
        }
    }
}
