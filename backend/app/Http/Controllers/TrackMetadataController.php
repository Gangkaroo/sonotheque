<?php

namespace App\Http\Controllers;

use App\Models\MetadataEditJob;
use App\Models\Track;
use App\Music\Metadata\TrackMetadataEditing;
use App\Support\MetadataEditPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackMetadataController extends Controller
{
    public function __construct(private readonly MetadataEditPayloads $payloads) {}

    public function preview(Request $request, Track $track, TrackMetadataEditing $editing): JsonResponse
    {
        return response()->json($editing->preview($track, $this->values($request)));
    }

    public function store(Request $request, Track $track, TrackMetadataEditing $editing): JsonResponse
    {
        $validated = $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);
        $job = $editing->queue($track, $this->values($request), $validated['fingerprint']);

        return response()->json($this->payloads->job($job), 202);
    }

    public function show(MetadataEditJob $metadataEditJob): JsonResponse
    {
        return response()->json($this->payloads->job($metadataEditJob));
    }

    /** @return array{title: string, artistNames: list<string>, composers: list<string>, performers: list<string>, comment: ?string, trackNumber: ?int, discNumber: ?int, year: ?int} */
    private function values(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:512', 'not_regex:/^\s*$/'],
            'artistNames' => ['required', 'array', 'min:1', 'max:64'],
            'artistNames.*' => ['string', 'max:512', 'not_regex:/^\s*$/'],
            'composers' => ['present', 'array', 'max:64'],
            'composers.*' => ['string', 'max:512', 'not_regex:/^\s*$/'],
            'performers' => ['present', 'array', 'max:64'],
            'performers.*' => ['string', 'max:512', 'not_regex:/^\s*$/'],
            'comment' => ['present', 'nullable', 'string', 'max:10000'],
            'trackNumber' => ['present', 'nullable', 'integer', 'min:1', 'max:65535'],
            'discNumber' => ['present', 'nullable', 'integer', 'min:1', 'max:65535'],
            'year' => ['present', 'nullable', 'integer', 'min:1000', 'max:9999'],
        ]);

        return [
            'title' => trim($validated['title']),
            'artistNames' => $this->names($validated['artistNames']),
            'composers' => $this->names($validated['composers']),
            'performers' => $this->names($validated['performers']),
            'comment' => filled($validated['comment']) ? trim($validated['comment']) : null,
            'trackNumber' => $validated['trackNumber'],
            'discNumber' => $validated['discNumber'],
            'year' => $validated['year'],
        ];
    }

    /** @param list<string> $names
     * @return list<string>
     */
    private function names(array $names): array
    {
        return collect($names)
            ->map(fn (string $name): string => trim($name))
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();
    }
}
