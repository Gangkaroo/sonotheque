<?php

namespace App\Music\PlaybackStatistics;

use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

class Mp3PlaybackStatisticsTagWriter implements PlaybackStatisticsTagWriter
{
    private const FILETIME_UNIX_EPOCH_SECONDS = 11_644_473_600;

    private const FILETIME_TICKS_PER_SECOND = 10_000_000;

    private const TARGET_FIELDS = [
        'PLAY_COUNT',
        'FIRST_PLAYED_TIMESTAMP',
        'LAST_PLAYED_TIMESTAMP',
    ];

    public function __construct(private readonly PlaybackStatisticsTagReader $reader) {}

    public function supports(string $path): bool
    {
        return mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mp3';
    }

    public function write(
        string $path,
        int $playCount,
        ?CarbonInterface $firstPlayedAt,
        ?CarbonInterface $lastPlayedAt,
    ): void {
        if (! $this->supports($path)) {
            throw new UnsupportedPlaybackStatisticsTagFormat(
                sprintf('Playback-statistics export is not supported for .%s files.', pathinfo($path, PATHINFO_EXTENSION)),
            );
        }

        if (is_link($path) || ! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
            throw new RuntimeException("Audio file [{$path}] is not a writable regular file.");
        }

        $values = array_filter([
            'PLAY_COUNT' => (string) max(0, $playCount),
            'FIRST_PLAYED_TIMESTAMP' => $this->fileTime($firstPlayedAt),
            'LAST_PLAYED_TIMESTAMP' => $this->fileTime($lastPlayedAt),
        ], static fn (?string $value): bool => $value !== null);

        $suffix = '.music-library-statistics-'.bin2hex(random_bytes(8));
        $temporaryPath = $path.$suffix.'.tmp';
        $backupPath = $path.$suffix.'.bak';

        try {
            $this->writeTemporaryFile($path, $temporaryPath, $values);
            $this->verify($temporaryPath, $playCount, $firstPlayedAt, $lastPlayedAt);
            $this->replaceOriginal($path, $temporaryPath, $backupPath);
        } finally {
            @unlink($temporaryPath);
            if (is_file($backupPath) && is_file($path)) {
                @unlink($backupPath);
            }
        }
    }

    /** @param array<string, string> $values */
    private function writeTemporaryFile(string $sourcePath, string $temporaryPath, array $values): void
    {
        $source = fopen($sourcePath, 'rb');
        $target = fopen($temporaryPath, 'xb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }

            throw new RuntimeException('Could not create a temporary file for playback-statistics export.');
        }

        try {
            if (! flock($source, LOCK_SH)) {
                throw new RuntimeException('Could not lock the source audio file for playback-statistics export.');
            }

            $firstBytes = $this->read($source, 10);
            if (substr($firstBytes, 0, 3) === 'ID3') {
                $majorVersion = ord($firstBytes[3]);
                $revision = ord($firstBytes[4]);
                $flags = ord($firstBytes[5]);
                if (! in_array($majorVersion, [3, 4], true)) {
                    throw new UnsupportedPlaybackStatisticsTagFormat("ID3v2.{$majorVersion} tags are not supported for statistics export.");
                }
                if ($flags !== 0) {
                    throw new UnsupportedPlaybackStatisticsTagFormat('ID3v2 tags with extended flags are not supported for statistics export.');
                }

                $existingSize = $this->decodeSynchsafe(substr($firstBytes, 6, 4));
                $existingPayload = $this->read($source, $existingSize);
                $payload = $this->replaceFrames($existingPayload, $majorVersion, $values);
                $payloadSize = max($existingSize, strlen($payload) + 1024);
            } else {
                rewind($source);
                $majorVersion = 4;
                $revision = 0;
                $flags = 0;
                $payload = $this->newFrames($majorVersion, $values);
                $payloadSize = strlen($payload) + 1024;
            }

            if ($payloadSize > 0x0FFFFFFF) {
                throw new RuntimeException('The resulting ID3v2 tag is too large.');
            }

            $payload .= str_repeat("\0", $payloadSize - strlen($payload));
            $header = 'ID3'.chr($majorVersion).chr($revision).chr($flags).$this->encodeSynchsafe($payloadSize);
            $this->writeAll($target, $header.$payload);

            $copied = stream_copy_to_stream($source, $target);
            if ($copied === false) {
                throw new RuntimeException('Could not copy the audio payload during playback-statistics export.');
            }

            fflush($target);
            if (function_exists('fsync')) {
                fsync($target);
            }
        } finally {
            if (is_resource($source)) {
                flock($source, LOCK_UN);
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
        }

        $permissions = fileperms($sourcePath);
        if ($permissions !== false) {
            @chmod($temporaryPath, $permissions & 0777);
        }
    }

    /** @param array<string, string> $values */
    private function replaceFrames(string $payload, int $majorVersion, array $values): string
    {
        $offset = 0;
        $preserved = '';
        $payloadLength = strlen($payload);

        while ($offset + 10 <= $payloadLength) {
            $frameId = substr($payload, $offset, 4);
            if ($frameId === "\0\0\0\0") {
                break;
            }
            if (preg_match('/^[A-Z0-9]{4}$/', $frameId) !== 1) {
                throw new RuntimeException('The existing ID3v2 frame layout could not be preserved safely.');
            }

            $frameSizeBytes = substr($payload, $offset + 4, 4);
            $frameSize = $majorVersion === 4
                ? $this->decodeSynchsafe($frameSizeBytes)
                : unpack('Nsize', $frameSizeBytes)['size'];
            $frameLength = 10 + $frameSize;
            if ($frameSize < 1 || $offset + $frameLength > $payloadLength) {
                throw new RuntimeException('The existing ID3v2 frame size is invalid.');
            }

            $frame = substr($payload, $offset, $frameLength);
            $framePayload = substr($frame, 10);
            if ($frameId !== 'TXXX' || ! in_array($this->textDescription($framePayload), self::TARGET_FIELDS, true)) {
                $preserved .= $frame;
            }

            $offset += $frameLength;
        }

        if (trim(substr($payload, $offset), "\0") !== '') {
            throw new RuntimeException('The existing ID3v2 padding contains data that cannot be preserved safely.');
        }

        return $preserved.$this->newFrames($majorVersion, $values);
    }

    /** @param array<string, string> $values */
    private function newFrames(int $majorVersion, array $values): string
    {
        $frames = '';
        foreach ($values as $name => $value) {
            $payload = "\0{$name}\0{$value}";
            $size = $majorVersion === 4
                ? $this->encodeSynchsafe(strlen($payload))
                : pack('N', strlen($payload));
            $frames .= 'TXXX'.$size."\0\0".$payload;
        }

        return $frames;
    }

    private function textDescription(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        $encoding = ord($payload[0]);
        $text = substr($payload, 1);
        if (in_array($encoding, [0, 3], true)) {
            $terminator = strpos($text, "\0");
            $description = $terminator === false ? $text : substr($text, 0, $terminator);
            $sourceEncoding = $encoding === 3 ? 'UTF-8' : 'ISO-8859-1';
        } elseif (in_array($encoding, [1, 2], true)) {
            $terminator = null;
            for ($index = 0, $length = strlen($text) - 1; $index < $length; $index += 2) {
                if ($text[$index] === "\0" && $text[$index + 1] === "\0") {
                    $terminator = $index;
                    break;
                }
            }
            $description = $terminator === null ? $text : substr($text, 0, $terminator);
            $sourceEncoding = $encoding === 1 ? 'UTF-16' : 'UTF-16BE';
        } else {
            return null;
        }

        return mb_strtoupper(trim(mb_convert_encoding($description, 'UTF-8', $sourceEncoding)));
    }

    private function verify(
        string $path,
        int $playCount,
        ?CarbonInterface $firstPlayedAt,
        ?CarbonInterface $lastPlayedAt,
    ): void {
        $information = (new \getID3)->analyze($path);
        \getid3_lib::CopyTagsToComments($information);
        $statistics = $this->reader->read($information);

        if ($statistics->playCount !== max(0, $playCount)
            || ! $this->sameInstant($statistics->firstPlayedAt, $firstPlayedAt)
            || ! $this->sameInstant($statistics->lastPlayedAt, $lastPlayedAt)) {
            throw new RuntimeException('Playback-statistics tags could not be verified after writing.');
        }
    }

    private function replaceOriginal(string $path, string $temporaryPath, string $backupPath): void
    {
        if (! rename($path, $backupPath)) {
            throw new RuntimeException('Could not move the original audio file into the temporary backup location.');
        }

        try {
            if (! rename($temporaryPath, $path)) {
                throw new RuntimeException('Could not move the updated audio file into place.');
            }
        } catch (Throwable $exception) {
            if (! is_file($path)) {
                @rename($backupPath, $path);
            }

            throw $exception;
        }

        if (! unlink($backupPath)) {
            throw new RuntimeException("Playback statistics were written, but temporary backup [{$backupPath}] could not be removed.");
        }
    }

    private function fileTime(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return (string) ((($date->getTimestamp() + self::FILETIME_UNIX_EPOCH_SECONDS) * self::FILETIME_TICKS_PER_SECOND)
            + ((int) $date->format('u') * 10));
    }

    private function sameInstant(?CarbonInterface $left, ?CarbonInterface $right): bool
    {
        return $left === null || $right === null
            ? $left === null && $right === null
            : $left->toJSON() === $right->toImmutable()->utc()->toJSON();
    }

    /** @param resource $stream */
    private function read($stream, int $length): string
    {
        $value = '';
        while (strlen($value) < $length && ! feof($stream)) {
            $chunk = fread($stream, $length - strlen($value));
            if ($chunk === false) {
                throw new RuntimeException('Could not read the audio file during playback-statistics export.');
            }
            $value .= $chunk;
        }

        if (strlen($value) !== $length) {
            throw new RuntimeException('The audio file ended before its ID3v2 tag was complete.');
        }

        return $value;
    }

    /** @param resource $stream */
    private function writeAll($stream, string $value): void
    {
        $offset = 0;
        while ($offset < strlen($value)) {
            $written = fwrite($stream, substr($value, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the temporary playback-statistics tag.');
            }
            $offset += $written;
        }
    }

    private function decodeSynchsafe(string $value): int
    {
        if (strlen($value) !== 4) {
            throw new RuntimeException('An invalid synchsafe integer was encountered.');
        }

        $bytes = array_values(unpack('C4', $value));
        foreach ($bytes as $byte) {
            if (($byte & 0x80) !== 0) {
                throw new RuntimeException('An invalid synchsafe integer was encountered.');
            }
        }

        return ($bytes[0] << 21) | ($bytes[1] << 14) | ($bytes[2] << 7) | $bytes[3];
    }

    private function encodeSynchsafe(int $value): string
    {
        return pack('C4',
            ($value >> 21) & 0x7F,
            ($value >> 14) & 0x7F,
            ($value >> 7) & 0x7F,
            $value & 0x7F,
        );
    }
}
