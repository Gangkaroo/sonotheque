<?php

namespace App\Music\Playlists;

use App\Enums\MediaFileStatus;
use App\Models\Track;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;

class PlaylistTrackPathResolver
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
    ) {
    }

    public function pathForTrack(Track $track, string $destinationDirectory): string
    {
        $mediaFile = $track->mediaFile;
        $root = $mediaFile?->libraryRoot;
        if ($mediaFile === null
            || $root === null
            || ! $root->enabled
            || $mediaFile->status !== MediaFileStatus::Available) {
            throw new PlaylistExportException(
                "The file for track [{$track->title}] is not available.",
            );
        }

        try {
            $absolutePath = $this->pathGuard->resolveExistingFileWithin(
                $root->path,
                $mediaFile->relative_path,
            );
        } catch (InvalidLibraryPath $exception) {
            throw new PlaylistExportException(
                "The file for track [{$track->title}] could not be opened: {$exception->getMessage()}",
                previous: $exception,
            );
        }
        if ($absolutePath === null) {
            throw new PlaylistExportException(
                "The file for track [{$track->title}] no longer exists.",
            );
        }

        return $this->relativeOrAbsolutePath($destinationDirectory, $absolutePath);
    }

    private function relativeOrAbsolutePath(string $directory, string $file): string
    {
        $directory = str_replace('\\', '/', $directory);
        $file = str_replace('\\', '/', $file);
        $directoryVolume = $this->volume($directory);
        $fileVolume = $this->volume($file);
        if ($directoryVolume === null
            || $fileVolume === null
            || ! $this->segmentsMatch($directoryVolume, $fileVolume)) {
            return $file;
        }

        $directorySegments = $this->segmentsAfterVolume($directory, $directoryVolume);
        $fileSegments = $this->segmentsAfterVolume($file, $fileVolume);
        $common = 0;
        $maximumCommon = min(count($directorySegments), count($fileSegments));
        while ($common < $maximumCommon
            && $this->segmentsMatch($directorySegments[$common], $fileSegments[$common])) {
            $common++;
        }

        return implode('/', [
            ...array_fill(0, count($directorySegments) - $common, '..'),
            ...array_slice($fileSegments, $common),
        ]);
    }

    private function volume(string $path): ?string
    {
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return substr($path, 0, 2);
        }
        if (preg_match('#^//[^/]+/[^/]+#', $path, $matches) === 1) {
            return $matches[0];
        }

        return str_starts_with($path, '/') ? '/' : null;
    }

    /** @return list<string> */
    private function segmentsAfterVolume(string $path, string $volume): array
    {
        return array_values(array_filter(
            explode('/', trim(substr($path, strlen($volume)), '/')),
            fn (string $segment): bool => $segment !== '',
        ));
    }

    private function segmentsMatch(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? mb_strtolower($left) === mb_strtolower($right)
            : $left === $right;
    }
}
