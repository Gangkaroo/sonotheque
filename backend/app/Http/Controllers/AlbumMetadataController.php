<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Music\Metadata\AlbumMetadataEditing;
use App\Support\MetadataEditPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumMetadataController extends Controller
{
    public function __construct(private readonly MetadataEditPayloads $payloads)
    {
    }

    public function preview(Request $request, Album $album, AlbumMetadataEditing $editing): JsonResponse
    {
        return response()->json($editing->preview($album, $this->values($request)));
    }

    public function store(Request $request, Album $album, AlbumMetadataEditing $editing): JsonResponse
    {
        $validated = $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);
        $job = $editing->queue($album, $this->values($request), $validated['fingerprint']);

        return response()->json($this->payloads->job($job), 202);
    }

    /** @return array{albumTitle: string, albumArtist: string, releaseYear: ?int, totalDiscs: ?int, genres: list<string>, comment?: ?string} */
    private function values(Request $request): array
    {
        $validated = $request->validate([
            'albumTitle' => ['required', 'string', 'max:512', 'not_regex:/^\s*$/'],
            'albumArtist' => ['required', 'string', 'max:512', 'not_regex:/^\s*$/'],
            'releaseYear' => ['present', 'nullable', 'integer', 'min:1000', 'max:9999'],
            'totalDiscs' => ['present', 'nullable', 'integer', 'min:1', 'max:65535'],
            'genres' => ['present', 'array', 'max:50'],
            'genres.*' => ['string', 'max:255', 'not_regex:/^\s*$/'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
        $genres = collect($validated['genres'])
            ->map(fn (string $genre) => trim($genre))
            ->unique(fn (string $genre) => mb_strtolower($genre))
            ->values()
            ->all();

        $values = [
            'albumTitle' => trim($validated['albumTitle']),
            'albumArtist' => trim($validated['albumArtist']),
            'releaseYear' => $validated['releaseYear'],
            'totalDiscs' => $validated['totalDiscs'],
            'genres' => $genres,
        ];
        if (array_key_exists('comment', $validated)) {
            $values['comment'] = filled($validated['comment']) ? trim($validated['comment']) : null;
        }

        return $values;
    }
}
