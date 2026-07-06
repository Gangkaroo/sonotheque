<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Track;
use App\Music\Metadata\TrackBatchMetadataEditing;
use App\Support\MetadataEditPayloads;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlbumTrackMetadataController extends Controller
{
    public function __construct(private readonly MetadataEditPayloads $payloads)
    {
    }

    public function preview(Request $request, Album $album, TrackBatchMetadataEditing $editing): JsonResponse
    {
        [$tracks, $changes] = $this->input($request, $album);

        return response()->json($editing->preview($album, $tracks, $changes));
    }

    public function store(Request $request, Album $album, TrackBatchMetadataEditing $editing): JsonResponse
    {
        $validated = $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);
        [$tracks, $changes] = $this->input($request, $album);
        $job = $editing->queue($album, $tracks, $changes, $validated['fingerprint']);

        return response()->json($this->payloads->job($job), 202);
    }

    /** @return array{EloquentCollection<int, Track>, array<string, mixed>} */
    private function input(Request $request, Album $album): array
    {
        $validated = $request->validate([
            'trackIds' => ['required', 'array', 'min:1', 'max:500'],
            'trackIds.*' => [
                'integer',
                'distinct',
                Rule::exists('tracks', 'id')->where('album_id', $album->id),
            ],
            'changes' => ['present', 'array'],
            'changes.artistNames' => ['sometimes', 'array', 'min:1', 'max:64'],
            'changes.artistNames.*' => ['string', 'max:512', 'not_regex:/^\s*$/'],
            'changes.composers' => ['sometimes', 'array', 'max:64'],
            'changes.composers.*' => ['string', 'max:512', 'not_regex:/^\s*$/'],
            'changes.performers' => ['sometimes', 'array', 'max:64'],
            'changes.performers.*' => ['string', 'max:512', 'not_regex:/^\s*$/'],
            'changes.genres' => ['sometimes', 'array', 'max:64'],
            'changes.genres.*' => ['string', 'max:512', 'not_regex:/^\s*$/'],
            'changes.comment' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'changes.trackNumber' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'changes.discNumber' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'changes.year' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:9999'],
        ]);
        $changes = $validated['changes'] ?? [];
        foreach (['artistNames', 'composers', 'performers', 'genres'] as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = $this->names($changes[$field]);
            }
        }
        if (array_key_exists('comment', $changes)) {
            $changes['comment'] = filled($changes['comment']) ? trim($changes['comment']) : null;
        }

        /** @var EloquentCollection<int, Track> $tracks */
        $tracks = Track::query()
            ->where('album_id', $album->id)
            ->whereIn('id', $validated['trackIds'])
            ->orderBy('disc_number')
            ->orderBy('track_number')
            ->orderBy('id')
            ->get();

        return [$tracks, $changes];
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
