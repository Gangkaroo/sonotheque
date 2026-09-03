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
        $recordLabels = $labels->map(fn (array $label): array => [
            'name' => trim($label['name']),
            'catalogNumber' => is_string($label['catno'] ?? null) && trim($label['catno']) !== ''
                ? trim($label['catno'])
                : null,
        ])->all();
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
            'recordLabels' => $recordLabels,
            'thumbnailUrl' => $this->images->register(is_array($image) ? $image['uri150'] : null),
            'webUrl' => $this->releaseWebUrl($payload['uri'] ?? null, (int) $payload['id']),
            'musicianCredits' => $this->musicianCredits($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function musicianCredits(array $payload): array
    {
        $releaseId = (int) $payload['id'];
        $credits = [];

        foreach ($this->arrays($payload['extraartists'] ?? null) as $artist) {
            $positions = $this->creditPositions($artist['tracks'] ?? null);
            if ($positions === []) {
                $this->appendMusicianCredit($credits, $artist, $releaseId, null, null);

                continue;
            }

            foreach ($positions as $position) {
                $this->appendMusicianCredit($credits, $artist, $releaseId, $position, null);
            }
        }

        foreach ($this->trackEntries($payload['tracklist'] ?? null) as $track) {
            $position = $this->text($track['position'] ?? null);
            $title = $this->text($track['title'] ?? null);
            foreach ($this->arrays($track['extraartists'] ?? null) as $artist) {
                $this->appendMusicianCredit($credits, $artist, $releaseId, $position, $title);
            }
        }

        return array_values($credits);
    }

    /**
     * @param  array<string, array<string, mixed>>  $credits
     * @param  array<string, mixed>  $artist
     */
    private function appendMusicianCredit(
        array &$credits,
        array $artist,
        int $releaseId,
        ?string $trackPosition,
        ?string $trackTitle,
    ): void {
        $name = $this->text($artist['name'] ?? null, 255);
        if ($name === null) {
            return;
        }

        $providerReference = is_numeric($artist['id'] ?? null) && (int) $artist['id'] > 0
            ? (string) (int) $artist['id']
            : 'release-'.$releaseId.'-'.hash('sha256', mb_strtolower($name));
        $role = $this->text($artist['role'] ?? null, 255) ?? 'performer';
        $sourceReference = 'release:'.$releaseId;
        if ($trackPosition !== null) {
            $sourceReference .= ':track:'.$trackPosition;
        }
        $lowerRole = mb_strtolower($role);
        $credit = [
            'providerReference' => $providerReference,
            'name' => $name,
            'sortName' => null,
            'entityType' => 'person',
            'trackPosition' => $trackPosition,
            'trackTitle' => $trackTitle,
            'sourceEntityType' => $trackPosition === null ? 'release' : 'recording',
            'sourceEntityReference' => mb_substr($sourceReference, 0, 128),
            'relationshipType' => 'extraartist',
            'role' => $role,
            'creditedAs' => $this->text($artist['anv'] ?? null, 255),
            'attributes' => [$role],
            'guest' => str_contains($lowerRole, 'guest'),
            'additional' => str_contains($lowerRole, 'additional'),
        ];
        $key = hash('sha256', implode('|', [
            $providerReference,
            $trackPosition ?? 'album',
            $role,
            $credit['creditedAs'] ?? '',
        ]));
        $credits[$key] = $credit;
    }

    /** @return list<string> */
    private function creditPositions(mixed $value): array
    {
        $tracks = $this->text($value);
        if ($tracks === null || preg_match('/\b(?:to|through)\b|\s-\s/i', $tracks) === 1) {
            return [];
        }

        return collect(preg_split('/[,;\s]+/', $tracks) ?: [])
            ->map(fn (string $position): string => trim($position))
            ->filter(fn (string $position): bool => $position !== '' && mb_strlen($position) <= 24)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function trackEntries(mixed $tracklist): array
    {
        $tracks = [];
        foreach ($this->arrays($tracklist) as $track) {
            if ($this->text($track['position'] ?? null) !== null) {
                $tracks[] = $track;
            }
            $tracks = [...$tracks, ...$this->trackEntries($track['sub_tracks'] ?? null)];
        }

        return $tracks;
    }

    /** @return list<array<string, mixed>> */
    private function arrays(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter($values, 'is_array'))
            : [];
    }

    private function text(mixed $value, ?int $maximumLength = null): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return $maximumLength === null ? $text : mb_substr($text, 0, $maximumLength);
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
