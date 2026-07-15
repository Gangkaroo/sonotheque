<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\OwnedAlbumCopy;
use App\Music\Discogs\DiscogsApiClient;
use App\Music\Discogs\DiscogsApiException;
use App\Support\CatalogPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AlbumDiscogsController extends Controller
{
    public function __construct(
        private readonly DiscogsApiClient $discogs,
        private readonly CatalogPayloads $payloads,
    ) {
    }

    public function candidates(Request $request, Album $album): JsonResponse
    {
        $validated = $request->validate([
            'artist' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:512'],
            'year' => ['nullable', 'integer', 'between:1000,9999'],
            'format' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'max:128'],
            'barcode' => ['nullable', 'string', 'max:128'],
            'catalogNumber' => ['nullable', 'string', 'max:128'],
        ]);
        $settings = $this->connectedSettings();

        try {
            $candidates = $this->discogs->searchReleases(
                $settings->discogs_personal_access_token,
                $settings->discogs_username,
                $validated,
            );
        } catch (DiscogsApiException $exception) {
            throw ValidationException::withMessages(['discogs' => $exception->getMessage()]);
        }

        return response()->json(['items' => $candidates]);
    }

    public function collectionInstances(Request $request, Album $album, int $releaseId): JsonResponse
    {
        $validated = $request->validate(['refresh' => ['sometimes', 'boolean']]);
        $settings = $this->connectedSettings();

        try {
            $instances = $this->collectionInstancePayloads(
                $settings,
                $releaseId,
                (bool) ($validated['refresh'] ?? false),
            );
        } catch (DiscogsApiException $exception) {
            throw ValidationException::withMessages(['discogs' => $exception->getMessage()]);
        }

        return response()->json(['items' => $instances]);
    }

    public function show(Album $album, OwnedAlbumCopy $ownedAlbumCopy): JsonResponse
    {
        $this->ensureCopyBelongsToAlbum($album, $ownedAlbumCopy);
        abort_unless($ownedAlbumCopy->provider === 'discogs' && $ownedAlbumCopy->external_release_id !== null, 404);
        $settings = $this->connectedSettings();

        try {
            $release = $this->discogs->release(
                $settings->discogs_personal_access_token,
                $ownedAlbumCopy->external_release_id,
            );
            $folders = $ownedAlbumCopy->external_folder_id !== null
                ? $this->discogs->collectionFolders(
                    $settings->discogs_personal_access_token,
                    $settings->discogs_username,
                )
                : [];
        } catch (DiscogsApiException $exception) {
            throw ValidationException::withMessages(['discogs' => $exception->getMessage()]);
        }

        return response()->json([
            'release' => $release,
            'collectionInstance' => $ownedAlbumCopy->external_collection_instance_id !== null ? [
                'instanceId' => $ownedAlbumCopy->external_collection_instance_id,
                'folderId' => $ownedAlbumCopy->external_folder_id,
                'folderName' => $folders[$ownedAlbumCopy->external_folder_id] ?? null,
            ] : null,
            'syncedAt' => $ownedAlbumCopy->provider_synced_at?->toJSON(),
        ]);
    }

    public function link(Request $request, Album $album, OwnedAlbumCopy $ownedAlbumCopy): JsonResponse
    {
        $this->ensureCopyBelongsToAlbum($album, $ownedAlbumCopy);
        $validated = $request->validate([
            'releaseId' => ['required', 'integer', 'min:1'],
            'collectionInstanceId' => ['nullable', 'integer', 'min:1'],
        ]);
        $settings = $this->connectedSettings();

        try {
            $release = $this->discogs->release(
                $settings->discogs_personal_access_token,
                $validated['releaseId'],
            );
            $instances = $this->collectionInstancePayloads($settings, $release['id']);
        } catch (DiscogsApiException $exception) {
            throw ValidationException::withMessages(['discogs' => $exception->getMessage()]);
        }

        $instance = $this->selectInstance($instances, $validated['collectionInstanceId'] ?? null);
        $this->updateLink($ownedAlbumCopy, $release, $instance);

        return response()->json($this->personalMetadata($album));
    }

    public function refresh(Request $request, Album $album, OwnedAlbumCopy $ownedAlbumCopy): JsonResponse
    {
        $this->ensureCopyBelongsToAlbum($album, $ownedAlbumCopy);
        abort_unless($ownedAlbumCopy->provider === 'discogs' && $ownedAlbumCopy->external_release_id !== null, 404);
        $validated = $request->validate([
            'collectionInstanceId' => ['nullable', 'integer', 'min:1'],
        ]);
        $settings = $this->connectedSettings();

        try {
            $release = $this->discogs->release(
                $settings->discogs_personal_access_token,
                $ownedAlbumCopy->external_release_id,
                true,
            );
            $instances = $this->collectionInstancePayloads($settings, $release['id'], true);
        } catch (DiscogsApiException $exception) {
            throw ValidationException::withMessages(['discogs' => $exception->getMessage()]);
        }

        $requestedInstanceId = $validated['collectionInstanceId']
            ?? $ownedAlbumCopy->external_collection_instance_id;
        $instance = $this->selectInstance($instances, $requestedInstanceId);
        $this->updateLink($ownedAlbumCopy, $release, $instance);

        return response()->json($this->personalMetadata($album));
    }

    public function unlink(Album $album, OwnedAlbumCopy $ownedAlbumCopy): JsonResponse
    {
        $this->ensureCopyBelongsToAlbum($album, $ownedAlbumCopy);
        $ownedAlbumCopy->update([
            'provider' => null,
            'external_release_id' => null,
            'external_master_id' => null,
            'external_collection_instance_id' => null,
            'external_folder_id' => null,
            'provider_synced_at' => null,
        ]);

        return response()->json($this->personalMetadata($album));
    }

    private function connectedSettings(): ApplicationSetting
    {
        $settings = ApplicationSetting::current();
        if (! $settings->hasDiscogsConnection()) {
            throw ValidationException::withMessages([
                'discogs' => 'Connect a Discogs account in Settings before matching a release.',
            ]);
        }

        return $settings;
    }

    private function ensureCopyBelongsToAlbum(Album $album, OwnedAlbumCopy $ownedAlbumCopy): void
    {
        abort_unless($ownedAlbumCopy->album_id === $album->id, 404);
    }

    /** @return list<array{instanceId: int, folderId: int, folderName: ?string, dateAdded: ?string, rating: ?int}> */
    private function collectionInstancePayloads(
        ApplicationSetting $settings,
        int $releaseId,
        bool $refresh = false,
    ): array {
        $instances = $this->discogs->collectionInstances(
            $settings->discogs_personal_access_token,
            $settings->discogs_username,
            $releaseId,
            $refresh,
        );
        if ($instances === []) {
            return [];
        }

        $folders = $this->discogs->collectionFolders(
            $settings->discogs_personal_access_token,
            $settings->discogs_username,
            $refresh,
        );

        return collect($instances)->map(fn (array $instance): array => [
            ...$instance,
            'folderName' => $folders[$instance['folderId']] ?? null,
        ])->all();
    }

    /**
     * @param  list<array{instanceId: int, folderId: int, folderName: ?string, dateAdded: ?string, rating: ?int}>  $instances
     * @return array{instanceId: int, folderId: int, folderName: ?string, dateAdded: ?string, rating: ?int}|null
     */
    private function selectInstance(array $instances, ?int $requestedInstanceId): ?array
    {
        if ($requestedInstanceId !== null) {
            $instance = collect($instances)->firstWhere('instanceId', $requestedInstanceId);
            if ($instance === null) {
                throw ValidationException::withMessages([
                    'discogs' => 'The selected Discogs collection copy is no longer available.',
                ]);
            }

            return $instance;
        }

        if (count($instances) > 1) {
            throw ValidationException::withMessages([
                'discogs' => 'Choose which Discogs collection copy should be linked.',
            ]);
        }

        return $instances[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array{instanceId: int, folderId: int}|null  $instance
     */
    private function updateLink(OwnedAlbumCopy $ownedAlbumCopy, array $release, ?array $instance): void
    {
        if ($instance !== null && OwnedAlbumCopy::query()
            ->where('id', '!=', $ownedAlbumCopy->id)
            ->where('provider', 'discogs')
            ->where('external_collection_instance_id', $instance['instanceId'])
            ->exists()) {
            throw ValidationException::withMessages([
                'discogs' => 'This Discogs collection copy is already linked to another album.',
            ]);
        }

        $ownedAlbumCopy->update([
            'provider' => 'discogs',
            'external_release_id' => $release['id'],
            'external_master_id' => $release['masterId'],
            'external_collection_instance_id' => $instance['instanceId'] ?? null,
            'external_folder_id' => $instance['folderId'] ?? null,
            'provider_synced_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function personalMetadata(Album $album): array
    {
        $album->load(['personalMetadata', 'ownedCopies']);

        return $this->payloads->albumPersonalMetadata($album);
    }
}
