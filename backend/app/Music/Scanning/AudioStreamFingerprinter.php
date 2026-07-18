<?php

namespace App\Music\Scanning;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class AudioStreamFingerprinter implements AudioContentFingerprinter
{
    private const READ_CHUNK_BYTES = 1024 * 1024;

    public function __construct(
        private readonly string $ffmpegBinary,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function fingerprint(string $absolutePath): string
    {
        $range = $this->audioByteRange($absolutePath);

        return $range === null
            ? $this->ffmpegFingerprint($absolutePath)
            : $this->hashRange($absolutePath, $range['offset'], $range['length']);
    }

    /** @return array{offset: int, length: int}|null */
    protected function audioByteRange(string $absolutePath): ?array
    {
        try {
            $information = (new \getID3())->analyze($absolutePath);
        } catch (Throwable) {
            return null;
        }

        $offset = $this->integer($information['avdataoffset'] ?? null);
        $end = $this->integer($information['avdataend'] ?? null);
        $fileSize = filesize($absolutePath);

        if ($offset === null
            || $end === null
            || $fileSize === false
            || $offset < 0
            || $end <= $offset
            || $end > $fileSize) {
            return null;
        }

        return ['offset' => $offset, 'length' => $end - $offset];
    }

    private function hashRange(string $absolutePath, int $offset, int $length): string
    {
        $stream = fopen($absolutePath, 'rb');
        if ($stream === false || fseek($stream, $offset) !== 0) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new RuntimeException('The audio payload could not be opened for fingerprinting.');
        }

        $hash = hash_init('sha256');
        $remaining = $length;

        try {
            while ($remaining > 0) {
                $chunk = fread($stream, min(self::READ_CHUNK_BYTES, $remaining));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('The audio payload could not be read for fingerprinting.');
                }

                hash_update($hash, $chunk);
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($hash);
    }

    private function ffmpegFingerprint(string $absolutePath): string
    {
        $result = Process::timeout($this->timeoutSeconds)->run([
            $this->ffmpegBinary,
            '-v',
            'error',
            '-nostdin',
            '-i',
            $absolutePath,
            '-map',
            '0:a:0',
            '-map_metadata',
            '-1',
            '-map_chapters',
            '-1',
            '-c:a',
            'copy',
            '-f',
            'hash',
            '-hash',
            'sha256',
            '-',
        ]);

        if (! $result->successful()) {
            $message = trim($result->errorOutput()) ?: 'FFmpeg could not fingerprint the audio stream.';

            throw new RuntimeException(mb_substr($message, 0, 1000));
        }

        if (preg_match('/^SHA256=([a-f\d]{64})$/mi', trim($result->output()), $matches) !== 1) {
            throw new RuntimeException('FFmpeg returned an invalid audio fingerprint.');
        }

        return mb_strtolower($matches[1]);
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : null;
    }
}
