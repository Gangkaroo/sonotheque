<?php

namespace App\Music\Metadata;

use App\Jobs\ApplyAlbumMetadataEdit;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\MetadataEditJob;
use App\Models\AlbumRecordLabel;
use App\Music\Catalog\ImportedRecordLabel;
use App\Music\Catalog\RecordLabelNormalizer;
use App\Music\Catalog\RecordLabelTagReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AlbumMetadataEditing
{
    public function __construct(
        private readonly TrackMetadataEditing $trackEditing,
        private readonly AdditionalMetadataTags $additionalTags,
        private readonly RecordLabelTagReader $recordLabelTagReader,
        private readonly RecordLabelNormalizer $recordLabelNormalizer,
    ) {
    }

    /**
     * @param  array{albumTitle: string, albumArtist: string, updateTrackArtists: bool, releaseYear: ?int, totalDiscs: ?int, genres: list<string>, recordLabels?: list<array{name: string, catalogNumber: ?string}>, recordLabelProvenance?: list<array{name: string, catalogNumber: ?string, source: string, sourceReference: ?string}>, comment?: ?string, removedTagKeys: list<string>}  $values
     * @return array<string, mixed>
     */
    public function preview(Album $album, array $values): array
    {
        $values['updateTrackArtists'] = $values['updateTrackArtists'] ?? true;
        $values['removedTagKeys'] = $values['removedTagKeys'] ?? [];
        $recordLabelProvenance = $values['recordLabelProvenance'] ?? [];
        unset($values['recordLabelProvenance']);
        $album->loadMissing([
            'primaryArtist',
            'recordLabelAssignments.recordLabel',
            'tracks.mediaFile',
            'tracks.artists',
            'tracks.genres',
        ]);
        $additionalTags = $album->tracks
            ->flatMap(fn ($track) => $this->additionalTags->extract($track->mediaFile?->raw_metadata ?? []))
            ->unique('key')
            ->values();
        $additionalTagKeys = $additionalTags->pluck('key')->all();
        if (array_diff($values['removedTagKeys'], $additionalTagKeys) !== []) {
            throw ValidationException::withMessages([
                'removedTagKeys' => 'One or more additional tags are no longer present in this album.',
            ]);
        }
        $protectedTagKeys = $additionalTags
            ->where('playbackStatistic', true)
            ->pluck('key')
            ->all();
        if (ApplicationSetting::current()->synchronizesPlaybackStatisticsWithTags()
            && array_intersect($values['removedTagKeys'], $protectedTagKeys) !== []) {
            throw ValidationException::withMessages([
                'removedTagKeys' => 'Playback-statistics tags cannot be removed while file-tag synchronization is enabled.',
            ]);
        }
        $protectedRatingKeys = $additionalTags
            ->where('rating', true)
            ->pluck('key')
            ->all();
        if (ApplicationSetting::current()->synchronizesRatingsWithTags()
            && array_intersect($values['removedTagKeys'], $protectedRatingKeys) !== []) {
            throw ValidationException::withMessages([
                'removedTagKeys' => 'Rating tags cannot be removed while rating synchronization is enabled.',
            ]);
        }
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
        $currentRecordLabels = $album->recordLabelAssignments
            ->where('source', 'file_tag')
            ->map(fn (AlbumRecordLabel $assignment): array => [
                'name' => $assignment->recordLabel->name,
                'catalogNumber' => $assignment->catalog_number,
            ])
            ->sortBy(fn (array $recordLabel): string => $this->recordLabelIdentity($recordLabel))
            ->values()
            ->all();
        $values['recordLabels'] ??= $currentRecordLabels;
        $recordLabelFilesMatch = $album->tracks->every(
            fn ($track): bool => $this->sameRecordLabels(
                $values['recordLabels'],
                $this->recordLabelTagReader->read($track->mediaFile?->raw_metadata ?? [])->items,
            ),
        );
        $albumArtistFileValues = $album->tracks
            ->map(fn ($track): ?string => $this->fileAlbumArtist($track->mediaFile?->raw_metadata ?? []));
        $albumArtistFilesMatch = ! $albumArtistFileValues
            ->contains(fn (?string $artist): bool => $artist !== $values['albumArtist']);
        $trackArtistsMatch = $album->tracks->every(
            fn ($track): bool => $this->sameNames(
                $track->artists->pluck('name')->values()->all(),
                [$values['albumArtist']],
            ),
        );
        $current = [
            'albumTitle' => $album->title,
            'albumArtist' => $album->primaryArtist?->name ?? '',
            'releaseYear' => $album->original_release_year,
            'totalDiscs' => $album->disc_total,
            'genres' => $currentGenres,
            'comment' => count($currentComments) === 1 ? $currentComments[0] : $currentComments,
            'recordLabels' => $currentRecordLabels,
            'removedTagKeys' => [],
        ];
        $metadataValues = $values;
        unset($metadataValues['updateTrackArtists']);
        $changes = [];
        foreach ($metadataValues as $field => $proposed) {
            $unchanged = match ($field) {
                'genres' => $this->sameNames($current[$field], $proposed),
                'comment' => count($currentComments) === 1 && $currentComments[0] === $proposed,
                'recordLabels' => $this->sameRecordLabels($current[$field], $proposed)
                    && $recordLabelFilesMatch,
                'removedTagKeys' => $proposed === [],
                'albumArtist' => $current[$field] === $proposed
                    && $albumArtistFilesMatch
                    && (! $values['updateTrackArtists'] || $trackArtistsMatch),
                default => $current[$field] === $proposed,
            };
            if (! $unchanged) {
                $change = [
                    'field' => $field,
                    'current' => $field === 'removedTagKeys'
                        ? $additionalTags->whereIn('key', $proposed)->pluck('name')->values()->all()
                        : $current[$field],
                    'proposed' => $field === 'removedTagKeys' ? [] : $proposed,
                ];
                if ($field === 'albumArtist'
                    && (! $albumArtistFilesMatch || ($values['updateTrackArtists'] && ! $trackArtistsMatch))) {
                    $change['fileValuesDiffer'] = true;
                }
                if ($field === 'recordLabels' && ! $recordLabelFilesMatch) {
                    $change['fileValuesDiffer'] = true;
                }
                $changes[] = $change;
            }
        }
        $changedFields = array_fill_keys(array_column($changes, 'field'), true);
        $writeValues = array_intersect_key($metadataValues, $changedFields);
        $trackArtistsWillChange = $values['updateTrackArtists'] && ! $trackArtistsMatch;
        if ($trackArtistsWillChange) {
            $writeValues['artistNames'] = [$values['albumArtist']];
        }

        $files = $album->tracks->map(function ($track) use ($writeValues): ?array {
            $file = $track->mediaFile?->relative_path;
            $trackWriteValues = $writeValues;
            if (array_key_exists('removedTagKeys', $trackWriteValues)) {
                $trackWriteValues['removedTagKeys'] = array_values(array_intersect(
                    $trackWriteValues['removedTagKeys'],
                    $this->additionalTags->keys($track->mediaFile?->raw_metadata ?? []),
                ));
                if ($trackWriteValues['removedTagKeys'] === []) {
                    unset($trackWriteValues['removedTagKeys']);
                }
            }
            if ($trackWriteValues === []) {
                return null;
            }
            $supportIssue = $this->trackEditing->supportIssue($track);

            return [
                'trackId' => $track->id,
                'trackTitle' => $track->title,
                'file' => $file,
                'format' => $file ? mb_strtolower(pathinfo($file, PATHINFO_EXTENSION)) : null,
                'supported' => $supportIssue === null,
                'supportIssue' => $supportIssue,
                'fingerprint' => $this->trackEditing->fingerprint($track),
                'writeValues' => $trackWriteValues,
            ];
        })->filter()->values();

        $responseValues = $values;
        if ($recordLabelProvenance !== []) {
            $responseValues['recordLabelProvenance'] = $recordLabelProvenance;
        }

        return [
            'albumId' => $album->id,
            'fingerprint' => $this->fingerprint($album),
            'values' => $responseValues,
            'changes' => $changes,
            'trackArtistsWillChange' => $trackArtistsWillChange,
            'files' => $files,
            'supportedFiles' => $files->where('supported', true)->count(),
            'unsupportedFiles' => $files->where('supported', false)->count(),
        ];
    }

    /**
     * @param  array{albumTitle: string, albumArtist: string, updateTrackArtists: bool, releaseYear: ?int, totalDiscs: ?int, genres: list<string>, recordLabels?: list<array{name: string, catalogNumber: ?string}>, recordLabelProvenance?: list<array{name: string, catalogNumber: ?string, source: string, sourceReference: ?string}>, comment?: ?string, removedTagKeys: list<string>}  $values
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
        $album->loadMissing([
            'primaryArtist',
            'recordLabelAssignments.recordLabel',
            'tracks.mediaFile',
            'tracks.genres',
        ]);

        return hash('sha256', json_encode([
            'album' => $album->only(['id', 'title', 'primary_artist_id', 'original_release_year', 'disc_total', 'updated_at']),
            'tracks' => $album->tracks
                ->sortBy('id')
                ->map(fn ($track) => $this->trackEditing->fingerprint($track))
                ->values()
                ->all(),
            'recordLabels' => $album->recordLabelAssignments
                ->sortBy('id')
                ->map(fn (AlbumRecordLabel $assignment): array => $assignment->only([
                    'id',
                    'record_label_id',
                    'catalog_number_hash',
                    'source',
                    'source_reference',
                    'updated_at',
                ]))
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array{name: string, catalogNumber: ?string}>  $left
     * @param  list<array{name: string, catalogNumber: ?string}|ImportedRecordLabel>  $right
     */
    private function sameRecordLabels(array $left, array $right): bool
    {
        $left = array_map(fn (array $recordLabel): string => $this->recordLabelIdentity($recordLabel), $left);
        $right = array_map(
            fn (array|ImportedRecordLabel $recordLabel): string => $this->recordLabelIdentity(
                $recordLabel instanceof ImportedRecordLabel
                    ? ['name' => $recordLabel->name, 'catalogNumber' => $recordLabel->catalogNumber]
                    : $recordLabel,
            ),
            $right,
        );
        sort($left);
        sort($right);

        return array_values(array_unique($left)) === array_values(array_unique($right));
    }

    /** @param array{name: string, catalogNumber: ?string} $recordLabel */
    private function recordLabelIdentity(array $recordLabel): string
    {
        return $this->recordLabelNormalizer->normalizedName($recordLabel['name'])
            .'|'.$this->recordLabelNormalizer->catalogNumberHash($recordLabel['catalogNumber']);
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

    /** @param array<string, mixed> $metadata */
    private function fileAlbumArtist(array $metadata): ?string
    {
        foreach ([
            'comments.album_artist',
            'comments.band',
            'id3v2.comments.album_artist',
            'id3v2.comments.band',
            'tags.id3v2.album_artist',
            'tags.id3v2.band',
            'ffprobe_fallback.format.tags.album_artist',
            'ffprobe_fallback.format.tags.albumartist',
        ] as $path) {
            foreach ((array) data_get($metadata, $path) as $value) {
                if (! is_scalar($value)) {
                    continue;
                }

                $artist = trim((string) $value);
                if ($artist !== '') {
                    return $artist;
                }
            }
        }

        return null;
    }
}
