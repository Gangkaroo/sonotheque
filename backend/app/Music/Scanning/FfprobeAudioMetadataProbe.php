<?php

namespace App\Music\Scanning;

use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class FfprobeAudioMetadataProbe implements AudioMetadataProbe
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function probe(string $absolutePath): ProbedAudioMetadata
    {
        $result = Process::timeout($this->timeoutSeconds)->run([
            $this->binary,
            '-v',
            'error',
            '-show_entries',
            'stream=codec_type,codec_name,sample_rate,channels,bit_rate,duration:format=format_name,duration,bit_rate:format_tags',
            '-of',
            'json',
            $absolutePath,
        ]);

        if (! $result->successful()) {
            $message = trim($result->errorOutput()) ?: 'FFprobe could not inspect the audio file.';

            throw new RuntimeException(mb_substr($message, 0, 1000));
        }

        try {
            $payload = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('FFprobe returned malformed JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('FFprobe did not return an object.');
        }

        $stream = collect(is_array($payload['streams'] ?? null) ? $payload['streams'] : [])
            ->first(fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['codec_type'] ?? null) === 'audio');

        if (! is_array($stream)) {
            throw new RuntimeException('FFprobe did not find an audio stream.');
        }

        $format = is_array($payload['format'] ?? null) ? $payload['format'] : [];

        return new ProbedAudioMetadata(
            tags: $this->tags($format['tags'] ?? null),
            durationMs: $this->durationMilliseconds($stream['duration'] ?? $format['duration'] ?? null),
            container: $this->text($format['format_name'] ?? null),
            codec: $this->text($stream['codec_name'] ?? null),
            bitrate: $this->integer($stream['bit_rate'] ?? $format['bit_rate'] ?? null),
            sampleRate: $this->integer($stream['sample_rate'] ?? null),
            channels: $this->integer($stream['channels'] ?? null, 32767),
            rawMetadata: $payload,
        );
    }

    /** @return array<string, string> */
    private function tags(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $key => $tagValue) {
            $text = $this->text($tagValue);
            if ($text !== null) {
                $tags[mb_strtolower((string) $key)] = $text;
            }
        }

        return $tags;
    }

    private function durationMilliseconds(mixed $value): ?int
    {
        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (int) round((float) $value * 1000);
    }

    private function integer(mixed $value, int $maximum = PHP_INT_MAX): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number >= 0 && $number <= $maximum ? $number : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim(str_replace("\0", '', (string) $value));

        return $text !== '' ? mb_convert_encoding($text, 'UTF-8', 'UTF-8') : null;
    }
}
