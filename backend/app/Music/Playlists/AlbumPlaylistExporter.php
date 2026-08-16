<?php

namespace App\Music\Playlists;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\PlaylistExportLocation;
use App\Models\Track;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Support\Collection;

class AlbumPlaylistExporter
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly PlaylistFileWriter $fileWriter,
        private readonly PlaylistTrackPathResolver $trackPathResolver,
    ) {
    }

    /** @return array<string, mixed> */
    public function configuration(Album $album): array
    {
        $album->loadMissing(['libraryRoot:id,name,path', 'primaryArtist:id,name']);

        $defaultFormat = ApplicationSetting::current()->playlist_export_format ?: 'm3u8';
        $locations = PlaylistExportLocation::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return [
            'defaultFormat' => $defaultFormat,
            'defaultFilename' => $this->defaultFilename($album, $defaultFormat),
            'formats' => PlaylistFileWriter::FORMATS,
            'directory' => [
                'libraryRoot' => $album->libraryRoot?->name,
                'relativePath' => $album->relative_path,
            ],
            'locations' => $locations->map(fn (PlaylistExportLocation $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'path' => $location->path,
                'isDefault' => $location->is_default,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function export(
        Album $album,
        string $format,
        string $filename,
        bool $overwrite,
        ?PlaylistExportLocation $location = null,
    ): array {
        $album->loadMissing(['libraryRoot:id,name,path', 'primaryArtist:id,name']);
        $root = $album->libraryRoot;
        if ($root === null) {
            throw new PlaylistExportException('The album is not associated with a library root.');
        }

        $directory = $this->destinationDirectory($album, $location);

        $tracks = $this->tracks($album);
        $writeResult = $this->fileWriter->write(
            $directory,
            $format,
            $filename,
            $this->paths($tracks, $directory),
            $overwrite,
        );

        return [
            ...$writeResult,
            'trackCount' => $tracks->count(),
            'directory' => [
                'type' => $location === null ? 'album' : 'configured',
                'libraryRoot' => $location === null ? $root->name : null,
                'relativePath' => $location === null ? $album->relative_path : null,
                'locationId' => $location?->id,
                'name' => $location?->name,
            ],
            'relativePath' => $location === null
                ? $album->relative_path.'/'.$writeResult['filename']
                : null,
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
                'mediaFile.libraryRoot:id,name,path,enabled',
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

    private function destinationDirectory(
        Album $album,
        ?PlaylistExportLocation $location,
    ): string {
        try {
            if ($location !== null) {
                return $this->pathGuard->canonicalizeDirectory($location->path);
            }

            return $this->pathGuard->resolveExistingDirectoryWithin(
                $album->libraryRoot->path,
                $album->relative_path,
            );
        } catch (InvalidLibraryPath $exception) {
            $message = $location === null
                ? 'The album folder could not be opened: '
                : 'The configured playlist export folder could not be opened: ';

            throw new PlaylistExportException(
                $message.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /** @param Collection<int, Track> $tracks */
    /** @return list<string> */
    private function paths(Collection $tracks, string $directory): array
    {
        return $tracks
            ->map(fn (Track $track): string => $this->trackPathResolver
                ->pathForTrack($track, $directory))
            ->all();
    }
}
