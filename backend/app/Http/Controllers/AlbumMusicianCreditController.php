<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\ManualAlbumMusicianCredit;
use App\Models\OwnedAlbumCopy;
use App\Music\Discogs\DiscogsApiException;
use App\Music\Enrichment\AlbumMusicianCreditManager;
use App\Music\Enrichment\DiscogsMusicianCreditImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumMusicianCreditController extends Controller
{
    public function __construct(
        private readonly AlbumMusicianCreditManager $credits,
        private readonly DiscogsMusicianCreditImporter $discogs,
    ) {
    }

    public function index(Album $album): JsonResponse
    {
        return response()->json($this->credits->editorForAlbum($album));
    }

    public function store(Request $request, Album $album): JsonResponse
    {
        return response()->json($this->credits->createManualCredit(
            $album,
            $this->validated($request),
        ), 201);
    }

    public function update(
        Request $request,
        Album $album,
        ManualAlbumMusicianCredit $manualCredit,
    ): JsonResponse {
        return response()->json($this->credits->updateManualCredit(
            $album,
            $manualCredit,
            $this->validated($request),
        ));
    }

    public function destroy(
        Album $album,
        ManualAlbumMusicianCredit $manualCredit,
    ): JsonResponse {
        return response()->json($this->credits->deleteManualCredit($album, $manualCredit));
    }

    public function suppress(Album $album, string $sourceKey): JsonResponse
    {
        return response()->json($this->credits->suppressImportedCredit($album, $sourceKey));
    }

    public function restore(Album $album, string $sourceKey): JsonResponse
    {
        return response()->json($this->credits->restoreImportedCredit($album, $sourceKey));
    }

    public function selectDiscogsSource(Request $request, Album $album): JsonResponse
    {
        $validated = $request->validate([
            'sourceType' => ['required', 'string', 'in:owned_copy,musicbrainz'],
            'ownedCopyId' => ['nullable', 'required_if:sourceType,owned_copy', 'integer', 'exists:owned_album_copies,id'],
            'releaseId' => ['nullable', 'required_if:sourceType,musicbrainz', 'integer', 'min:1'],
            'refresh' => ['sometimes', 'boolean'],
        ]);
        try {
            if ($validated['sourceType'] === 'owned_copy') {
                $copy = OwnedAlbumCopy::query()->findOrFail($validated['ownedCopyId']);
                $this->discogs->select($album, $copy, (bool) ($validated['refresh'] ?? false));
            } else {
                $this->discogs->selectMusicBrainzRelease(
                    $album,
                    (int) $validated['releaseId'],
                    (bool) ($validated['refresh'] ?? false),
                );
            }
        } catch (DiscogsApiException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discogs' => $exception->getMessage(),
            ]);
        }

        return response()->json($this->credits->editorForAlbum($album));
    }

    public function clearDiscogsSource(Album $album): JsonResponse
    {
        $this->discogs->clear($album);

        return response()->json($this->credits->editorForAlbum($album));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'musicianId' => ['nullable', 'integer', 'exists:musicians,id'],
            'name' => ['nullable', 'required_without:musicianId', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
            'creditedAs' => ['nullable', 'string', 'max:255'],
            'guest' => ['sometimes', 'boolean'],
            'additional' => ['sometimes', 'boolean'],
            'trackIds' => ['sometimes', 'array'],
            'trackIds.*' => ['integer', 'distinct'],
        ]);
    }
}
