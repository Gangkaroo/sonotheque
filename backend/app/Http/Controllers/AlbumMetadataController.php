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

    /** @return array{albumTitle: string, albumArtist: string, updateTrackArtists: bool, releaseYear: ?int, totalDiscs: ?int, genres: list<string>, comment?: ?string, removedTagKeys: list<string>} */
    private function values(Request $request): array
    {
        $validated = $request->validate([
            'albumTitle' => ['required', 'string', 'max:512', 'not_regex:/^\s*$/'],
            'albumArtist' => ['required', 'string', 'max:512', 'not_regex:/^\s*$/'],
            'updateTrackArtists' => ['sometimes', 'boolean'],
            'releaseYear' => ['present', 'nullable', 'integer', 'min:1000', 'max:9999'],
            'totalDiscs' => ['present', 'nullable', 'integer', 'min:1', 'max:65535'],
            'genres' => ['present', 'array', 'max:50'],
            'genres.*' => ['string', 'max:255', 'not_regex:/^\s*$/'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'removedTagKeys' => ['sometimes', 'array', 'max:64'],
            'removedTagKeys.*' => [
                'string',
                'max:512',
                'distinct',
                'regex:/^[A-Z0-9]{4}(?::.+)?$/u',
            ],
        ]);
        $genres = collect($validated['genres'])
            ->map(fn (string $genre) => trim($genre))
            ->unique(fn (string $genre) => mb_strtolower($genre))
            ->values()
            ->all();

        $values = [
            'albumTitle' => trim($validated['albumTitle']),
            'albumArtist' => trim($validated['albumArtist']),
            'updateTrackArtists' => $validated['updateTrackArtists'] ?? true,
            'releaseYear' => $validated['releaseYear'],
            'totalDiscs' => $validated['totalDiscs'],
            'genres' => $genres,
            'removedTagKeys' => array_values($validated['removedTagKeys'] ?? []),
        ];
        if (array_key_exists('comment', $validated)) {
            $values['comment'] = filled($validated['comment']) ? trim($validated['comment']) : null;
        }

        return $values;
    }
}
