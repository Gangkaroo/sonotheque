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

    public function link(Request $request, Album $album, OwnedAlbumCopy $ownedAlbumCopy): JsonResponse
    {
        $this->ensureCopyBelongsToAlbum($album, $ownedAlbumCopy);
        $validated = $request->validate([
            'releaseId' => ['required', 'integer', 'min:1'],
        ]);
        $settings = $this->connectedSettings();

        try {
            $release = $this->discogs->release(
                $settings->discogs_personal_access_token,
                $validated['releaseId'],
            );
            $instances = $this->discogs->collectionInstances(
                $settings->discogs_personal_access_token,
                $settings->discogs_username,
                $release['id'],
            );
        } catch (DiscogsApiException $exception) {
            throw ValidationException::withMessages(['discogs' => $exception->getMessage()]);
        }

        $instance = count($instances) === 1 ? $instances[0] : null;
        if ($instance !== null && OwnedAlbumCopy::query()
            ->whereKeyNot($ownedAlbumCopy->id)
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

    /** @return array<string, mixed> */
    private function personalMetadata(Album $album): array
    {
        $album->load(['personalMetadata', 'ownedCopies']);

        return $this->payloads->albumPersonalMetadata($album);
    }
}
