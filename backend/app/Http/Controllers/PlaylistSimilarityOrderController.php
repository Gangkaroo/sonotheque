<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Music\Intelligence\AudioSimilarityPlaylistOrderer;
use App\Music\Playlists\PlaylistFileSynchronizationDispatcher;
use App\Support\LibraryRootScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaylistSimilarityOrderController extends Controller
{
    public function __construct(
        private readonly AudioSimilarityPlaylistOrderer $orderer,
        private readonly LibraryRootScope $libraryRootScope,
        private readonly PlaylistFileSynchronizationDispatcher $synchronizationDispatcher,
    ) {
    }

    public function show(Request $request, Playlist $playlist): JsonResponse
    {
        $this->requireAllRoots($request);

        return response()->json($this->orderer->status($playlist));
    }

    public function preview(Request $request, Playlist $playlist): JsonResponse
    {
        $this->requireAllRoots($request);
        $validated = $request->validate([
            'openingItemId' => ['required', 'integer'],
        ]);

        return response()->json($this->orderer->preview($playlist, $validated['openingItemId']));
    }

    public function apply(Request $request, Playlist $playlist): JsonResponse
    {
        $this->requireAllRoots($request);
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:2', 'max:500'],
            'items.*' => ['integer', 'distinct'],
            'orderSignature' => ['required', 'string', 'size:64'],
        ]);
        $result = $this->orderer->apply($playlist, $validated['items'], $validated['orderSignature']);
        $this->synchronizationDispatcher->playlist($playlist);

        return response()->json($result);
    }

    public function restore(Request $request, Playlist $playlist): JsonResponse
    {
        $this->requireAllRoots($request);
        $result = $this->orderer->restore($playlist);
        $this->synchronizationDispatcher->playlist($playlist);

        return response()->json($result);
    }

    private function requireAllRoots(Request $request): void
    {
        abort_if(
            $this->libraryRootScope->id($request) !== null,
            409,
            'Playlist ordering is only available while all library roots are shown.',
        );
    }
}
