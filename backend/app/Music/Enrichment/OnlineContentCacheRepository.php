<?php

namespace App\Music\Enrichment;

use App\Enums\OnlineContentStatus;
use App\Enums\OnlineContentType;
use App\Models\OnlineContentCache;
use App\Music\Enrichment\Contracts\CacheableLookup;
use Carbon\CarbonInterface;
use JsonException;

class OnlineContentCacheRepository
{
    public function find(
        string $provider,
        OnlineContentType $type,
        CacheableLookup $lookup,
    ): ?OnlineContentCache {
        return OnlineContentCache::query()->firstWhere([
            'provider' => $provider,
            'resource_type' => $type->value,
            'lookup_hash' => $this->lookupHash($lookup),
        ]);
    }

    /** @param array<string, mixed>|null $payload */
    public function store(
        string $provider,
        OnlineContentType $type,
        CacheableLookup $lookup,
        OnlineContentStatus $status,
        ?array $payload,
        ?CarbonInterface $expiresAt,
        ?CarbonInterface $staleUntil = null,
        ?CarbonInterface $retryAfter = null,
        ?string $providerReference = null,
        ?string $sourceUrl = null,
        int $failureCount = 0,
        ?string $lastErrorCode = null,
    ): OnlineContentCache {
        $lookupPayload = $this->normalized($lookup->cachePayload());

        return OnlineContentCache::query()->updateOrCreate([
            'provider' => $provider,
            'resource_type' => $type->value,
            'lookup_hash' => $this->lookupHash($lookup),
        ], [
            'lookup' => $lookupPayload,
            'status' => $status,
            'payload' => $payload,
            'provider_reference' => $providerReference,
            'source_url' => $sourceUrl,
            'fetched_at' => now(),
            'expires_at' => $expiresAt,
            'stale_until' => $staleUntil,
            'retry_after' => $retryAfter,
            'failure_count' => $failureCount,
            'last_error_code' => $lastErrorCode,
        ]);
    }

    public function markFailure(
        OnlineContentCache $cache,
        CarbonInterface $retryAfter,
        string $errorCode,
        int $failureCount,
    ): OnlineContentCache {
        $cache->update([
            'retry_after' => $retryAfter,
            'failure_count' => $failureCount,
            'last_error_code' => $errorCode,
        ]);

        return $cache->refresh();
    }

    /** @throws JsonException */
    public function lookupHash(CacheableLookup $lookup): string
    {
        return hash('sha256', json_encode(
            $this->normalized($lookup->cachePayload()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    public function lockKey(string $provider, OnlineContentType $type, CacheableLookup $lookup): string
    {
        return "online-enrichment:{$provider}:{$type->value}:{$this->lookupHash($lookup)}";
    }

    /** @return array{total: int, ready: int, notFound: int, errors: int, stale: int} */
    public function summary(): array
    {
        $query = OnlineContentCache::query();

        return [
            'total' => (clone $query)->count(),
            'ready' => (clone $query)->where('status', OnlineContentStatus::Ready->value)->count(),
            'notFound' => (clone $query)->where('status', OnlineContentStatus::NotFound->value)->count(),
            'errors' => (clone $query)->where('status', OnlineContentStatus::Error->value)->count(),
            'stale' => (clone $query)
                ->where('status', OnlineContentStatus::Ready->value)
                ->where('expires_at', '<=', now())
                ->where('stale_until', '>', now())
                ->count(),
        ];
    }

    public function clear(): int
    {
        return OnlineContentCache::query()->delete();
    }

    /** @param array<string, mixed> $values
     *  @return array<string, mixed>
     */
    private function normalized(array $values): array
    {
        ksort($values);

        foreach ($values as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $values[$key] = array_is_list($value)
                ? array_map(fn (mixed $item): mixed => is_array($item) ? $this->normalized($item) : $item, $value)
                : $this->normalized($value);
        }

        return $values;
    }
}
