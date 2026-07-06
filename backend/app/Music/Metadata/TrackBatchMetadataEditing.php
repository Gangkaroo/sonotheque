<?php

namespace App\Music\Metadata;

use App\Jobs\ApplyTrackMetadataBatch;
use App\Models\Album;
use App\Models\MetadataEditJob;
use App\Models\Track;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrackBatchMetadataEditing
{
    private const FIELDS = [
        'artistNames',
        'composers',
        'performers',
        'genres',
        'comment',
        'trackNumber',
        'discNumber',
        'year',
    ];

    public function __construct(private readonly TrackMetadataEditing $trackEditing)
    {
    }

    /**
     * @param  EloquentCollection<int, Track>  $tracks
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function preview(Album $album, EloquentCollection $tracks, array $changes): array
    {
        $this->guardTracks($album, $tracks);
        $tracks->loadMissing(['mediaFile', 'artists', 'genres']);
        $currentByTrack = $tracks->mapWithKeys(fn (Track $track): array => [
            $track->id => $this->currentValues($track),
        ]);
        $fields = [];
        foreach (self::FIELDS as $field) {
            $values = $currentByTrack->pluck($field)->values()->all();
            $first = $values[0] ?? null;
            $mixed = collect($values)->contains(fn (mixed $value): bool => ! $this->sameValue($field, $first, $value));
            $fields[$field] = [
                'mixed' => $mixed,
                'value' => $mixed ? null : $first,
                'values' => $this->uniqueValues($field, $values),
            ];
        }

        $files = $tracks->map(function (Track $track) use ($changes, $currentByTrack): array {
            $current = $currentByTrack[$track->id];
            $writeValues = [];
            $fileChanges = [];
            foreach ($changes as $field => $proposed) {
                if ($this->sameValue($field, $current[$field], $proposed)) {
                    continue;
                }
                $writeValues[$field] = $proposed;
                $fileChanges[] = [
                    'field' => $field,
                    'current' => $current[$field],
                    'proposed' => $proposed,
                ];
            }
            $file = $track->mediaFile?->relative_path;
            $supportIssue = $this->trackEditing->supportIssue($track);

            return [
                'trackId' => $track->id,
                'trackTitle' => $track->title,
                'file' => $file,
                'format' => $file ? mb_strtolower(pathinfo($file, PATHINFO_EXTENSION)) : null,
                'supported' => $supportIssue === null,
                'supportIssue' => $supportIssue,
                'fingerprint' => $this->trackEditing->fingerprint($track),
                'writeValues' => $writeValues,
                'changes' => $fileChanges,
                'affected' => $writeValues !== [],
            ];
        })->values();
        $affectedFiles = $files->where('affected', true);

        return [
            'albumId' => $album->id,
            'trackIds' => $tracks->pluck('id')->values()->all(),
            'fingerprint' => $this->fingerprint($album, $tracks),
            'fields' => $fields,
            'requestedChanges' => $changes,
            'changes' => collect($changes)->map(fn (mixed $proposed, string $field): array => [
                'field' => $field,
                'mixed' => $fields[$field]['mixed'],
                'current' => $fields[$field]['value'],
                'proposed' => $proposed,
                'affectedFiles' => $affectedFiles->filter(
                    fn (array $file): bool => collect($file['changes'])->contains('field', $field),
                )->count(),
            ])->values()->all(),
            'files' => $files->all(),
            'selectedFiles' => $files->count(),
            'affectedFiles' => $affectedFiles->count(),
            'unsupportedFiles' => $affectedFiles->where('supported', false)->count(),
        ];
    }

    /**
     * @param  EloquentCollection<int, Track>  $tracks
     * @param  array<string, mixed>  $changes
     */
    public function queue(Album $album, EloquentCollection $tracks, array $changes, string $fingerprint): MetadataEditJob
    {
        $preview = $this->preview($album, $tracks, $changes);
        if (! hash_equals($preview['fingerprint'], $fingerprint)) {
            throw ValidationException::withMessages([
                'fingerprint' => 'The selected tracks changed after the preview. Review the changes again.',
            ]);
        }
        if ($changes === [] || $preview['affectedFiles'] === 0) {
            throw ValidationException::withMessages(['tracks' => 'No metadata changes were requested.']);
        }
        if ($preview['unsupportedFiles'] > 0) {
            $file = collect($preview['files'])
                ->where('affected', true)
                ->firstWhere('supported', false);
            throw ValidationException::withMessages([
                'tracks' => $this->trackEditing->supportIssueMessage($file['supportIssue'] ?? null),
            ]);
        }

        return DB::transaction(function () use ($album, $tracks, $changes, $fingerprint, $preview): MetadataEditJob {
            $affectedFiles = collect($preview['files'])->where('affected', true);
            $job = MetadataEditJob::create([
                'album_id' => $album->id,
                'type' => 'track_batch',
                'status' => 'pending',
                'fingerprint' => $fingerprint,
                'requested_changes' => $changes,
                'preview' => $preview,
                'total_items' => $affectedFiles->count(),
            ]);

            foreach ($affectedFiles as $file) {
                $track = $tracks->firstWhere('id', $file['trackId']);
                $job->items()->create([
                    'track_id' => $file['trackId'],
                    'media_file_id' => $track?->media_file_id,
                    'status' => 'pending',
                    'fingerprint' => $file['fingerprint'],
                    'requested_changes' => $file['writeValues'],
                    'preview' => $file,
                ]);
            }

            ApplyTrackMetadataBatch::dispatch($job->id)->afterCommit();

            return $job;
        });
    }

    /** @param EloquentCollection<int, Track> $tracks */
    public function fingerprint(Album $album, EloquentCollection $tracks): string
    {
        return hash('sha256', json_encode([
            'albumId' => $album->id,
            'tracks' => $tracks
                ->sortBy('id')
                ->map(fn (Track $track): string => $this->trackEditing->fingerprint($track))
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param EloquentCollection<int, Track> $tracks */
    private function guardTracks(Album $album, EloquentCollection $tracks): void
    {
        if ($tracks->isEmpty() || $tracks->contains(fn (Track $track): bool => $track->album_id !== $album->id)) {
            throw ValidationException::withMessages(['trackIds' => 'Select tracks from this album.']);
        }
    }

    /** @return array<string, mixed> */
    private function currentValues(Track $track): array
    {
        return [
            'artistNames' => $track->artists->pluck('name')->values()->all(),
            'composers' => $track->composers ?? [],
            'performers' => $track->performers ?? [],
            'genres' => $track->genres->pluck('name')->values()->all(),
            'comment' => $track->comment,
            'trackNumber' => $track->track_number,
            'discNumber' => $track->disc_number,
            'year' => $track->year,
        ];
    }

    private function sameValue(string $field, mixed $left, mixed $right): bool
    {
        if (in_array($field, ['artistNames', 'composers', 'performers', 'genres'], true)) {
            $normalize = static function (array $names): array {
                $names = array_map('mb_strtolower', $names);
                sort($names);

                return $names;
            };

            return $normalize($left) === $normalize($right);
        }

        return $left === $right;
    }

    /** @param list<mixed> $values
     * @return list<mixed>
     */
    private function uniqueValues(string $field, array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $key = json_encode(
                in_array($field, ['artistNames', 'composers', 'performers', 'genres'], true)
                    ? collect($value)->map(fn (string $name): string => mb_strtolower($name))->sort()->values()->all()
                    : $value,
                JSON_THROW_ON_ERROR,
            );
            $unique[$key] ??= $value;
        }

        return array_values($unique);
    }
}
