<?php

namespace App\Music\Playlists;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Track;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Support\Collection;

class AlbumPlaylistExporter
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly PlaylistFileWriter $fileWriter,
    ) {
    }

    /** @return array<string, mixed> */
    public function configuration(Album $album): array
    {
        $album->loadMissing(['libraryRoot:id,name,path', 'primaryArtist:id,name']);

        $defaultFormat = ApplicationSetting::current()->playlist_export_format ?: 'm3u8';

        return [
            'defaultFormat' => $defaultFormat,
            'defaultFilename' => $this->defaultFilename($album, $defaultFormat),
            'formats' => PlaylistFileWriter::FORMATS,
            'directory' => [
                'libraryRoot' => $album->libraryRoot?->name,
                'relativePath' => $album->relative_path,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function export(
        Album $album,
        string $format,
        string $filename,
        bool $overwrite,
    ): array {
        $album->loadMissing(['libraryRoot:id,name,path', 'primaryArtist:id,name']);
        $root = $album->libraryRoot;
        if ($root === null) {
            throw new PlaylistExportException('The album is not associated with a library root.');
        }

        try {
            $directory = $this->pathGuard->resolveExistingDirectoryWithin(
                $root->path,
                $album->relative_path,
            );
        } catch (InvalidLibraryPath $exception) {
            throw new PlaylistExportException(
                'The album folder could not be opened: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $tracks = $this->tracks($album);
        $writeResult = $this->fileWriter->write(
            $directory,
            $format,
            $filename,
            $this->paths($album, $tracks),
            $overwrite,
        );

        return [
            ...$writeResult,
            'trackCount' => $tracks->count(),
            'directory' => [
                'libraryRoot' => $root->name,
                'relativePath' => $album->relative_path,
            ],
            'relativePath' => $album->relative_path.'/'.$writeResult['filename'],
        ];
    }

    public function defaultFilename(Album $album, string $format): string
    {
        $artist = $album->primaryArtist?->name ?: 'Unknown Artist';
        return $this->fileWriter->defaultFilename($artist.' - '.$album->title, $format);
    }

    /** @return Collection<int, Track> */
    private function tracks(Album $album): Collection
    {
        $tracks = $album->tracks()
            ->with([
                'mediaFile:id,library_root_id,relative_path,status',
            ])
            ->orderBy('disc_number')
            ->orderBy('track_number')
            ->orderBy('id')
            ->get();
        if ($tracks->isEmpty()) {
            throw new PlaylistExportException('The album does not contain any tracks.');
        }

        foreach ($tracks as $track) {
            $mediaFile = $track->mediaFile;
            if ($mediaFile === null
                || $mediaFile->library_root_id !== $album->library_root_id
                || $mediaFile->status !== MediaFileStatus::Available) {
                throw new PlaylistExportException(
                    "The file for track [{$track->title}] is not available in the album's library root.",
                );
            }
        }

        return $tracks;
    }

    /** @param Collection<int, Track> $tracks */
    /** @return list<string> */
    private function paths(Album $album, Collection $tracks): array
    {
        $lines = [];

        foreach ($tracks as $track) {
            $mediaFile = $track->mediaFile;
            if ($mediaFile === null) {
                continue;
            }

            $lines[] = $this->relativePath(
                $album->relative_path,
                $mediaFile->relative_path,
            );
        }

        return $lines;
    }

    private function relativePath(string $directory, string $file): string
    {
        $directorySegments = explode('/', $this->pathGuard->normalizeRelativePath($directory));
        $fileSegments = explode('/', $this->pathGuard->normalizeRelativePath($file));
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

    private function segmentsMatch(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? mb_strtolower($left) === mb_strtolower($right)
            : $left === $right;
    }

}
