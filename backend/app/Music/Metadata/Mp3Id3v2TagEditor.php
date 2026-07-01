<?php

namespace App\Music\Metadata;

use App\Music\PlaybackStatistics\UnsupportedPlaybackStatisticsTagFormat;
use Closure;
use RuntimeException;
use Throwable;

class Mp3Id3v2TagEditor
{
    public const ISSUE_UNSYNCHRONIZATION = 'id3v2_unsynchronization';

    public const ISSUE_EXTENDED_HEADER = 'id3v2_extended_header';

    public const ISSUE_EXPERIMENTAL = 'id3v2_experimental';

    public const ISSUE_FOOTER = 'id3v2_footer';

    public function supports(string $path): bool
    {
        return mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mp3';
    }

    public function majorVersion(string $path): int
    {
        $header = file_get_contents($path, false, null, 0, 10);
        if (! is_string($header) || strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') {
            return 4;
        }

        $majorVersion = ord($header[3]);
        if (! in_array($majorVersion, [3, 4], true)) {
            throw new UnsupportedPlaybackStatisticsTagFormat("ID3v2.{$majorVersion} tags are not supported for editing.");
        }

        return $majorVersion;
    }

    /** @param array<string, mixed> $rawMetadata */
    public function supportIssue(array $rawMetadata): ?string
    {
        $flags = is_array($rawMetadata['id3v2']['flags'] ?? null)
            ? $rawMetadata['id3v2']['flags']
            : [];
        $majorVersion = $rawMetadata['id3v2']['majorversion'] ?? null;

        return match (true) {
            ($flags['unsynch'] ?? false) === true && $majorVersion !== 4 => self::ISSUE_UNSYNCHRONIZATION,
            ($flags['exthead'] ?? false) === true => self::ISSUE_EXTENDED_HEADER,
            ($flags['experim'] ?? false) === true => self::ISSUE_EXPERIMENTAL,
            ($flags['isfooter'] ?? false) === true => self::ISSUE_FOOTER,
            default => null,
        };
    }

    public function supportIssueMessage(string $issue): string
    {
        return match ($issue) {
            self::ISSUE_UNSYNCHRONIZATION => 'ID3v2 tag-level unsynchronization is not supported for safe editing.',
            self::ISSUE_EXTENDED_HEADER => 'ID3v2 extended headers are not supported for safe editing.',
            self::ISSUE_EXPERIMENTAL => 'Experimental ID3v2 tags are not supported for safe editing.',
            self::ISSUE_FOOTER => 'ID3v2 footers are not supported for safe editing.',
            default => 'This ID3v2 tag structure is not supported for safe editing.',
        };
    }

    /**
     * @param  array<string, string|list<string>|null>  $textFrames
     * @param  array<string, ?string>  $userTextFrames
     * @param  array<string, ?string>  $commentFrames
     */
    public function write(
        string $path,
        array $textFrames,
        array $userTextFrames,
        Closure $verify,
        array $commentFrames = [],
    ): void {
        if (! $this->supports($path)) {
            throw new UnsupportedPlaybackStatisticsTagFormat(
                sprintf('ID3v2 editing is not supported for .%s files.', pathinfo($path, PATHINFO_EXTENSION)),
            );
        }

        if (is_link($path) || ! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
            throw new RuntimeException("Audio file [{$path}] is not a writable regular file.");
        }

        $suffix = '.music-library-metadata-'.bin2hex(random_bytes(8));
        $temporaryPath = $path.$suffix.'.tmp.'.pathinfo($path, PATHINFO_EXTENSION);
        $backupPath = $path.$suffix.'.bak';

        try {
            $this->writeTemporaryFile($path, $temporaryPath, $textFrames, $userTextFrames, $commentFrames);
            $verify($temporaryPath);
            $this->replaceOriginal($path, $temporaryPath, $backupPath);
        } finally {
            @unlink($temporaryPath);
            if (is_file($backupPath) && is_file($path)) {
                @unlink($backupPath);
            }
        }
    }

    /**
     * @param  array<string, string|list<string>|null>  $textFrames
     * @param  array<string, ?string>  $userTextFrames
     * @param  array<string, ?string>  $commentFrames
     */
    private function writeTemporaryFile(
        string $sourcePath,
        string $temporaryPath,
        array $textFrames,
        array $userTextFrames,
        array $commentFrames,
    ): void {
        $source = fopen($sourcePath, 'rb');
        $target = fopen($temporaryPath, 'xb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }

            throw new RuntimeException('Could not create a temporary file for ID3v2 editing.');
        }

        try {
            if (! flock($source, LOCK_SH)) {
                throw new RuntimeException('Could not lock the source audio file for ID3v2 editing.');
            }

            $firstBytes = $this->read($source, 10);
            if (substr($firstBytes, 0, 3) === 'ID3') {
                $majorVersion = ord($firstBytes[3]);
                $revision = ord($firstBytes[4]);
                $flags = ord($firstBytes[5]);
                if (! in_array($majorVersion, [3, 4], true)) {
                    throw new UnsupportedPlaybackStatisticsTagFormat("ID3v2.{$majorVersion} tags are not supported for editing.");
                }
                if ($issue = $this->headerSupportIssue($majorVersion, $flags)) {
                    throw new UnsupportedPlaybackStatisticsTagFormat($this->supportIssueMessage($issue));
                }

                $existingSize = $this->decodeSynchsafe(substr($firstBytes, 6, 4));
                $existingPayload = $this->read($source, $existingSize);
                $payload = $this->replaceFrames($existingPayload, $majorVersion, $textFrames, $userTextFrames, $commentFrames);
                $payloadSize = max($existingSize, strlen($payload) + 1024);
            } else {
                rewind($source);
                $majorVersion = 4;
                $revision = 0;
                $flags = 0;
                $payload = $this->newFrames($majorVersion, $textFrames, $userTextFrames, $commentFrames);
                $payloadSize = strlen($payload) + 1024;
            }

            if ($payloadSize > 0x0FFFFFFF) {
                throw new RuntimeException('The resulting ID3v2 tag is too large.');
            }

            $payload .= str_repeat("\0", $payloadSize - strlen($payload));
            $header = 'ID3'.chr($majorVersion).chr($revision).chr($flags).$this->encodeSynchsafe($payloadSize);
            $this->writeAll($target, $header.$payload);

            if (stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException('Could not copy the audio payload during ID3v2 editing.');
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

    /**
     * @param  array<string, string|list<string>|null>  $textFrames
     * @param  array<string, ?string>  $userTextFrames
     * @param  array<string, ?string>  $commentFrames
     */
    private function replaceFrames(
        string $payload,
        int $majorVersion,
        array $textFrames,
        array $userTextFrames,
        array $commentFrames,
    ): string {
        $offset = 0;
        $preserved = '';
        $payloadLength = strlen($payload);
        $targetDescriptions = array_map('mb_strtoupper', array_keys($userTextFrames));

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
            $replaceStandardFrame = array_key_exists($frameId, $textFrames);
            $replaceUserFrame = $frameId === 'TXXX'
                && in_array($this->textDescription($framePayload), $targetDescriptions, true);
            $replaceCommentFrame = $frameId === 'COMM'
                && array_key_exists('COMM', $commentFrames)
                && $this->commentDescription($framePayload) === '';
            if (! $replaceStandardFrame && ! $replaceUserFrame && ! $replaceCommentFrame) {
                $preserved .= $frame;
            }

            $offset += $frameLength;
        }

        if (trim(substr($payload, $offset), "\0") !== '') {
            throw new RuntimeException('The existing ID3v2 padding contains data that cannot be preserved safely.');
        }

        return $preserved.$this->newFrames($majorVersion, $textFrames, $userTextFrames, $commentFrames);
    }

    private function headerSupportIssue(int $majorVersion, int $flags): ?string
    {
        return match (true) {
            ($flags & 0x80) !== 0 && $majorVersion !== 4 => self::ISSUE_UNSYNCHRONIZATION,
            ($flags & 0x40) !== 0 => self::ISSUE_EXTENDED_HEADER,
            ($flags & 0x20) !== 0 => self::ISSUE_EXPERIMENTAL,
            $majorVersion === 4 && ($flags & 0x10) !== 0 => self::ISSUE_FOOTER,
            ($flags & ~($majorVersion === 4 ? 0x80 : 0)) !== 0 => 'id3v2_unknown_flags',
            default => null,
        };
    }

    /**
     * @param  array<string, string|list<string>|null>  $textFrames
     * @param  array<string, ?string>  $userTextFrames
     * @param  array<string, ?string>  $commentFrames
     */
    private function newFrames(
        int $majorVersion,
        array $textFrames,
        array $userTextFrames,
        array $commentFrames,
    ): string {
        $frames = '';
        foreach ($textFrames as $frameId => $value) {
            foreach ($value === null ? [] : (array) $value as $singleValue) {
                $frames .= $this->frame($majorVersion, $frameId, $this->textPayload($majorVersion, $singleValue));
            }
        }
        foreach ($userTextFrames as $name => $value) {
            if ($value !== null) {
                $frames .= $this->frame($majorVersion, 'TXXX', $this->userTextPayload($majorVersion, $name, $value));
            }
        }
        foreach ($commentFrames as $frameId => $value) {
            if ($value !== null) {
                $frames .= $this->frame($majorVersion, $frameId, $this->commentPayload($majorVersion, $value));
            }
        }

        return $frames;
    }

    private function frame(int $majorVersion, string $frameId, string $payload): string
    {
        $size = $majorVersion === 4
            ? $this->encodeSynchsafe(strlen($payload))
            : pack('N', strlen($payload));

        return $frameId.$size."\0\0".$payload;
    }

    private function textPayload(int $majorVersion, string $value): string
    {
        return $majorVersion === 4
            ? chr(3).$value
            : chr(1)."\xFF\xFE".mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
    }

    private function userTextPayload(int $majorVersion, string $name, string $value): string
    {
        if ($majorVersion === 4) {
            return chr(3).$name."\0".$value;
        }

        return chr(1)."\xFF\xFE"
            .mb_convert_encoding($name, 'UTF-16LE', 'UTF-8')
            ."\0\0"
            .mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
    }

    private function commentPayload(int $majorVersion, string $value): string
    {
        if ($majorVersion === 4) {
            return chr(3).'eng'."\0".$value;
        }

        return chr(1).'eng'."\xFF\xFE\0\0".mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
    }

    private function commentDescription(string $payload): ?string
    {
        if (strlen($payload) < 4) {
            return null;
        }

        return $this->textDescription($payload[0].substr($payload, 4));
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
            throw new RuntimeException("The file was updated, but temporary backup [{$backupPath}] could not be removed.");
        }
    }

    /** @param resource $stream */
    private function read($stream, int $length): string
    {
        $value = '';
        while (strlen($value) < $length && ! feof($stream)) {
            $chunk = fread($stream, $length - strlen($value));
            if ($chunk === false) {
                throw new RuntimeException('Could not read the audio file during ID3v2 editing.');
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
                throw new RuntimeException('Could not write the temporary ID3v2 tag.');
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
