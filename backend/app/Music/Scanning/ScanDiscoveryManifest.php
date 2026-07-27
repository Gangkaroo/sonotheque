<?php

namespace App\Music\Scanning;

use Generator;
use JsonException;
use RuntimeException;

final class ScanDiscoveryManifest
{
    /** @var resource|null */
    private mixed $writer = null;

    public function __construct(private readonly string $directory)
    {
    }

    public function start(int $scanRunId): void
    {
        $this->discardWriter();
        $this->ensureDirectoryExists();
        $this->writer = fopen($this->path($scanRunId), 'wb');

        if ($this->writer === false) {
            $this->writer = null;

            throw new RuntimeException('The scan discovery manifest could not be created.');
        }
    }

    public function append(DiscoveredAudioFile $file): void
    {
        if (! is_resource($this->writer)) {
            throw new RuntimeException('The scan discovery manifest is not open for writing.');
        }

        try {
            $line = json_encode([
                $file->absolutePath,
                $file->relativePath,
                $file->albumRelativePath,
                $file->artistFolder,
                $file->albumFolder,
                $file->fileSize,
                $file->modifiedAt,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'A discovered audio-file path could not be recorded.',
                previous: $exception,
            );
        }

        $record = $line."\n";
        $length = strlen($record);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($this->writer, substr($record, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('The scan discovery manifest could not be written.');
            }

            $offset += $written;
        }
    }

    public function finish(): void
    {
        if (! is_resource($this->writer)) {
            return;
        }

        $writer = $this->writer;
        $this->writer = null;
        $flushed = fflush($writer);
        $closed = fclose($writer);

        if (! $flushed || ! $closed) {
            throw new RuntimeException('The scan discovery manifest could not be finalized.');
        }
    }

    /** @return Generator<int, DiscoveredAudioFile> */
    public function files(int $scanRunId): Generator
    {
        $this->finish();
        $reader = fopen($this->path($scanRunId), 'rb');

        if ($reader === false) {
            throw new RuntimeException('The scan discovery manifest could not be opened.');
        }

        try {
            $lineNumber = 0;

            while (($line = fgets($reader)) !== false) {
                $lineNumber++;

                try {
                    $values = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException(
                        "The scan discovery manifest is invalid at line {$lineNumber}.",
                        previous: $exception,
                    );
                }

                if (! $this->validValues($values)) {
                    throw new RuntimeException(
                        "The scan discovery manifest is invalid at line {$lineNumber}.",
                    );
                }

                yield new DiscoveredAudioFile(
                    absolutePath: $values[0],
                    relativePath: $values[1],
                    albumRelativePath: $values[2],
                    artistFolder: $values[3],
                    albumFolder: $values[4],
                    fileSize: $values[5],
                    modifiedAt: $values[6],
                );
            }

            if (! feof($reader)) {
                throw new RuntimeException('The scan discovery manifest could not be read.');
            }
        } finally {
            fclose($reader);
        }
    }

    public function delete(int $scanRunId): void
    {
        $this->discardWriter();

        $path = $this->path($scanRunId);

        if (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException('The scan discovery manifest could not be removed.');
        }
    }

    private function discardWriter(): void
    {
        if (is_resource($this->writer)) {
            fclose($this->writer);
        }

        $this->writer = null;
    }

    private function ensureDirectoryExists(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('The scan discovery-manifest directory could not be created.');
        }
    }

    private function path(int $scanRunId): string
    {
        return rtrim($this->directory, '\\/').DIRECTORY_SEPARATOR."scan-{$scanRunId}.jsonl";
    }

    private function validValues(mixed $values): bool
    {
        return is_array($values)
            && count($values) === 7
            && is_string($values[0] ?? null)
            && is_string($values[1] ?? null)
            && is_string($values[2] ?? null)
            && is_string($values[3] ?? null)
            && is_string($values[4] ?? null)
            && is_int($values[5] ?? null)
            && is_int($values[6] ?? null);
    }
}
