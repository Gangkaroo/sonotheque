<?php

namespace App\Music\Streaming;

class ByteRangeParser
{
    public function parse(?string $header, int $fileSize): ?ByteRange
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        if ($fileSize <= 0 || preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches) !== 1) {
            throw new InvalidByteRange;
        }

        [$startValue, $endValue] = [$matches[1], $matches[2]];

        if ($startValue === '' && $endValue === '') {
            throw new InvalidByteRange;
        }

        if ($startValue === '') {
            $suffixLength = (int) $endValue;

            if ($suffixLength <= 0) {
                throw new InvalidByteRange;
            }

            return new ByteRange(max(0, $fileSize - $suffixLength), $fileSize - 1);
        }

        $start = (int) $startValue;
        $end = $endValue === '' ? $fileSize - 1 : (int) $endValue;

        if ($start >= $fileSize || $end < $start) {
            throw new InvalidByteRange;
        }

        return new ByteRange($start, min($end, $fileSize - 1));
    }
}
