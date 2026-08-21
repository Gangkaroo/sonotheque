<?php

namespace App\Music\Metadata;

use App\Jobs\ApplyTrackMetadataEdit;
use App\Models\ApplicationSetting;
use App\Models\MetadataEditJob;
use App\Models\Track;
use Illuminate\Validation\ValidationException;

class TrackMetadataEditing
{
    public function __construct(
        private readonly TrackMetadataWriter $writer,
        private readonly Mp3Id3v2TagEditor $editor,
        private readonly AdditionalMetadataTags $additionalTags,
    ) {
    }

    /**
     * @param  array{title: string, artistNames: list<string>, composers: list<string>, performers: list<string>, genres: list<string>, comment: ?string, trackNumber: ?int, discNumber: ?int, year: ?int, removedTagKeys: list<string>}  $values
     * @return array<string, mixed>
     */
    public function preview(Track $track, array $values): array
    {
        $track->loadMissing(['mediaFile', 'artists', 'genres']);
        $mediaFile = $track->mediaFile;
        if ($mediaFile === null) {
            throw ValidationException::withMessages(['track' => 'This track has no associated media file.']);
        }
        $additionalTags = $this->additionalTags->extract($mediaFile->raw_metadata ?? []);
        $additionalTagKeys = array_column($additionalTags, 'key');
        $unknownTagKeys = array_values(array_diff($values['removedTagKeys'], $additionalTagKeys));
        if ($unknownTagKeys !== []) {
            throw ValidationException::withMessages([
                'removedTagKeys' => 'One or more additional tags are no longer present in this file.',
            ]);
        }
        if (ApplicationSetting::current()->synchronizesPlaybackStatisticsWithTags()
            && array_intersect(
                $values['removedTagKeys'],
                $this->additionalTags->playbackStatisticKeys($mediaFile->raw_metadata ?? []),
            ) !== []) {
            throw ValidationException::withMessages([
                'removedTagKeys' => 'Playback-statistics tags cannot be removed while file-tag synchronization is enabled.',
            ]);
        }

        $supportIssue = $this->supportIssue($track);
        $current = [
            'title' => $track->title,
            'artistNames' => $track->artists->pluck('name')->values()->all(),
            'composers' => $track->composers ?? [],
            'performers' => $track->performers ?? [],
            'genres' => $track->genres->pluck('name')->values()->all(),
            'comment' => $track->comment,
            'trackNumber' => $track->track_number,
            'discNumber' => $track->disc_number,
            'year' => $track->year,
            'removedTagKeys' => [],
        ];
        $changes = [];
        foreach ($values as $field => $proposed) {
            if ($field === 'removedTagKeys') {
                if ($proposed !== []) {
                    $changes[] = [
                        'field' => $field,
                        'current' => collect($additionalTags)
                            ->whereIn('key', $proposed)
                            ->pluck('name')
                            ->values()
                            ->all(),
                        'proposed' => [],
                    ];
                }

                continue;
            }

            $unchanged = in_array($field, ['artistNames', 'composers', 'performers', 'genres'], true)
                ? $this->sameNames($current[$field], $proposed)
                : $current[$field] === $proposed;
            if (! $unchanged) {
                $changes[] = [
                    'field' => $field,
                    'current' => $current[$field],
                    'proposed' => $proposed,
                ];
            }
        }

        return [
            'trackId' => $track->id,
            'file' => $mediaFile->relative_path,
            'format' => mb_strtolower(pathinfo($mediaFile->relative_path, PATHINFO_EXTENSION)),
            'supported' => $supportIssue === null,
            'supportIssue' => $supportIssue,
            'fingerprint' => $this->fingerprint($track),
            'values' => $values,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array{title: string, artistNames: list<string>, composers: list<string>, performers: list<string>, genres: list<string>, comment: ?string, trackNumber: ?int, discNumber: ?int, year: ?int, removedTagKeys: list<string>}  $values
     */
    public function queue(Track $track, array $values, string $fingerprint): MetadataEditJob
    {
        $preview = $this->preview($track, $values);
        if (! hash_equals($preview['fingerprint'], $fingerprint)) {
            throw ValidationException::withMessages([
                'fingerprint' => 'The track changed after the preview. Review the changes again.',
            ]);
        }
        if (! $preview['supported']) {
            throw ValidationException::withMessages([
                'track' => $this->supportIssueMessage($preview['supportIssue']),
            ]);
        }
        if ($preview['changes'] === []) {
            throw ValidationException::withMessages(['track' => 'No metadata changes were requested.']);
        }

        $job = MetadataEditJob::create([
            'track_id' => $track->id,
            'media_file_id' => $track->media_file_id,
            'status' => 'pending',
            'fingerprint' => $fingerprint,
            'requested_changes' => $values,
            'preview' => $preview,
        ]);
        ApplyTrackMetadataEdit::dispatch($job->id)->afterCommit();

        return $job;
    }

    public function fingerprint(Track $track): string
    {
        $track->loadMissing(['mediaFile', 'artists', 'genres']);

        return hash('sha256', json_encode([
            'track' => $track->only(['id', 'title', 'track_number', 'disc_number', 'year', 'comment', 'composers', 'performers', 'updated_at']),
            'artists' => $track->artists->pluck('name')->all(),
            'genres' => $track->genres->pluck('name')->all(),
            'mediaFile' => $track->mediaFile?->only(['id', 'file_size', 'modified_at']),
        ], JSON_THROW_ON_ERROR));
    }

    public function supportIssue(Track $track): ?string
    {
        $track->loadMissing('mediaFile');
        $mediaFile = $track->mediaFile;
        if ($mediaFile === null) {
            return 'missing_media_file';
        }
        if (! $this->writer->supports($mediaFile->relative_path)) {
            return 'unsupported_format';
        }

        return $this->editor->supportIssue($mediaFile->raw_metadata ?? []);
    }

    public function supportIssueMessage(?string $issue): string
    {
        return match ($issue) {
            'missing_media_file' => 'This track has no associated media file.',
            'unsupported_format' => 'Metadata editing currently supports MP3 files only.',
            null => 'This file is supported for metadata editing.',
            default => $this->editor->supportIssueMessage($issue),
        };
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     */
    private function sameNames(array $left, array $right): bool
    {
        $normalize = static function (array $names): array {
            $names = array_map('mb_strtolower', $names);
            sort($names);

            return $names;
        };

        return $normalize($left) === $normalize($right);
    }
}
