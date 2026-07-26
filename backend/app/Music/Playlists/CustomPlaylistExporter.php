<?php

namespace App\Music\Playlists;

use App\Enums\MediaFileStatus;
use App\Models\ApplicationSetting;
use App\Models\Playlist;
use App\Models\PlaylistExportLocation;
use App\Models\PlaylistItem;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use App\Support\LibraryRootScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CustomPlaylistExporter
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly LibraryRootScope $libraryRootScope,
        private readonly PlaylistFileWriter $fileWriter,
    ) {
    }

    /** @return array<string, mixed> */
    public function configuration(Playlist $playlist, ?int $libraryRootId): array
    {
        $defaultFormat = ApplicationSetting::current()->playlist_export_format ?: 'm3u8';
        $locations = PlaylistExportLocation::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return [
            'defaultFormat' => $defaultFormat,
            'defaultFilename' => $this->fileWriter->defaultFilename($playlist->name, $defaultFormat),
            'formats' => PlaylistFileWriter::FORMATS,
            'defaultLocationId' => $locations->firstWhere('is_default', true)?->id,
            'locations' => $locations->map(fn (PlaylistExportLocation $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'path' => $location->path,
                'isDefault' => $location->is_default,
            ])->values(),
            'trackCount' => $this->items($playlist, $libraryRootId)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function export(
        Playlist $playlist,
        PlaylistExportLocation $location,
        string $format,
        string $filename,
        bool $overwrite,
        ?int $libraryRootId,
    ): array {
        try {
            $directory = $this->pathGuard->canonicalizeDirectory($location->path);
        } catch (InvalidLibraryPath $exception) {
            throw new PlaylistExportException(
                'The configured playlist export folder could not be opened: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $result = $this->exportToDirectory(
            $playlist,
            $directory,
            $format,
            $filename,
            $overwrite,
            $libraryRootId,
        );

        return [
            ...$result,
            'location' => [
                'id' => $location->id,
                'name' => $location->name,
                'path' => $directory,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function exportToDirectory(
        Playlist $playlist,
        string $directory,
        string $format,
        string $filename,
        bool $overwrite,
        ?int $libraryRootId,
        bool $allowEmpty = false,
    ): array {
        $items = $this->items($playlist, $libraryRootId);
        if (! $allowEmpty && $items->isEmpty()) {
            throw new PlaylistExportException('The playlist does not contain any available tracks.');
        }

        $paths = $items
            ->map(fn (PlaylistItem $item): string => $this->pathForItem($item, $directory))
            ->all();
        $writeResult = $this->fileWriter->write(
            $directory,
            $format,
            $filename,
            $paths,
            $overwrite,
        );

        return [
            ...$writeResult,
            'trackCount' => $items->count(),
            'destinationPath' => $this->join($directory, $writeResult['filename']),
        ];
    }

    /** @return Collection<int, PlaylistItem> */
    private function items(Playlist $playlist, ?int $libraryRootId): Collection
    {
        return $playlist->items()
            ->whereHas(
                'track',
                fn (Builder $tracks) => $this->libraryRootScope
                    ->tracks($tracks, $libraryRootId)
                    ->whereHas(
                        'mediaFile',
                        fn (Builder $mediaFiles) => $mediaFiles
                            ->where('status', MediaFileStatus::Available),
                    ),
            )
            ->with(['track.mediaFile.libraryRoot:id,name,path,enabled'])
            ->get();
    }

    private function pathForItem(PlaylistItem $item, string $destinationDirectory): string
    {
        $track = $item->track;
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

    private function join(string $directory, string $filename): string
    {
        return rtrim(str_replace('\\', '/', $directory), '/').'/'.$filename;
    }
}
