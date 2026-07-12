<?php

namespace App\Music\Metadata;

use App\Jobs\ApplyAlbumMetadataEdit;
use App\Models\Album;
use App\Models\MetadataEditJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AlbumMetadataEditing
{
    public function __construct(
        private readonly TrackMetadataEditing $trackEditing,
    ) {
    }

    /**
     * @param  array{albumTitle: string, albumArtist: string, releaseYear: ?int, totalDiscs: ?int, genres: list<string>, comment?: ?string}  $values
     * @return array<string, mixed>
     */
    public function preview(Album $album, array $values): array
    {
        $album->loadMissing(['primaryArtist', 'tracks.mediaFile', 'tracks.genres']);
        $currentGenres = $album->tracks
            ->flatMap(fn ($track) => $track->genres->pluck('name'))
            ->unique(fn (string $name) => mb_strtolower($name))
            ->sort()
            ->values()
            ->all();
        $currentComments = $album->tracks
            ->pluck('comment')
            ->uniqueStrict()
            ->values()
            ->all();
        $current = [
            'albumTitle' => $album->title,
            'albumArtist' => $album->primaryArtist?->name ?? '',
            'releaseYear' => $album->original_release_year,
            'totalDiscs' => $album->disc_total,
            'genres' => $currentGenres,
            'comment' => count($currentComments) === 1 ? $currentComments[0] : $currentComments,
        ];
        $changes = [];
        foreach ($values as $field => $proposed) {
            $unchanged = match ($field) {
                'genres' => $this->sameNames($current[$field], $proposed),
                'comment' => count($currentComments) === 1 && $currentComments[0] === $proposed,
                default => $current[$field] === $proposed,
            };
            if (! $unchanged) {
                $changes[] = ['field' => $field, 'current' => $current[$field], 'proposed' => $proposed];
            }
        }
        $changedFields = array_fill_keys(array_column($changes, 'field'), true);
        $writeValues = array_intersect_key($values, $changedFields);

        $files = $album->tracks->map(function ($track) use ($writeValues): array {
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
            ];
        })->values();

        return [
            'albumId' => $album->id,
            'fingerprint' => $this->fingerprint($album),
            'values' => $values,
            'changes' => $changes,
            'files' => $files,
            'supportedFiles' => $files->where('supported', true)->count(),
            'unsupportedFiles' => $files->where('supported', false)->count(),
        ];
    }

    /**
     * @param  array{albumTitle: string, albumArtist: string, releaseYear: ?int, totalDiscs: ?int, genres: list<string>, comment?: ?string}  $values
     */
    public function queue(Album $album, array $values, string $fingerprint): MetadataEditJob
    {
        $preview = $this->preview($album, $values);
        if (! hash_equals($preview['fingerprint'], $fingerprint)) {
            throw ValidationException::withMessages([
                'fingerprint' => 'The album changed after the preview. Review the changes again.',
            ]);
        }
        if ($preview['changes'] === []) {
            throw ValidationException::withMessages(['album' => 'No metadata changes were requested.']);
        }
        if ($preview['supportedFiles'] === 0) {
            throw ValidationException::withMessages(['album' => 'This album has no editable MP3 files.']);
        }
        if ($preview['unsupportedFiles'] > 0) {
            $issue = $preview['files']->firstWhere('supported', false)['supportIssue'] ?? null;
            throw ValidationException::withMessages([
                'album' => $this->trackEditing->supportIssueMessage($issue),
            ]);
        }

        $job = DB::transaction(function () use ($album, $values, $fingerprint, $preview): MetadataEditJob {
            $job = MetadataEditJob::create([
                'album_id' => $album->id,
                'type' => 'album',
                'status' => 'pending',
                'fingerprint' => $fingerprint,
                'requested_changes' => $values,
                'preview' => $preview,
                'total_items' => count($preview['files']),
            ]);

            foreach ($preview['files'] as $file) {
                $job->items()->create([
                    'track_id' => $file['trackId'],
                    'media_file_id' => $album->tracks->firstWhere('id', $file['trackId'])?->media_file_id,
                    'status' => $file['supported'] ? 'pending' : 'failed',
                    'fingerprint' => $file['fingerprint'],
                    'requested_changes' => $file['writeValues'],
                    'preview' => $file,
                    'error' => $file['supported'] ? null : $this->trackEditing->supportIssueMessage($file['supportIssue']),
                    'finished_at' => $file['supported'] ? null : now(),
                ]);
            }

            ApplyAlbumMetadataEdit::dispatch($job->id)->afterCommit();

            return $job;
        });

        return $job;
    }

    public function fingerprint(Album $album): string
    {
        $album->loadMissing(['primaryArtist', 'tracks.mediaFile', 'tracks.genres']);

        return hash('sha256', json_encode([
            'album' => $album->only(['id', 'title', 'primary_artist_id', 'original_release_year', 'disc_total', 'updated_at']),
            'tracks' => $album->tracks
                ->sortBy('id')
                ->map(fn ($track) => $this->trackEditing->fingerprint($track))
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     */
    private function sameNames(array $left, array $right): bool
    {
        $normalize = static function (array $names): array {
            $names = array_map(fn (string $name) => mb_strtolower($name), $names);
            sort($names);

            return $names;
        };

        return $normalize($left) === $normalize($right);
    }
}
