<?php

namespace App\Music\Playlists;

use App\Models\Playlist;
use App\Models\PlaylistExportLocation;
use App\Models\PlaylistFolder;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Support\Collection;

class PlaylistSynchronizationPath
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly PlaylistFileWriter $fileWriter,
        private readonly PlaylistFilesystemName $filesystemName,
    ) {
    }

    /** @return array{directory: string, filename: string, relativePath: string, rootPath: string} */
    public function prepare(
        Playlist $playlist,
        PlaylistExportLocation $location,
        string $format,
    ): array {
        try {
            $rootPath = $this->pathGuard->canonicalizeDirectory($location->path);
        } catch (InvalidLibraryPath $exception) {
            throw new PlaylistExportException(
                'The default playlist folder could not be opened: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $components = $this->folderComponents($playlist);
        $directory = $this->ensureDirectories($rootPath, $components);
        $filename = $this->playlistFilename($playlist, $format);
        $relativePath = implode('/', [...$components, $filename]);

        return compact('directory', 'filename', 'relativePath', 'rootPath');
    }

    /** @return list<string> */
    private function folderComponents(Playlist $playlist): array
    {
        $components = [];
        $folder = $playlist->folder()->first();
        $visited = [];

        while ($folder !== null) {
            if (isset($visited[$folder->id])) {
                throw new PlaylistExportException('The playlist folder hierarchy contains a cycle.');
            }

            $visited[$folder->id] = true;
            array_unshift($components, $this->folderComponent($folder));
            $folder = $folder->parent()->first();
        }

        return $components;
    }

    private function folderComponent(PlaylistFolder $folder): string
    {
        $component = $this->filesystemName->component($folder->name, 'Folder');
        $siblings = PlaylistFolder::query()
            ->whereKeyNot($folder->id)
            ->when(
                $folder->parent_id === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $folder->parent_id),
            )
            ->pluck('name');

        if ($this->hasCollision($component, $siblings)) {
            $component .= ' ['.$folder->id.']';
        }

        return $component;
    }

    private function playlistFilename(Playlist $playlist, string $format): string
    {
        $filename = $this->fileWriter->defaultFilename($playlist->name, $format);
        $siblings = Playlist::query()
            ->whereKeyNot($playlist->id)
            ->when(
                $playlist->playlist_folder_id === null,
                fn ($query) => $query->whereNull('playlist_folder_id'),
                fn ($query) => $query->where('playlist_folder_id', $playlist->playlist_folder_id),
            )
            ->get(['name'])
            ->map(fn (Playlist $sibling): string => $this->fileWriter->defaultFilename(
                $sibling->name,
                $format,
            ));

        if ($this->hasCollision($filename, $siblings, alreadySafe: true)) {
            $extension = '.'.$this->fileWriter->format($format);
            $filename = mb_substr($filename, 0, -mb_strlen($extension))
                .' ['.$playlist->id.']'.$extension;
        }

        return $filename;
    }

    /** @param Collection<int, string> $values */
    private function hasCollision(
        string $value,
        Collection $values,
        bool $alreadySafe = false,
    ): bool {
        $normalized = mb_strtolower($value);

        return $values->contains(function (string $candidate) use ($normalized, $alreadySafe): bool {
            $candidate = $alreadySafe
                ? $candidate
                : $this->filesystemName->component($candidate, 'Folder');

            return mb_strtolower($candidate) === $normalized;
        });
    }

    /** @param list<string> $components */
    private function ensureDirectories(string $rootPath, array $components): string
    {
        $directory = $rootPath;
        foreach ($components as $component) {
            $directory .= DIRECTORY_SEPARATOR.$component;
            if (is_link($directory)) {
                throw new PlaylistExportException(
                    'A synchronized playlist folder must not be a symbolic link.',
                );
            }
            if (file_exists($directory) && ! is_dir($directory)) {
                throw new PlaylistExportException(
                    'A synchronized playlist folder path is occupied by a file.',
                );
            }
            if (! is_dir($directory) && ! @mkdir($directory, 0777)) {
                throw new PlaylistExportException(
                    'A synchronized playlist folder could not be created.',
                );
            }
        }

        return $directory;
    }
}
