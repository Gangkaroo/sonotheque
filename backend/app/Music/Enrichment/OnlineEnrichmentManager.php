<?php

namespace App\Music\Enrichment;

use App\Enums\OnlineContentStatus;
use App\Enums\OnlineContentType;
use App\Jobs\RefreshOnlineEnrichment;
use App\Models\ApplicationSetting;
use App\Models\OnlineContentCache;
use App\Models\Track;
use App\Music\Enrichment\Contracts\CacheableLookup;
use App\Music\Enrichment\Data\AlbumLookup;
use App\Music\Enrichment\Data\ArtistLookup;
use App\Music\Enrichment\Data\LyricsLookup;
use App\Music\Enrichment\Providers\LastFmInformationProvider;
use App\Music\Enrichment\Providers\LrclibLyricsProvider;
use App\Music\Enrichment\Providers\MusicBrainzInformationProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class OnlineEnrichmentManager
{
    private const LASTFM_CACHE_VARIANT = 'full-description-v1';

    public function __construct(
        private readonly OnlineContentCacheRepository $cache,
        private readonly ProviderRequestGate $requestGate,
        private readonly MusicBrainzTagIdentifierReader $musicBrainzTags,
        private readonly LastFmInformationProvider $informationProvider,
        private readonly MusicBrainzInformationProvider $identityProvider,
        private readonly LrclibLyricsProvider $lyricsProvider,
    ) {
    }

    /** @return array{artist: array<string, mixed>, album: array<string, mixed>} */
    public function identityForTrack(Track $track): array
    {
        if (! ApplicationSetting::current()->online_information_enabled) {
            return [
                'artist' => $this->state('disabled'),
                'album' => $this->state('disabled'),
            ];
        }

        $track->loadMissing([
            'album.primaryArtist:id,name',
            'artists:id,name',
            'mediaFile:id,raw_metadata',
        ]);
        $album = $track->album;
        $artist = $album?->primaryArtist ?? $track->artists->first();
        if ($artist === null) {
            return [
                'artist' => $this->state('not_found', $this->identityProvider->key()),
                'album' => $this->state('not_found', $this->identityProvider->key()),
            ];
        }

        $identifiers = $this->musicBrainzTags->read($track->mediaFile?->raw_metadata ?? []);
        $artistIdentifier = $identifiers['albumArtist'] ?? $identifiers['artist'] ?? null;
        $artistLookup = new ArtistLookup(
            $artist->id,
            $artist->name,
            array_filter(['musicbrainz_artist' => $artistIdentifier]),
        );
        $artistResult = $this->resolve(
            $this->identityProvider->key(),
            OnlineContentType::Artist,
            $artistLookup,
            fn () => $this->identityProvider->fetchArtist($artistLookup),
        );

        if ($album === null) {
            return [
                'artist' => $artistResult,
                'album' => $this->state('not_found', $this->identityProvider->key()),
            ];
        }

        $albumLookup = new AlbumLookup(
            $album->id,
            $album->title,
            $artist->name,
            $album->original_release_year,
            array_filter([
                'musicbrainz_release' => $identifiers['release'] ?? null,
                'musicbrainz_release_group' => $identifiers['releaseGroup'] ?? null,
            ]),
        );

        return [
            'artist' => $artistResult,
            'album' => $this->resolve(
                $this->identityProvider->key(),
                OnlineContentType::Album,
                $albumLookup,
                fn () => $this->identityProvider->fetchAlbum($albumLookup),
            ),
        ];
    }

    /** @return array{artist: array<string, mixed>, album: array<string, mixed>} */
    public function informationForTrack(Track $track, string $language): array
    {
        $settings = ApplicationSetting::current();
        if (! $settings->online_information_enabled) {
            return [
                'artist' => $this->state('disabled'),
                'album' => $this->state('disabled'),
            ];
        }

        if (blank($settings->lastfm_api_key)) {
            return [
                'artist' => $this->state('not_configured', $this->informationProvider->key()),
                'album' => $this->state('not_configured', $this->informationProvider->key()),
            ];
        }

        $track->loadMissing(['album.primaryArtist:id,name', 'artists:id,name']);
        $album = $track->album;
        $artist = $album?->primaryArtist ?? $track->artists->first();
        if ($artist === null) {
            return [
                'artist' => $this->state('not_found', $this->informationProvider->key()),
                'album' => $this->state('not_found', $this->informationProvider->key()),
            ];
        }

        $artistLookup = new ArtistLookup(
            $artist->id,
            $artist->name,
            language: $language,
            cacheVariant: self::LASTFM_CACHE_VARIANT,
        );
        $artistResult = $this->resolve(
            $this->informationProvider->key(),
            OnlineContentType::Artist,
            $artistLookup,
            fn () => $this->informationProvider->fetchArtist($artistLookup),
        );

        if ($album === null) {
            return [
                'artist' => $artistResult,
                'album' => $this->state('not_found', $this->informationProvider->key()),
            ];
        }

        $albumLookup = new AlbumLookup(
            $album->id,
            $album->title,
            $artist->name,
            $album->original_release_year,
            language: $language,
            cacheVariant: self::LASTFM_CACHE_VARIANT,
        );

        return [
            'artist' => $artistResult,
            'album' => $this->resolve(
                $this->informationProvider->key(),
                OnlineContentType::Album,
                $albumLookup,
                fn () => $this->informationProvider->fetchAlbum($albumLookup),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function lyricsForTrack(Track $track): array
    {
        if (! ApplicationSetting::current()->online_lyrics_enabled) {
            return $this->state('disabled');
        }

        $track->loadMissing(['album:id,title', 'artists:id,name']);
        $artist = $track->artists->first();
        if ($artist === null || $track->album === null || $track->duration_ms === null) {
            return $this->state('not_found', $this->lyricsProvider->key());
        }

        $lookup = new LyricsLookup(
            $track->id,
            $track->title,
            $artist->name,
            $track->album->title,
            max(0, (int) round($track->duration_ms / 1000)),
        );

        return $this->resolve(
            $this->lyricsProvider->key(),
            OnlineContentType::Lyrics,
            $lookup,
            fn () => $this->lyricsProvider->fetchLyrics($lookup),
        );
    }

    /** @param array<string, mixed> $payload */
    public function refreshLookup(string $provider, OnlineContentType $type, array $payload): void
    {
        if (! $this->providerIsEnabled($provider)) {
            return;
        }

        $lookup = $this->lookupFromPayload($type, $payload);
        $this->resolve(
            $provider,
            $type,
            $lookup,
            fn () => $this->fetch($provider, $lookup),
            true,
        );
    }

    /**
     * @param callable(): object|null $fetch
     * @return array<string, mixed>
     */
    private function resolve(
        string $provider,
        OnlineContentType $type,
        CacheableLookup $lookup,
        callable $fetch,
        bool $backgroundRefresh = false,
    ): array {
        $cached = $this->cache->find($provider, $type, $lookup);
        if ($this->isFresh($cached)) {
            return $this->cachedState($cached);
        }

        if ($backgroundRefresh && $this->isStale($cached) && $cached->retry_after?->isFuture()) {
            return $this->cachedState($cached, true, true);
        }

        if (! $backgroundRefresh && $this->isStale($cached)) {
            $this->dispatchRefreshUnlessBackingOff($provider, $type, $lookup, $cached);

            return $this->cachedState($cached, true, true);
        }

        $lock = Cache::lock(
            $this->cache->lockKey($provider, $type, $lookup),
            max(5, (int) config('music-library.enrichment.lock_seconds', 30)),
        );

        try {
            return $lock->block(
                max(1, (int) config('music-library.enrichment.lock_wait_seconds', 12)),
                function () use ($provider, $type, $lookup, $fetch, $backgroundRefresh): array {
                    $latest = $this->cache->find($provider, $type, $lookup);
                    if ($this->isFresh($latest)) {
                        return $this->cachedState($latest);
                    }

                    if (! $backgroundRefresh && $this->isStale($latest)) {
                        $this->dispatchRefreshUnlessBackingOff($provider, $type, $lookup, $latest);

                        return $this->cachedState($latest, true, true);
                    }

                    return $this->fetchAndStore($provider, $type, $lookup, $fetch, $latest);
                },
            );
        } catch (LockTimeoutException) {
            $latest = $this->cache->find($provider, $type, $lookup);
            if ($this->isFresh($latest) || $this->isStale($latest)) {
                return $this->cachedState($latest, true, $this->isStale($latest));
            }

            return $this->state(OnlineContentStatus::Pending->value, $provider);
        }
    }

    /**
     * @param callable(): object|null $fetch
     * @return array<string, mixed>
     */
    private function fetchAndStore(
        string $provider,
        OnlineContentType $type,
        CacheableLookup $lookup,
        callable $fetch,
        ?OnlineContentCache $previous,
    ): array {
        try {
            $content = $this->requestGate->run($provider, $fetch);
            if ($content === null) {
                $cache = $this->cache->store(
                    $provider,
                    $type,
                    $lookup,
                    OnlineContentStatus::NotFound,
                    null,
                    now()->addHours(max(1, (int) config('music-library.enrichment.not_found_cache_hours', 24))),
                );

                return $this->cachedState($cache, false);
            }

            /** @var array<string, mixed> $payload */
            $payload = $content->toArray();
            $attribution = is_array($payload['attribution'] ?? null) ? $payload['attribution'] : [];
            $expiresAt = now()->addDays(max(1, (int) config('music-library.enrichment.ready_cache_days', 30)));
            $cache = $this->cache->store(
                $provider,
                $type,
                $lookup,
                OnlineContentStatus::Ready,
                $payload,
                $expiresAt,
                $expiresAt->copy()->addDays(max(1, (int) config('music-library.enrichment.stale_cache_days', 7))),
                providerReference: is_string($payload['providerReference'] ?? null) ? $payload['providerReference'] : null,
                sourceUrl: is_string($attribution['sourceUrl'] ?? null) ? $attribution['sourceUrl'] : null,
            );

            return $this->cachedState($cache, false);
        } catch (EnrichmentProviderException $exception) {
            return $this->storeFailure($provider, $type, $lookup, $previous, $exception);
        } catch (AmbiguousEnrichmentMatchException) {
            $cache = $this->cache->store(
                $provider,
                $type,
                $lookup,
                OnlineContentStatus::Ambiguous,
                null,
                now()->addHours(max(1, (int) config('music-library.enrichment.not_found_cache_hours', 24))),
            );

            return $this->cachedState($cache, false);
        }
    }

    /** @return array<string, mixed> */
    private function storeFailure(
        string $provider,
        OnlineContentType $type,
        CacheableLookup $lookup,
        ?OnlineContentCache $previous,
        EnrichmentProviderException $exception,
    ): array {
        $failureCount = max(1, ($previous?->failure_count ?? 0) + 1);
        $baseMinutes = max(1, (int) config('music-library.enrichment.error_retry_minutes', 15));
        $maximumMinutes = max($baseMinutes, (int) config('music-library.enrichment.max_error_retry_minutes', 360));
        $backoffMinutes = min($maximumMinutes, $baseMinutes * (2 ** min(5, $failureCount - 1)));
        $providerMinutes = $exception->retryAfterSeconds === null
            ? 0
            : (int) ceil($exception->retryAfterSeconds / 60);
        $retryAfter = now()->addMinutes(max($backoffMinutes, $providerMinutes));

        if ($this->isStale($previous)) {
            $cache = $this->cache->markFailure(
                $previous,
                $retryAfter,
                $exception->errorCode,
                $failureCount,
            );

            return $this->cachedState($cache, true, true);
        }

        $cache = $this->cache->store(
            $provider,
            $type,
            $lookup,
            OnlineContentStatus::Error,
            ['errorCode' => $exception->errorCode],
            $retryAfter,
            retryAfter: $retryAfter,
            failureCount: $failureCount,
            lastErrorCode: $exception->errorCode,
        );

        return $this->cachedState($cache, false);
    }

    private function dispatchRefreshUnlessBackingOff(
        string $provider,
        OnlineContentType $type,
        CacheableLookup $lookup,
        OnlineContentCache $cache,
    ): void {
        if ($cache->retry_after?->isFuture()) {
            return;
        }

        RefreshOnlineEnrichment::dispatch(
            $provider,
            $type->value,
            $lookup->cachePayload(),
            $this->cache->lookupHash($lookup),
        );
    }

    private function isFresh(?OnlineContentCache $cache): bool
    {
        if ($cache === null) {
            return false;
        }

        if ($cache->status === OnlineContentStatus::Error) {
            return $cache->retry_after?->isFuture() ?? false;
        }

        return $cache->expires_at?->isFuture() ?? false;
    }

    private function isStale(?OnlineContentCache $cache): bool
    {
        return $cache?->status === OnlineContentStatus::Ready
            && $cache->payload !== null
            && ($cache->expires_at?->isPast() ?? false)
            && ($cache->stale_until?->isFuture() ?? false);
    }

    /** @return array<string, mixed> */
    private function cachedState(
        OnlineContentCache $cache,
        bool $cached = true,
        bool $stale = false,
    ): array {
        $isError = $cache->status === OnlineContentStatus::Error;
        $errorCode = $isError && is_string($cache->payload['errorCode'] ?? null)
            ? $cache->payload['errorCode']
            : null;

        return $this->state(
            $cache->status->value,
            $cache->provider,
            $isError ? null : $cache->payload,
            $cached,
            $errorCode,
            $stale,
        );
    }

    /** @return array<string, mixed> */
    private function state(
        string $status,
        ?string $provider = null,
        ?array $data = null,
        bool $cached = false,
        ?string $errorCode = null,
        bool $stale = false,
    ): array {
        return [
            'status' => $status,
            'provider' => $provider,
            'cached' => $cached,
            'stale' => $stale,
            'data' => $data,
            'errorCode' => $errorCode,
        ];
    }

    private function providerIsEnabled(string $provider): bool
    {
        $settings = ApplicationSetting::current();

        return match ($provider) {
            'lastfm' => $settings->online_information_enabled && filled($settings->lastfm_api_key),
            'lrclib' => $settings->online_lyrics_enabled,
            'musicbrainz' => $settings->online_information_enabled,
            default => false,
        };
    }

    /** @param array<string, mixed> $payload */
    private function lookupFromPayload(OnlineContentType $type, array $payload): CacheableLookup
    {
        return match ($type) {
            OnlineContentType::Artist => new ArtistLookup(
                (int) ($payload['artistId'] ?? 0),
                (string) ($payload['name'] ?? ''),
                is_array($payload['externalIds'] ?? null) ? $payload['externalIds'] : [],
                (string) ($payload['language'] ?? 'en'),
                isset($payload['cacheVariant']) ? (string) $payload['cacheVariant'] : null,
            ),
            OnlineContentType::Album => new AlbumLookup(
                (int) ($payload['albumId'] ?? 0),
                (string) ($payload['title'] ?? ''),
                (string) ($payload['artistName'] ?? ''),
                isset($payload['releaseYear']) ? (int) $payload['releaseYear'] : null,
                is_array($payload['externalIds'] ?? null) ? $payload['externalIds'] : [],
                (string) ($payload['language'] ?? 'en'),
                isset($payload['cacheVariant']) ? (string) $payload['cacheVariant'] : null,
            ),
            OnlineContentType::Lyrics => new LyricsLookup(
                (int) ($payload['trackId'] ?? 0),
                (string) ($payload['title'] ?? ''),
                (string) ($payload['artistName'] ?? ''),
                isset($payload['albumTitle']) ? (string) $payload['albumTitle'] : null,
                isset($payload['durationSeconds']) ? (int) $payload['durationSeconds'] : null,
            ),
        };
    }

    private function fetch(string $provider, CacheableLookup $lookup): object|null
    {
        return match (true) {
            $provider === 'lastfm' && $lookup instanceof ArtistLookup => $this->informationProvider->fetchArtist($lookup),
            $provider === 'lastfm' && $lookup instanceof AlbumLookup => $this->informationProvider->fetchAlbum($lookup),
            $provider === 'musicbrainz' && $lookup instanceof ArtistLookup => $this->identityProvider->fetchArtist($lookup),
            $provider === 'musicbrainz' && $lookup instanceof AlbumLookup => $this->identityProvider->fetchAlbum($lookup),
            $provider === 'lrclib' && $lookup instanceof LyricsLookup => $this->lyricsProvider->fetchLyrics($lookup),
            default => throw new InvalidArgumentException('Unsupported online enrichment provider or lookup type.'),
        };
    }
}
