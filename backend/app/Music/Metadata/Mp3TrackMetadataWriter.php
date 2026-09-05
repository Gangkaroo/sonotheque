<?php

namespace App\Music\Metadata;

use App\Music\Catalog\ImportedRecordLabel;
use App\Music\Catalog\RecordLabelNormalizer;
use App\Music\Catalog\RecordLabelTagReader;
use App\Music\Scanning\AudioMetadata;
use App\Music\Scanning\AudioMetadataReader;
use RuntimeException;

class Mp3TrackMetadataWriter implements TrackMetadataWriter
{
    private readonly AdditionalMetadataTags $additionalTags;

    private readonly RecordLabelTagReader $recordLabelReader;

    private readonly RecordLabelNormalizer $recordLabelNormalizer;

    public function __construct(
        private readonly Mp3Id3v2TagEditor $editor,
        private readonly AudioMetadataReader $metadataReader,
        ?AdditionalMetadataTags $additionalTags = null,
        ?RecordLabelTagReader $recordLabelReader = null,
        ?RecordLabelNormalizer $recordLabelNormalizer = null,
    ) {
        $this->additionalTags = $additionalTags ?? new AdditionalMetadataTags();
        $this->recordLabelReader = $recordLabelReader ?? new RecordLabelTagReader();
        $this->recordLabelNormalizer = $recordLabelNormalizer ?? new RecordLabelNormalizer();
    }

    public function supports(string $path): bool
    {
        return $this->editor->supports($path);
    }

    public function write(string $path, array $values): AudioMetadata
    {
        $majorVersion = $this->editor->majorVersion($path);
        [$trackTotal, $currentDiscNumber, $discTotal] = array_key_exists('trackNumber', $values)
            || array_key_exists('discNumber', $values)
            || array_key_exists('totalDiscs', $values)
                ? $this->positions($path)
                : [null, null, null];
        $frames = [];
        if (array_key_exists('title', $values)) {
            $frames['TIT2'] = $values['title'];
        }
        if (array_key_exists('trackNumber', $values)) {
            $frames['TRCK'] = $this->fraction($values['trackNumber'], $trackTotal);
        }
        if (array_key_exists('discNumber', $values)) {
            $frames['TPOS'] = $this->fraction($values['discNumber'], $discTotal);
        }
        if (array_key_exists('totalDiscs', $values)) {
            $frames['TPOS'] = $values['totalDiscs'] === null
                ? $this->fraction($currentDiscNumber, null)
                : $this->fraction($currentDiscNumber ?? 1, $values['totalDiscs']);
        }
        if (array_key_exists('year', $values)) {
            $year = $values['year'] === null ? null : (string) $values['year'];
            $frames['TYER'] = $majorVersion === 3 ? $year : null;
            $frames['TDRC'] = $majorVersion === 4 ? $year : null;
        }
        if (array_key_exists('albumTitle', $values)) {
            $frames['TALB'] = $values['albumTitle'];
        }
        if (array_key_exists('albumArtist', $values)) {
            $frames['TPE2'] = $values['albumArtist'];
        }
        if (array_key_exists('artistNames', $values)) {
            $frames['TPE1'] = $values['artistNames'];
        }
        if (array_key_exists('composers', $values)) {
            $frames['TCOM'] = $values['composers'];
        }
        if (array_key_exists('performers', $values)) {
            $frames['TPE3'] = $values['performers'];
        }
        if (array_key_exists('genres', $values)) {
            $frames['TCON'] = $values['genres'];
        }
        if (array_key_exists('releaseYear', $values)) {
            $year = $values['releaseYear'] === null ? null : (string) $values['releaseYear'];
            $frames['TYER'] = $majorVersion === 3 ? $year : null;
            $frames['TDRC'] = $majorVersion === 4 ? $year : null;
            $frames['TORY'] = $majorVersion === 3 ? $year : null;
            $frames['TDOR'] = $majorVersion === 4 ? $year : null;
        }
        $userTextFrames = [];
        if (array_key_exists('recordLabels', $values)) {
            $frames['TPUB'] = array_column($values['recordLabels'], 'name') ?: null;
            $catalogNumbers = array_map(
                static fn (array $recordLabel): string => $recordLabel['catalogNumber'] ?? '',
                $values['recordLabels'],
            );
            $userTextFrames['CATALOGNUMBER'] = collect($catalogNumbers)->contains(
                static fn (string $catalogNumber): bool => $catalogNumber !== '',
            ) ? $catalogNumbers : null;
        }
        $commentFrames = array_key_exists('comment', $values) ? ['COMM' => $values['comment']] : [];
        $verified = null;

        $this->editor->write(
            $path,
            $frames,
            $userTextFrames,
            function (string $temporaryPath) use ($values, &$verified): void {
                $verified = $this->metadataReader->read($temporaryPath);
                $failedField = $this->failedVerificationField($verified, $values);
                if ($failedField !== null) {
                    throw new RuntimeException(
                        "Track metadata could not be verified after writing: {$failedField} read back differently.",
                    );
                }
            },
            $commentFrames,
            $values['removedTagKeys'] ?? [],
        );

        if (! $verified instanceof AudioMetadata) {
            throw new RuntimeException('Track metadata verification did not complete.');
        }

        return $verified;
    }

    /** @param array<string, mixed> $values */
    private function failedVerificationField(AudioMetadata $metadata, array $values): ?string
    {
        $checks = [
            'title' => $metadata->title,
            'trackNumber' => $metadata->trackNumber,
            'discNumber' => $metadata->discNumber,
            'year' => $metadata->year,
            'albumTitle' => $metadata->album,
            'albumArtist' => $metadata->albumArtist,
            'releaseYear' => $metadata->originalReleaseYear,
            'totalDiscs' => $metadata->discTotal,
            'comment' => $metadata->comment,
        ];
        foreach ($checks as $field => $actual) {
            if (array_key_exists($field, $values) && $values[$field] !== $actual) {
                return $field;
            }
        }

        foreach (['genres', 'artistNames', 'composers', 'performers'] as $field) {
            if (array_key_exists($field, $values)
                && ! $this->sameNames($values[$field], $this->metadataValues($metadata, $field), $field === 'artistNames')) {
                return $field;
            }
        }

        if (array_key_exists('recordLabels', $values)) {
            $expectedRecordLabels = $this->recordLabelIdentities($values['recordLabels']);
            $actualRecordLabels = $this->importedRecordLabelIdentities($metadata);
            if ($expectedRecordLabels !== $actualRecordLabels) {
                return 'record labels';
            }
        }

        $remainingTagKeys = $this->additionalTags->keys($metadata->rawMetadata);
        foreach ($values['removedTagKeys'] ?? [] as $removedTagKey) {
            if (in_array($removedTagKey, $remainingTagKeys, true)) {
                return 'additional tags';
            }
        }

        return null;
    }

    /** @param list<array{name: string, catalogNumber: ?string}> $recordLabels
     * @return list<string>
     */
    private function recordLabelIdentities(array $recordLabels): array
    {
        $identities = array_map(
            fn (array $recordLabel): string => $this->recordLabelNormalizer->normalizedName($recordLabel['name'])
                .'|'.$this->recordLabelNormalizer->catalogNumberHash($recordLabel['catalogNumber']),
            $recordLabels,
        );
        sort($identities);

        return array_values(array_unique($identities));
    }

    /** @return list<string> */
    private function importedRecordLabelIdentities(AudioMetadata $metadata): array
    {
        $identities = array_map(
            fn (ImportedRecordLabel $recordLabel): string => $this->recordLabelNormalizer->normalizedName($recordLabel->name)
                .'|'.$this->recordLabelNormalizer->catalogNumberHash($recordLabel->catalogNumber),
            $this->recordLabelReader->read($metadata->rawMetadata)->items,
        );
        sort($identities);

        return array_values(array_unique($identities));
    }

    /** @return array{?int, ?int, ?int} */
    private function positions(string $path): array
    {
        $information = (new \getID3())->analyze($path);
        \getid3_lib::CopyTagsToComments($information);
        $comments = $information['comments'] ?? [];
        $id3Comments = $information['id3v2']['comments'] ?? [];

        $track = $comments['track_number'][0] ?? null;
        $disc = $comments['part_of_a_set'][0] ?? $comments['disc_number'][0] ?? null;

        return [
            $this->integer($id3Comments['totaltracks'][0] ?? null) ?? $this->total($track),
            $this->leadingInteger($disc),
            $this->total($disc),
        ];
    }

    private function total(mixed $value): ?int
    {
        return is_scalar($value) && preg_match('/^\s*\d+\s*\/\s*(\d+)/', (string) $value, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_scalar($value) && ctype_digit((string) $value) ? (int) $value : null;
    }

    private function leadingInteger(mixed $value): ?int
    {
        return is_scalar($value) && preg_match('/^\s*(\d+)/', (string) $value, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function fraction(?int $value, ?int $total): ?string
    {
        if ($value === null) {
            return null;
        }

        return $total === null ? (string) $value : "{$value}/{$total}";
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     */
    private function sameNames(array $left, array $right, bool $caseSensitive = false): bool
    {
        $normalize = static function (array $names) use ($caseSensitive): array {
            if (! $caseSensitive) {
                $names = array_map('mb_strtolower', $names);
            }
            sort($names);

            return $names;
        };

        return $normalize($left) === $normalize($right);
    }

    /** @return list<string> */
    private function metadataValues(AudioMetadata $metadata, string $field): array
    {
        return match ($field) {
            'genres' => $metadata->genres,
            'artistNames' => $metadata->artists,
            'composers' => $metadata->composers,
            'performers' => $metadata->performers,
        };
    }
}
