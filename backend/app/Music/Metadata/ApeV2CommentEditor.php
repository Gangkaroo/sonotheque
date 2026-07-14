<?php

namespace App\Music\Metadata;

use RuntimeException;

class ApeV2CommentEditor
{
    private const HEADER_SIZE = 32;

    private const VERSION = 2000;

    /**
     * @param  resource  $stream
     * @return null|array{offset: int, length: int, replacement: string}
     */
    public function replacement($stream, int $fileSize, int $trailingBytes, ?string $comment): ?array
    {
        $originalPosition = ftell($stream);
        if (! is_int($originalPosition)) {
            throw new RuntimeException('Could not inspect the trailing APEv2 tag.');
        }

        try {
            $footerOffset = $fileSize - $trailingBytes - self::HEADER_SIZE;
            if ($footerOffset < 0 || fseek($stream, $footerOffset) !== 0) {
                return null;
            }

            $footer = $this->read($stream, self::HEADER_SIZE);
            if (! str_starts_with($footer, 'APETAGEX')) {
                return null;
            }

            $metadata = unpack('Vversion/Vsize/Vitems/Vflags', substr($footer, 8, 16));
            if (! is_array($metadata)
                || $metadata['version'] !== self::VERSION
                || $metadata['size'] < self::HEADER_SIZE) {
                throw new RuntimeException('The trailing APE tag cannot be edited safely.');
            }

            $hasHeader = ($metadata['flags'] & 0x80000000) !== 0;
            $tagLength = $metadata['size'] + ($hasHeader ? self::HEADER_SIZE : 0);
            $tagOffset = $footerOffset + self::HEADER_SIZE - $tagLength;
            if ($tagOffset < 0 || $tagOffset < $originalPosition || fseek($stream, $tagOffset) !== 0) {
                throw new RuntimeException('The trailing APEv2 tag position is invalid.');
            }

            $tag = $this->read($stream, $tagLength);
            $header = $hasHeader ? substr($tag, 0, self::HEADER_SIZE) : null;
            if ($header !== null && ! str_starts_with($header, 'APETAGEX')) {
                throw new RuntimeException('The trailing APEv2 header is invalid.');
            }

            $itemsOffset = $hasHeader ? self::HEADER_SIZE : 0;
            $itemsLength = $metadata['size'] - self::HEADER_SIZE;
            $items = substr($tag, $itemsOffset, $itemsLength);
            [$preservedItems, $preservedCount] = $this->withoutComment($items, $metadata['items']);

            if ($comment !== null) {
                $value = mb_convert_encoding(str_replace("\0", '', $comment), 'UTF-8', 'UTF-8');
                $preservedItems .= pack('V', strlen($value)).pack('V', 0).'Comment' . "\0" . $value;
                $preservedCount++;
            }

            $size = strlen($preservedItems) + self::HEADER_SIZE;
            $replacement = $header === null
                ? ''
                : $this->updatedBoundary($header, $size, $preservedCount);
            $replacement .= $preservedItems.$this->updatedBoundary($footer, $size, $preservedCount);

            return [
                'offset' => $tagOffset,
                'length' => $tagLength,
                'replacement' => $replacement,
            ];
        } finally {
            fseek($stream, $originalPosition);
        }
    }

    /** @return array{string, int} */
    private function withoutComment(string $items, int $itemCount): array
    {
        $offset = 0;
        $preserved = '';
        $preservedCount = 0;

        for ($index = 0; $index < $itemCount; $index++) {
            if ($offset + 8 > strlen($items)) {
                throw new RuntimeException('The trailing APEv2 item layout is invalid.');
            }

            $itemHeader = unpack('VvalueSize/Vflags', substr($items, $offset, 8));
            $keyEnd = strpos($items, "\0", $offset + 8);
            if (! is_array($itemHeader) || $keyEnd === false) {
                throw new RuntimeException('The trailing APEv2 item layout is invalid.');
            }

            $key = substr($items, $offset + 8, $keyEnd - $offset - 8);
            $itemLength = $keyEnd + 1 - $offset + $itemHeader['valueSize'];
            if ($key === '' || $offset + $itemLength > strlen($items)) {
                throw new RuntimeException('The trailing APEv2 item size is invalid.');
            }

            if (mb_strtolower($key) !== 'comment') {
                $preserved .= substr($items, $offset, $itemLength);
                $preservedCount++;
            }

            $offset += $itemLength;
        }

        if ($offset !== strlen($items)) {
            throw new RuntimeException('The trailing APEv2 tag contains unrecognized item data.');
        }

        return [$preserved, $preservedCount];
    }

    private function updatedBoundary(string $boundary, int $size, int $itemCount): string
    {
        return substr($boundary, 0, 12)
            .pack('V', $size)
            .pack('V', $itemCount)
            .substr($boundary, 20);
    }

    /** @param resource $stream */
    private function read($stream, int $length): string
    {
        $value = '';
        while (strlen($value) < $length && ! feof($stream)) {
            $chunk = fread($stream, $length - strlen($value));
            if ($chunk === false) {
                throw new RuntimeException('Could not read the trailing APEv2 tag.');
            }
            $value .= $chunk;
        }

        if (strlen($value) !== $length) {
            throw new RuntimeException('The trailing APEv2 tag is truncated.');
        }

        return $value;
    }
}
