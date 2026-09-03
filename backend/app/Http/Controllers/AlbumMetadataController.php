<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Music\Catalog\RecordLabelNormalizer;
use App\Music\Metadata\AlbumMetadataEditing;
use App\Support\MetadataEditPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumMetadataController extends Controller
{
    public function __construct(
        private readonly MetadataEditPayloads $payloads,
        private readonly RecordLabelNormalizer $recordLabelNormalizer,
    ) {
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

    /** @return array<string, mixed> */
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
            'recordLabels' => ['sometimes', 'array', 'max:20'],
            'recordLabels.*.name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'recordLabels.*.catalogNumber' => ['sometimes', 'nullable', 'string', 'max:128'],
            'recordLabelProvenance' => ['sometimes', 'array', 'max:20'],
            'recordLabelProvenance.*.name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'recordLabelProvenance.*.catalogNumber' => ['sometimes', 'nullable', 'string', 'max:128'],
            'recordLabelProvenance.*.source' => ['required', 'string', 'in:musicbrainz,discogs'],
            'recordLabelProvenance.*.sourceReference' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
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
        if (array_key_exists('recordLabels', $validated)) {
            $values['recordLabels'] = collect($validated['recordLabels'])
                ->map(fn (array $recordLabel): array => [
                    'name' => $this->recordLabelNormalizer->displayName($recordLabel['name']),
                    'catalogNumber' => $this->recordLabelNormalizer->catalogNumber(
                        $recordLabel['catalogNumber'] ?? null,
                    ),
                ])
                ->unique(fn (array $recordLabel): string => $this->recordLabelNormalizer->normalizedName($recordLabel['name'])
                    .'|'.$this->recordLabelNormalizer->catalogNumberHash($recordLabel['catalogNumber']))
                ->values()
                ->all();
        }
        if (array_key_exists('recordLabelProvenance', $validated)) {
            $recordLabelIdentities = collect($values['recordLabels'] ?? [])->mapWithKeys(
                fn (array $recordLabel): array => [
                    $this->recordLabelNormalizer->normalizedName($recordLabel['name'])
                    .'|'.$this->recordLabelNormalizer->catalogNumberHash($recordLabel['catalogNumber']) => true,
                ],
            );
            $values['recordLabelProvenance'] = collect($validated['recordLabelProvenance'])
                ->map(fn (array $recordLabel): array => [
                    'name' => $this->recordLabelNormalizer->displayName($recordLabel['name']),
                    'catalogNumber' => $this->recordLabelNormalizer->catalogNumber(
                        $recordLabel['catalogNumber'] ?? null,
                    ),
                    'source' => $recordLabel['source'],
                    'sourceReference' => trim($recordLabel['sourceReference']),
                ])
                ->filter(fn (array $recordLabel): bool => $recordLabelIdentities->has(
                    $this->recordLabelNormalizer->normalizedName($recordLabel['name'])
                    .'|'.$this->recordLabelNormalizer->catalogNumberHash($recordLabel['catalogNumber']),
                ))
                ->unique(fn (array $recordLabel): string => $recordLabel['source'].'|'.$recordLabel['sourceReference']
                    .'|'.$this->recordLabelNormalizer->normalizedName($recordLabel['name'])
                    .'|'.$this->recordLabelNormalizer->catalogNumberHash($recordLabel['catalogNumber']))
                ->values()
                ->all();
        }

        return $values;
    }
}
