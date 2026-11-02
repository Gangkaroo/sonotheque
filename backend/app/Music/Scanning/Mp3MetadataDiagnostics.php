<?php

namespace App\Music\Scanning;

final class Mp3MetadataDiagnostics
{
    private const MAX_SECONDARY_TAG_BYTES = 4 * 1024 * 1024;

    /**
     * @param  array<string, mixed>  $information
     * @return list<array{offset: int, length: int, tagCount: int}>
     */
    public function secondaryTags(string $path, array $information): array
    {
        if (($information['fileformat'] ?? null) !== 'mp3' || ! empty($information['error'])) {
            return [];
        }

        $tagEnd = $information['id3v2']['tag_offset_end'] ?? null;
        $audioOffset = $information['avdataoffset'] ?? null;
        if (! is_int($tagEnd) || ! is_int($audioOffset) || $tagEnd < 10
            || $audioOffset <= $tagEnd || $audioOffset - $tagEnd > self::MAX_SECONDARY_TAG_BYTES) {
            return [];
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return [];
        }
        try {
            if (fseek($stream, $tagEnd) !== 0) {
                return [];
            }
            $data = stream_get_contents($stream, $audioOffset - $tagEnd);
        } finally {
            fclose($stream);
        }
        if (! is_string($data) || strlen($data) !== $audioOffset - $tagEnd) {
            return [];
        }

        $offset = 0;
        $count = 0;
        while ($offset < strlen($data)) {
            $header = substr($data, $offset, 10);
            if (strlen($header) !== 10 || substr($header, 0, 3) !== 'ID3'
                || ! in_array(ord($header[3]), [3, 4], true) || ord($header[4]) !== 0) {
                return [];
            }
            $version = ord($header[3]);
            // Do not classify extended, experimental, or globally unsynchronized v2.3 tags.
            if (ord($header[5]) !== 0 && ! ($version === 4 && ord($header[5]) === 0x80)) {
                return [];
            }
            $size = $this->synchsafe(substr($header, 6, 4));
            if ($size === null || $size < 11 || $offset + 10 + $size > strlen($data)
                || ! $this->validFrames(substr($data, $offset + 10, $size), $version)) {
                return [];
            }
            $offset += 10 + $size;
            $count++;
        }

        return [['offset' => $tagEnd, 'length' => strlen($data), 'tagCount' => $count]];
    }

    /**
     * @param  array<string, mixed>  $information
     * @param  list<array{offset: int, length: int, tagCount: int}>  $secondaryTags
     */
    public function explainedBySecondaryTags(string $warning, array $information, array $secondaryTags): bool
    {
        foreach ($secondaryTags as $range) {
            if (preg_match('/^Unknown data before synch \(ID3v2 header ends at (\d+), then (\d+) bytes garbage, synch detected at (\d+)\)\.?$/', $warning, $matches)
                && (int) $matches[1] === $range['offset']
                && (int) $matches[2] === $range['length']
                && (int) $matches[3] === $range['offset'] + $range['length']) {
                return true;
            }
            if (preg_match('/^Too much data in file: expecting (\d+) bytes of audio data, found (\d+) \((\d+) bytes too many\)$/', $warning, $matches)
                && (int) $matches[3] === $range['length']
                && (int) $matches[2] - (int) $matches[1] === $range['length']
                && isset($information['avdataend'], $information['avdataoffset'])
                && $information['avdataend'] - $information['avdataoffset'] === (int) $matches[1]) {
                return true;
            }
        }

        return false;
    }

    private function validFrames(string $payload, int $version): bool
    {
        $offset = 0;
        $frames = 0;
        while ($offset < strlen($payload)) {
            if ($payload[$offset] === "\0") {
                return $frames > 0 && strspn($payload, "\0", $offset) === strlen($payload) - $offset;
            }
            $header = substr($payload, $offset, 10);
            if (strlen($header) !== 10 || preg_match('/^[A-Z0-9]{4}$/D', substr($header, 0, 4)) !== 1) {
                return false;
            }
            $size = $version === 4
                ? $this->synchsafe(substr($header, 4, 4))
                : unpack('N', substr($header, 4, 4))[1];
            if ($size === null || $size < 1 || $offset + 10 + $size > strlen($payload)) {
                return false;
            }
            $offset += 10 + $size;
            $frames++;
        }

        return $frames > 0;
    }

    private function synchsafe(string $bytes): ?int
    {
        $value = 0;
        foreach (unpack('C*', $bytes) as $byte) {
            if ($byte > 127) {
                return null;
            }
            $value = ($value << 7) | $byte;
        }

        return $value;
    }
}
