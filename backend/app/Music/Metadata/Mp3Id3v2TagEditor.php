<?php

namespace App\Music\Metadata;

use App\Music\PlaybackStatistics\UnsupportedPlaybackStatisticsTagFormat;
use Closure;
use RuntimeException;
use Throwable;

class Mp3Id3v2TagEditor
{
    /** @var array<string, string> */
    private const V22_FRAME_MAP = [
        'BUF' => 'RBUF', 'CNT' => 'PCNT', 'COM' => 'COMM', 'CRA' => 'AENC',
        'ETC' => 'ETCO', 'EQU' => 'EQUA', 'GEO' => 'GEOB', 'IPL' => 'IPLS',
        'LNK' => 'LINK', 'MCI' => 'MCDI', 'MLL' => 'MLLT', 'PIC' => 'APIC',
        'POP' => 'POPM', 'REV' => 'RVRB', 'RVA' => 'RVAD', 'SLT' => 'SYLT',
        'STC' => 'SYTC', 'TAL' => 'TALB', 'TBP' => 'TBPM', 'TCM' => 'TCOM',
        'TCO' => 'TCON', 'TCR' => 'TCOP', 'TDA' => 'TDAT', 'TDY' => 'TDLY',
        'TEN' => 'TENC', 'TFT' => 'TFLT', 'TIM' => 'TIME', 'TKE' => 'TKEY',
        'TLA' => 'TLAN', 'TLE' => 'TLEN', 'TMT' => 'TMED', 'TOA' => 'TOPE',
        'TOF' => 'TOFN', 'TOL' => 'TOLY', 'TOR' => 'TORY', 'TOT' => 'TOAL',
        'TP1' => 'TPE1', 'TP2' => 'TPE2', 'TP3' => 'TPE3', 'TP4' => 'TPE4',
        'TPA' => 'TPOS', 'TPB' => 'TPUB', 'TRC' => 'TSRC', 'TRD' => 'TRDA',
        'TRK' => 'TRCK', 'TSI' => 'TSIZ', 'TSS' => 'TSSE', 'TT1' => 'TIT1',
        'TT2' => 'TIT2', 'TT3' => 'TIT3', 'TXT' => 'TEXT', 'TXX' => 'TXXX',
        'TYE' => 'TYER', 'UFI' => 'UFID', 'ULT' => 'USLT', 'WAF' => 'WOAF',
        'WAR' => 'WOAR', 'WAS' => 'WOAS', 'WCM' => 'WCOM', 'WCP' => 'WCOP',
        'WPB' => 'WPUB', 'WXX' => 'WXXX',
    ];

    public const ISSUE_UNSYNCHRONIZATION = 'id3v2_unsynchronization';

    public const ISSUE_EXTENDED_HEADER = 'id3v2_extended_header';

    public const ISSUE_EXPERIMENTAL = 'id3v2_experimental';

    public const ISSUE_FOOTER = 'id3v2_footer';

    public const ISSUE_COMPRESSION = 'id3v2_compression';

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
        if (! in_array($majorVersion, [2, 3, 4], true)) {
            throw new UnsupportedPlaybackStatisticsTagFormat("ID3v2.{$majorVersion} tags are not supported for editing.");
        }

        return $majorVersion === 2 ? 3 : $majorVersion;
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
            $majorVersion === 2 && (($flags['compression'] ?? $flags['iscompression'] ?? false) === true) => self::ISSUE_COMPRESSION,
            $majorVersion !== 2 && ($flags['exthead'] ?? false) === true => self::ISSUE_EXTENDED_HEADER,
            $majorVersion !== 2 && ($flags['experim'] ?? false) === true => self::ISSUE_EXPERIMENTAL,
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
            self::ISSUE_COMPRESSION => 'Compressed ID3v2.2 tags are not supported for safe conversion.',
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

        $updatesId3v1Comment = array_key_exists('COMM', $commentFrames) && $this->hasId3v1Tag($path);
        $inPlaceReplacement = $updatesId3v1Comment
            ? null
            : $this->inPlaceReplacement($path, $textFrames, $userTextFrames, $commentFrames);
        if ($inPlaceReplacement !== null) {
            $this->writeTagInPlace(
                $path,
                $inPlaceReplacement['original'],
                $inPlaceReplacement['replacement'],
                $verify,
            );

            return;
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
     * @return null|array{original: string, replacement: string}
     */
    private function inPlaceReplacement(
        string $path,
        array $textFrames,
        array $userTextFrames,
        array $commentFrames,
    ): ?array {
        $source = fopen($path, 'rb');
        if ($source === false) {
            throw new RuntimeException('Could not read the audio file for ID3v2 editing.');
        }

        try {
            if (! flock($source, LOCK_SH)) {
                throw new RuntimeException('Could not lock the source audio file for ID3v2 editing.');
            }

            $existing = $this->readExistingTag($source);
            if ($existing === null) {
                return null;
            }

            $payload = $this->replaceFrames(
                $existing['payload'],
                $existing['majorVersion'],
                $textFrames,
                $userTextFrames,
                $commentFrames,
            );
            if (strlen($payload) > $existing['payloadSize']) {
                return null;
            }

            $payload .= str_repeat("\0", $existing['payloadSize'] - strlen($payload));

            return [
                'original' => $existing['originalHeader'].$existing['originalPayload'],
                'replacement' => $existing['header'].$payload,
            ];
        } finally {
            flock($source, LOCK_UN);
            fclose($source);
        }
    }

    private function writeTagInPlace(
        string $path,
        string $originalTag,
        string $replacementTag,
        Closure $verify,
    ): void {
        $backupPath = $path.'.music-library-tag-'.bin2hex(random_bytes(8)).'.bak';
        $this->writeRecoveryTag($backupPath, $originalTag);
        $writeStarted = false;

        try {
            $this->replaceTagPrefix($path, $originalTag, $replacementTag, $writeStarted);
            $verify($path);
        } catch (Throwable $exception) {
            if ($writeStarted) {
                try {
                    $this->restoreTagPrefix($path, $originalTag);
                } catch (Throwable $restoreException) {
                    throw new RuntimeException(
                        "Metadata verification failed and the original tag could not be restored. Recovery data remains at [{$backupPath}].",
                        previous: $exception,
                    );
                }
            }

            @unlink($backupPath);
            throw $exception;
        }

        if (! unlink($backupPath)) {
            throw new RuntimeException("The file was updated, but temporary tag backup [{$backupPath}] could not be removed.");
        }
    }

    private function writeRecoveryTag(string $path, string $tag): void
    {
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Could not create the temporary ID3v2 recovery data.');
        }

        try {
            $this->writeAll($stream, $tag);
            fflush($stream);
            if (function_exists('fsync')) {
                fsync($stream);
            }
        } catch (Throwable $exception) {
            fclose($stream);
            @unlink($path);
            throw $exception;
        }

        fclose($stream);
    }

    private function replaceTagPrefix(
        string $path,
        string $expectedTag,
        string $replacementTag,
        bool &$writeStarted,
    ): void {
        $stream = fopen($path, 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Could not open the audio file for in-place ID3v2 editing.');
        }

        try {
            if (! flock($stream, LOCK_EX)) {
                throw new RuntimeException('Could not lock the audio file for in-place ID3v2 editing.');
            }

            if (! hash_equals($expectedTag, $this->read($stream, strlen($expectedTag)))) {
                throw new RuntimeException('The ID3v2 tag changed before it could be updated.');
            }

            rewind($stream);
            $writeStarted = true;
            $this->writeAll($stream, $replacementTag);
            fflush($stream);
            if (function_exists('fsync')) {
                fsync($stream);
            }
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function restoreTagPrefix(string $path, string $originalTag): void
    {
        $stream = fopen($path, 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Could not reopen the audio file to restore its original ID3v2 tag.');
        }

        try {
            if (! flock($stream, LOCK_EX)) {
                throw new RuntimeException('Could not lock the audio file to restore its original ID3v2 tag.');
            }

            $this->writeAll($stream, $originalTag);
            fflush($stream);
            if (function_exists('fsync')) {
                fsync($stream);
            }
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
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

            $existing = $this->readExistingTag($source);
            if ($existing !== null) {
                $majorVersion = $existing['majorVersion'];
                $revision = $existing['revision'];
                $flags = $existing['flags'];
                $payload = $this->replaceFrames(
                    $existing['payload'],
                    $majorVersion,
                    $textFrames,
                    $userTextFrames,
                    $commentFrames,
                );
                $payloadSize = max($existing['payloadSize'], strlen($payload) + 1024);
            } else {
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

            $id3v1Tag = array_key_exists('COMM', $commentFrames)
                ? $this->readId3v1Tag($source)
                : null;
            if ($id3v1Tag !== null) {
                $sourceSize = fstat($source)['size'] ?? null;
                $sourcePosition = ftell($source);
                if (! is_int($sourceSize) || ! is_int($sourcePosition) || $sourceSize - $sourcePosition < 128) {
                    throw new RuntimeException('Could not locate the existing ID3v1 tag safely.');
                }
                if (stream_copy_to_stream($source, $target, $sourceSize - $sourcePosition - 128) === false) {
                    throw new RuntimeException('Could not copy the audio payload during ID3v2 editing.');
                }
                $this->writeAll($target, $this->withId3v1Comment($id3v1Tag, $commentFrames['COMM']));
            } elseif (stream_copy_to_stream($source, $target) === false) {
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

    private function hasId3v1Tag(string $path): bool
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return false;
        }

        try {
            return $this->readId3v1Tag($stream) !== null;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function readId3v1Tag($stream): ?string
    {
        $position = ftell($stream);
        if (! is_int($position) || fseek($stream, -128, SEEK_END) !== 0) {
            return null;
        }

        try {
            $tag = fread($stream, 128);

            return is_string($tag) && strlen($tag) === 128 && str_starts_with($tag, 'TAG')
                ? $tag
                : null;
        } finally {
            fseek($stream, $position, SEEK_SET);
        }
    }

    private function withId3v1Comment(string $tag, ?string $comment): string
    {
        $usesTrackNumber = $tag[125] === "\0" && $tag[126] !== "\0";
        $length = $usesTrackNumber ? 28 : 30;
        $encoded = $comment === null ? '' : mb_convert_encoding($comment, 'ISO-8859-1', 'UTF-8');
        $encoded = str_pad(substr($encoded, 0, $length), $length, "\0");
        $trackNumber = $usesTrackNumber ? substr($tag, 125, 2) : '';

        return substr($tag, 0, 97).$encoded.$trackNumber.$tag[127];
    }

    /**
     * @param  resource  $source
     * @return null|array{originalHeader: string, originalPayload: string, header: string, majorVersion: int, revision: int, flags: int, payloadSize: int, payload: string}
     */
    private function readExistingTag($source): ?array
    {
        $header = $this->read($source, 10);
        if (substr($header, 0, 3) !== 'ID3') {
            rewind($source);

            return null;
        }

        $sourceMajorVersion = ord($header[3]);
        $revision = ord($header[4]);
        $flags = ord($header[5]);
        if (! in_array($sourceMajorVersion, [2, 3, 4], true)) {
            throw new UnsupportedPlaybackStatisticsTagFormat("ID3v2.{$sourceMajorVersion} tags are not supported for editing.");
        }
        if ($issue = $this->headerSupportIssue($sourceMajorVersion, $flags)) {
            throw new UnsupportedPlaybackStatisticsTagFormat($this->supportIssueMessage($issue));
        }

        $payloadSize = $this->decodeSynchsafe(substr($header, 6, 4));
        $originalPayload = $this->read($source, $payloadSize);
        $majorVersion = $sourceMajorVersion === 2 ? 3 : $sourceMajorVersion;
        $targetRevision = $sourceMajorVersion === 2 ? 0 : $revision;
        $targetFlags = $sourceMajorVersion === 2 ? 0 : $flags;
        $payload = $sourceMajorVersion === 2
            ? $this->convertV22Payload($originalPayload)
            : $originalPayload;

        return [
            'originalHeader' => $header,
            'originalPayload' => $originalPayload,
            'header' => 'ID3'.chr($majorVersion).chr($targetRevision).chr($targetFlags).$this->encodeSynchsafe($payloadSize),
            'majorVersion' => $majorVersion,
            'revision' => $targetRevision,
            'flags' => $targetFlags,
            'payloadSize' => $payloadSize,
            'payload' => $payload,
        ];
    }

    private function convertV22Payload(string $payload): string
    {
        $offset = 0;
        $converted = '';
        $payloadLength = strlen($payload);

        while ($offset + 6 <= $payloadLength) {
            $frameId = substr($payload, $offset, 3);
            if ($frameId === "\0\0\0") {
                break;
            }
            if (preg_match('/^[A-Z0-9]{3}$/', $frameId) !== 1) {
                throw new RuntimeException('The existing ID3v2.2 frame layout could not be converted safely.');
            }

            $sizeBytes = array_values(unpack('C3', substr($payload, $offset + 3, 3)));
            $frameSize = ($sizeBytes[0] << 16) | ($sizeBytes[1] << 8) | $sizeBytes[2];
            $frameLength = 6 + $frameSize;
            if ($offset + $frameLength > $payloadLength) {
                throw new RuntimeException('The existing ID3v2.2 frame size is invalid.');
            }
            if ($frameSize === 0) {
                $offset += $frameLength;

                continue;
            }

            $targetFrameId = self::V22_FRAME_MAP[$frameId] ?? null;
            if ($targetFrameId === null) {
                throw new UnsupportedPlaybackStatisticsTagFormat(
                    "ID3v2.2 frame [{$frameId}] cannot be converted without risking metadata loss.",
                );
            }

            $framePayload = substr($payload, $offset + 6, $frameSize);
            if ($frameId === 'PIC') {
                $framePayload = $this->convertV22Picture($framePayload);
            }
            $converted .= $this->frame(3, $targetFrameId, $framePayload);
            $offset += $frameLength;
        }

        if (trim(substr($payload, $offset), "\0") !== '') {
            throw new RuntimeException('The existing ID3v2.2 padding contains data that cannot be converted safely.');
        }

        return $converted;
    }

    private function convertV22Picture(string $payload): string
    {
        if (strlen($payload) < 5) {
            throw new RuntimeException('The existing ID3v2.2 picture frame is invalid.');
        }

        $format = strtoupper(substr($payload, 1, 3));
        if (preg_match('/^[A-Z0-9]{3}$/', $format) !== 1) {
            throw new RuntimeException('The existing ID3v2.2 picture format is invalid.');
        }
        $mimeType = match ($format) {
            'JPG' => 'image/jpeg',
            'PNG' => 'image/png',
            'GIF' => 'image/gif',
            default => 'image/'.strtolower($format),
        };

        return $payload[0].$mimeType."\0".substr($payload, 4);
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
            if ($offset + $frameLength > $payloadLength) {
                throw new RuntimeException('The existing ID3v2 frame size is invalid.');
            }
            if ($frameSize === 0) {
                $offset += $frameLength;

                continue;
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
        if ($majorVersion === 2) {
            return match (true) {
                ($flags & 0x80) !== 0 => self::ISSUE_UNSYNCHRONIZATION,
                ($flags & 0x40) !== 0 => self::ISSUE_COMPRESSION,
                ($flags & ~0xC0) !== 0 => 'id3v2_unknown_flags',
                default => null,
            };
        }

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
        return pack(
            'C4',
            ($value >> 21) & 0x7F,
            ($value >> 14) & 0x7F,
            ($value >> 7) & 0x7F,
            $value & 0x7F,
        );
    }
}
