<?php

namespace App\Music\Discogs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DiscogsApiClient
{
    public function __construct(private readonly DiscogsImageCache $images)
    {
    }

    /** @return array{id: int, username: string, resourceUrl: ?string} */
    public function identity(string $personalAccessToken): array
    {
        $payload = $this->payload($this->request('oauth/identity', $personalAccessToken));
        $id = $payload['id'] ?? null;
        $username = $payload['username'] ?? null;

        if (! is_numeric($id) || ! is_string($username) || trim($username) === '') {
            throw new DiscogsApiException('Discogs returned an invalid account identity.');
        }

        return [
            'id' => (int) $id,
            'username' => trim($username),
            'resourceUrl' => is_string($payload['resource_url'] ?? null)
                ? $payload['resource_url']
                : null,
        ];
    }

    /**
     * @param  array{artist: string, title: string, year?: int|null, format?: string|null, country?: string|null, barcode?: string|null, catalogNumber?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function searchReleases(string $personalAccessToken, string $username, array $filters): array
    {
        $query = array_filter([
            'type' => 'release',
            'artist' => $filters['artist'],
            'release_title' => $filters['title'],
            'year' => $filters['year'] ?? null,
            'format' => $filters['format'] ?? null,
            'country' => $filters['country'] ?? null,
            'barcode' => $filters['barcode'] ?? null,
            'catno' => $filters['catalogNumber'] ?? null,
            'per_page' => 25,
            'page' => 1,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $cacheKey = 'discogs:search:'.hash('sha256', $username.'|'.json_encode($query, JSON_THROW_ON_ERROR));

        $payload = Cache::remember(
            $cacheKey,
            now()->addMinutes(10),
            fn (): array => $this->payload($this->request('database/search', $personalAccessToken, $query)),
        );

        return collect($payload['results'] ?? [])
            ->filter(fn (mixed $result): bool => is_array($result)
                && ($result['type'] ?? null) === 'release'
                && is_numeric($result['id'] ?? null))
            ->map(fn (array $result): array => $this->releaseSummary($result))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function release(string $personalAccessToken, int $releaseId, bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget("discogs:release:{$releaseId}");
        }

        $payload = Cache::remember(
            "discogs:release:{$releaseId}",
            now()->addDay(),
            fn (): array => $this->payload($this->request("releases/{$releaseId}", $personalAccessToken)),
        );

        if (! is_numeric($payload['id'] ?? null)) {
            throw new DiscogsApiException('Discogs returned an invalid release.');
        }

        return $this->releaseDetails($payload);
    }

    /** @return list<array{instanceId: int, folderId: int, dateAdded: ?string, rating: ?int}> */
    public function collectionInstances(
        string $personalAccessToken,
        string $username,
        int $releaseId,
        bool $refresh = false,
    ): array {
        $cacheKey = "discogs:collection:{$username}:release:{$releaseId}";
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $payload = Cache::remember(
            $cacheKey,
            now()->addMinutes(5),
            fn (): array => $this->payload($this->request(
                'users/'.rawurlencode($username)."/collection/releases/{$releaseId}",
                $personalAccessToken,
                ['per_page' => 100, 'page' => 1],
            )),
        );

        return collect($payload['releases'] ?? [])
            ->filter(fn (mixed $release): bool => is_array($release)
                && is_numeric($release['instance_id'] ?? null)
                && is_numeric($release['folder_id'] ?? null))
            ->map(fn (array $release): array => [
                'instanceId' => (int) $release['instance_id'],
                'folderId' => (int) $release['folder_id'],
                'dateAdded' => is_string($release['date_added'] ?? null) ? $release['date_added'] : null,
                'rating' => is_numeric($release['rating'] ?? null) ? (int) $release['rating'] : null,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function collectionFolders(
        string $personalAccessToken,
        string $username,
        bool $refresh = false,
    ): array {
        $cacheKey = "discogs:collection:{$username}:folders";
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $payload = Cache::remember(
            $cacheKey,
            now()->addMinutes(10),
            fn (): array => $this->payload($this->request(
                'users/'.rawurlencode($username).'/collection/folders',
                $personalAccessToken,
            )),
        );

        return collect($payload['folders'] ?? [])
            ->filter(fn (mixed $folder): bool => is_array($folder)
                && is_numeric($folder['id'] ?? null)
                && is_string($folder['name'] ?? null))
            ->mapWithKeys(fn (array $folder): array => [(int) $folder['id'] => trim($folder['name'])])
            ->all();
    }

    /** @param array<string, int|string> $query */
    private function request(string $path, string $personalAccessToken, array $query = []): Response
    {
        $configuration = config('sonotheque.discogs');
        $caBundle = trim((string) ($configuration['ca_bundle'] ?? ''));

        try {
            return Http::acceptJson()
                ->withUserAgent((string) ($configuration['user_agent'] ?? ''))
                ->withHeaders(['Authorization' => 'Discogs token='.$personalAccessToken])
                ->withOptions([
                    'proxy' => (string) ($configuration['proxy'] ?? ''),
                    'verify' => $caBundle !== '' ? $caBundle : true,
                ])
                ->timeout(max(1, (int) ($configuration['timeout_seconds'] ?? 20)))
                ->retry(2, 500, throw: false)
                ->get(rtrim((string) $configuration['api_url'], '/').'/'.ltrim($path, '/'), $query);
        } catch (ConnectionException $exception) {
            throw new DiscogsApiException(
                'Discogs could not be reached: '.$exception->getMessage(),
                true,
            );
        }
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $payload = $response->json();

        if ($response->status() === 401) {
            throw new DiscogsApiException('Discogs rejected the personal access token.');
        }

        if ($response->failed()) {
            if ($response->status() === 429) {
                throw new DiscogsApiException('The Discogs request limit was reached. Try again shortly.', true);
            }

            $message = is_array($payload) && is_string($payload['message'] ?? null)
                ? $payload['message']
                : 'Discogs returned an HTTP error.';

            throw new DiscogsApiException(
                $message,
                $response->serverError() || $response->status() === 429,
            );
        }

        if (! is_array($payload)) {
            throw new DiscogsApiException('Discogs returned an invalid response.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $result */
    private function releaseSummary(array $result): array
    {
        return [
            'releaseId' => (int) $result['id'],
            'masterId' => is_numeric($result['master_id'] ?? null) ? (int) $result['master_id'] : null,
            'title' => is_string($result['title'] ?? null) ? trim($result['title']) : '',
            'year' => is_numeric($result['year'] ?? null) ? (int) $result['year'] : null,
            'country' => is_string($result['country'] ?? null) ? trim($result['country']) : null,
            'formats' => collect($result['format'] ?? [])->filter(
                static fn (mixed $value): bool => is_string($value),
            )->values()->all(),
            'labels' => collect($result['label'] ?? [])->filter(
                static fn (mixed $value): bool => is_string($value),
            )->values()->all(),
            'catalogNumber' => is_string($result['catno'] ?? null) ? trim($result['catno']) : null,
            'thumbnailUrl' => $this->images->register(
                is_string($result['thumb'] ?? null) && $result['thumb'] !== '' ? $result['thumb'] : null,
            ),
            'webUrl' => $this->releaseWebUrl($result['uri'] ?? null, (int) $result['id']),
            'inCollection' => (bool) data_get($result, 'user_data.in_collection', false),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function releaseDetails(array $payload): array
    {
        $formats = collect($payload['formats'] ?? [])
            ->filter(fn (mixed $format): bool => is_array($format) && is_string($format['name'] ?? null))
            ->map(function (array $format): string {
                $description = collect($format['descriptions'] ?? [])
                    ->filter(static fn (mixed $value): bool => is_string($value))
                    ->join(', ');

                return trim($format['name'].($description !== '' ? ' ('.$description.')' : ''));
            })
            ->values()
            ->all();
        $labels = collect($payload['labels'] ?? [])
            ->filter(fn (mixed $label): bool => is_array($label) && is_string($label['name'] ?? null))
            ->values();
        $image = collect($payload['images'] ?? [])->first(
            fn (mixed $item): bool => is_array($item) && is_string($item['uri150'] ?? null),
        );

        return [
            'id' => (int) $payload['id'],
            'masterId' => is_numeric($payload['master_id'] ?? null) ? (int) $payload['master_id'] : null,
            'title' => is_string($payload['title'] ?? null) ? trim($payload['title']) : '',
            'artist' => is_string($payload['artists_sort'] ?? null) ? trim($payload['artists_sort']) : '',
            'year' => is_numeric($payload['year'] ?? null) ? (int) $payload['year'] : null,
            'country' => is_string($payload['country'] ?? null) ? trim($payload['country']) : null,
            'formats' => $formats,
            'labels' => $labels->pluck('name')->all(),
            'catalogNumber' => $labels->pluck('catno')->first(
                fn (mixed $value): bool => is_string($value) && trim($value) !== '',
            ),
            'thumbnailUrl' => $this->images->register(is_array($image) ? $image['uri150'] : null),
            'webUrl' => $this->releaseWebUrl($payload['uri'] ?? null, (int) $payload['id']),
        ];
    }

    private function releaseWebUrl(mixed $uri, int $releaseId): string
    {
        $baseUrl = rtrim((string) config('sonotheque.discogs.web_url'), '/');
        $path = null;
        if (is_string($uri) && trim($uri) !== '') {
            $candidate = trim($uri);
            $parts = parse_url($candidate);
            if (is_array($parts) && isset($parts['host'])) {
                $configuredHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
                if (strtolower((string) $parts['host']) === $configuredHost) {
                    $path = $parts['path'] ?? null;
                }
            } elseif (str_starts_with('/'.ltrim($candidate, '/'), '/release/')) {
                $path = '/'.ltrim($candidate, '/');
            }
        }

        if (! is_string($path) || ! str_starts_with($path, '/release/')) {
            $path = "/release/{$releaseId}";
        }

        return $baseUrl.$path;
    }
}
