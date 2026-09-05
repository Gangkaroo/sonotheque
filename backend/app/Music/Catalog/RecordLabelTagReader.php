<?php

namespace App\Music\Catalog;

final class RecordLabelTagReader
{
    public const IMPORT_VERSION = 2;

    /** @var list<string> */
    private const LABEL_KEYS = [
        'label',
        'publisher',
        'organization',
        'recordlabel',
    ];

    /** @var list<string> */
    private const CATALOG_NUMBER_KEYS = [
        'catalog',
        'catalognumber',
        'catalogno',
        'catalognr',
        'cataloguenumber',
        'catalogueno',
    ];

    public function recognizesField(string $name): bool
    {
        return in_array($this->normalizedKey($name), [...self::LABEL_KEYS, ...self::CATALOG_NUMBER_KEYS], true);
    }

    /** @param array<string, mixed> $rawMetadata */
    public function read(array $rawMetadata): ImportedRecordLabels
    {
        $id3Pairs = $this->pairedId3Values($rawMetadata);
        if ($id3Pairs !== null) {
            return new ImportedRecordLabels($id3Pairs);
        }

        $labels = $this->fieldValues($rawMetadata, self::LABEL_KEYS);
        if ($labels === []) {
            return new ImportedRecordLabels([]);
        }

        $catalogNumbers = $this->fieldValues($rawMetadata, self::CATALOG_NUMBER_KEYS);
        $items = [];

        if ($catalogNumbers === []) {
            foreach ($labels as $label) {
                $items[] = new ImportedRecordLabel($label);
            }
        } elseif (count($labels) === count($catalogNumbers)) {
            foreach ($labels as $index => $label) {
                $items[] = new ImportedRecordLabel($label, $catalogNumbers[$index]);
            }
        } elseif (count($labels) === 1) {
            foreach ($catalogNumbers as $catalogNumber) {
                $items[] = new ImportedRecordLabel($labels[0], $catalogNumber);
            }
        } else {
            // Several unpaired values are still useful as labels. The original
            // catalog-number tags remain available in raw metadata for review.
            foreach ($labels as $label) {
                $items[] = new ImportedRecordLabel($label);
            }
        }

        return new ImportedRecordLabels($items);
    }

    /**
     * Preserve positional pairing for MP3 files written with multiple `TPUB`
     * and `TXXX:CATALOGNUMBER` frames. Empty catalog-number frames act only as
     * placeholders and are normalized to null.
     *
     * @param  array<string, mixed>  $rawMetadata
     * @return null|list<ImportedRecordLabel>
     */
    private function pairedId3Values(array $rawMetadata): ?array
    {
        $id3v2 = is_array($rawMetadata['id3v2'] ?? null) ? $rawMetadata['id3v2'] : [];
        $labels = [];
        foreach ($this->frames($id3v2['TPUB'] ?? []) as $frame) {
            foreach ($this->decodedFrameValues($frame) as $label) {
                $label = trim($label);
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }
        if ($labels === []) {
            $labels = $this->id3CommentValues($id3v2, self::LABEL_KEYS);
        }
        if ($labels === []) {
            return null;
        }

        $catalogNumbers = [];
        foreach ($this->frames($id3v2['TXXX'] ?? []) as $frame) {
            if (! in_array(
                $this->normalizedKey((string) ($frame['description'] ?? '')),
                self::CATALOG_NUMBER_KEYS,
                true,
            )) {
                continue;
            }
            foreach ($this->decodedFrameValues($frame) as $catalogNumber) {
                $catalogNumbers[] = trim($catalogNumber);
            }
        }
        if ($catalogNumbers === []) {
            $catalogNumbers = $this->id3CommentValues($id3v2, self::CATALOG_NUMBER_KEYS);
        }

        if ($catalogNumbers === []) {
            return array_map(
                static fn (string $label): ImportedRecordLabel => new ImportedRecordLabel($label),
                $labels,
            );
        }
        if (count($labels) !== count($catalogNumbers)) {
            return null;
        }

        return array_map(
            static fn (string $label, string $catalogNumber): ImportedRecordLabel => new ImportedRecordLabel(
                $label,
                $catalogNumber === '' ? null : $catalogNumber,
            ),
            $labels,
            $catalogNumbers,
        );
    }

    /**
     * getID3 exposes decoded `TPUB` values as `comments.publisher` and decoded
     * `TXXX` values under `comments.text[description]`. These are the safe
     * fallback when raw UTF-16 frame bytes were removed before JSON storage.
     *
     * @param  array<string, mixed>  $id3v2
     * @param  list<string>  $acceptedKeys
     * @return list<string>
     */
    private function id3CommentValues(array $id3v2, array $acceptedKeys): array
    {
        $comments = is_array($id3v2['comments'] ?? null) ? $id3v2['comments'] : [];
        $values = [];

        foreach ($comments as $name => $value) {
            if (is_string($name) && in_array($this->normalizedKey($name), $acceptedKeys, true)) {
                array_push($values, ...$this->values($value));
            }
        }

        $userText = is_array($comments['text'] ?? null) ? $comments['text'] : [];
        foreach ($userText as $description => $value) {
            if (is_string($description)
                && in_array($this->normalizedKey($description), $acceptedKeys, true)) {
                array_push($values, ...$this->values($value));
            }
        }

        return $this->uniqueValues($values);
    }

    /**
     * @param  array<string, mixed>  $rawMetadata
     * @param  list<string>  $acceptedKeys
     * @return list<string>
     */
    private function fieldValues(array $rawMetadata, array $acceptedKeys): array
    {
        $values = [];
        foreach ($this->commentMaps($rawMetadata) as $comments) {
            foreach ($comments as $name => $value) {
                if (is_string($name) && in_array($this->normalizedKey($name), $acceptedKeys, true)) {
                    array_push($values, ...$this->values($value));
                }
            }
        }

        $id3v2 = is_array($rawMetadata['id3v2'] ?? null) ? $rawMetadata['id3v2'] : [];
        if (in_array('publisher', $acceptedKeys, true)) {
            foreach ($this->frames($id3v2['TPUB'] ?? []) as $frame) {
                array_push($values, ...$this->frameValues($frame));
            }
        }
        foreach ($this->frames($id3v2['TXXX'] ?? []) as $frame) {
            $description = $this->normalizedKey((string) ($frame['description'] ?? ''));
            if (in_array($description, $acceptedKeys, true)) {
                array_push($values, ...$this->frameValues($frame));
            }
        }

        $apeItems = is_array($rawMetadata['ape']['items'] ?? null) ? $rawMetadata['ape']['items'] : [];
        foreach ($apeItems as $name => $item) {
            if (is_string($name)
                && in_array($this->normalizedKey($name), $acceptedKeys, true)
                && is_array($item)) {
                array_push($values, ...$this->values($item['data'] ?? []));
            }
        }

        return $this->uniqueValues($values);
    }

    /**
     * @param  array<string, mixed>  $rawMetadata
     * @return list<array<string, mixed>>
     */
    private function commentMaps(array $rawMetadata): array
    {
        $candidates = [
            $rawMetadata['comments'] ?? null,
            $rawMetadata['id3v2']['comments'] ?? null,
            $rawMetadata['vorbiscomment']['comments'] ?? null,
            $rawMetadata['ape']['comments'] ?? null,
            $rawMetadata['format']['tags'] ?? null,
            $rawMetadata['ffprobe_fallback']['format']['tags'] ?? null,
            $rawMetadata['ffprobe_fallback']['streams'][0]['tags'] ?? null,
        ];

        return array_values(array_filter($candidates, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    private function frames(mixed $frames): array
    {
        if (! is_array($frames)) {
            return [];
        }

        $frames = array_is_list($frames) ? $frames : [$frames];

        return array_values(array_filter($frames, 'is_array'));
    }

    /** @param array<string, mixed> $frame
     * @return list<string>
     */
    private function frameValues(array $frame): array
    {
        return $this->uniqueValues($this->decodedFrameValues($frame));
    }

    /** @param array<string, mixed> $frame
     * @return list<string>
     */
    private function decodedFrameValues(array $frame): array
    {
        $data = $frame['data'] ?? null;
        if (! is_string($data)) {
            return $this->values($data);
        }
        if (preg_match('/^\[binary data omitted: \d+ bytes\]$/', $data) === 1) {
            return [];
        }

        $encoding = is_string($frame['encoding'] ?? null) ? $frame['encoding'] : null;
        $normalizedEncoding = $encoding === null ? null : mb_strtoupper($encoding);
        if (in_array($normalizedEncoding, ['UTF-8', 'ISO-8859-1'], true)) {
            // Null bytes are valid multi-value separators for these encodings.
        } elseif (str_contains($data, "\0")) {
            $data = mb_convert_encoding($data, 'UTF-8', $this->utf16Encoding($data));
        } elseif ($encoding !== null) {
            $data = mb_convert_encoding($data, 'UTF-8', $encoding);
        }
        $data = preg_replace('/^\x{FEFF}/u', '', $data) ?? $data;

        return preg_split('/\x00+/u', $data) ?: [];
    }

    private function utf16Encoding(string $data): string
    {
        if (str_starts_with($data, "\xFF\xFE")) {
            return 'UTF-16LE';
        }
        if (str_starts_with($data, "\xFE\xFF")) {
            return 'UTF-16BE';
        }

        $evenNulls = 0;
        $oddNulls = 0;
        for ($index = 0, $length = strlen($data); $index < $length; $index++) {
            if ($data[$index] !== "\0") {
                continue;
            }
            $index % 2 === 0 ? $evenNulls++ : $oddNulls++;
        }

        return $evenNulls > $oddNulls ? 'UTF-16BE' : 'UTF-16LE';
    }

    /** @return list<string> */
    private function values(mixed $value): array
    {
        if (is_array($value)) {
            $values = [];
            foreach ($value as $item) {
                array_push($values, ...$this->values($item));
            }

            return $values;
        }

        return is_scalar($value) ? [(string) $value] : [];
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function uniqueValues(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $value = trim(str_replace("\0", '', $value));
            if ($value !== '') {
                $unique[mb_strtolower($value)] ??= $value;
            }
        }

        return array_values($unique);
    }

    private function normalizedKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($key)) ?? '';
    }
}
