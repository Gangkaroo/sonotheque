<?php

namespace App\Music\Playlists;

use App\Enums\MediaFileStatus;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistFolder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlaylistImporter
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    private const MAX_ENTRIES = 20_000;

    /**
     * @return array{
     *     playlist: Playlist,
     *     totalEntries: int,
     *     importedCount: int,
     *     unresolvedCount: int,
     *     warnings: list<array{line: int, path: string, code: string, message: string}>
     * }
     */
    public function import(
        string $path,
        string $name,
        ?PlaylistFolder $folder,
    ): array {
        $playlistPath = $this->playlistPath($path);
        $entries = $this->entries($playlistPath);
        $roots = $this->roots();
        $resolved = [];
        $warnings = [];

        foreach ($entries as $entry) {
            $location = $this->catalogLocation($entry['path'], dirname($playlistPath), $roots);
            if ($location === null) {
                $warnings[] = $this->warning(
                    $entry,
                    'outside_or_missing',
                    'The file is outside the configured library roots or no longer exists.',
                );
                $resolved[] = null;

                continue;
            }

            $resolved[] = $location;
        }

        $tracks = $this->tracksByLocation(array_values(array_filter($resolved)));
        $trackIds = [];

        foreach ($resolved as $index => $location) {
            if ($location === null) {
                continue;
            }

            $trackId = $tracks[$this->locationKey($location)] ?? null;
            if ($trackId === null) {
                $warnings[] = $this->warning(
                    $entries[$index],
                    'not_in_collection',
                    'The file was not found as an available track in the collection.',
                );

                continue;
            }

            $trackIds[] = $trackId;
        }

        $playlist = DB::transaction(function () use ($name, $folder, $trackIds): Playlist {
            $playlist = Playlist::create([
                'name' => $name,
                'playlist_folder_id' => $folder?->id,
            ]);

            foreach (array_chunk($trackIds, 500, true) as $chunk) {
                $timestamp = now();
                $rows = [];

                foreach ($chunk as $position => $trackId) {
                    $rows[] = [
                        'playlist_id' => $playlist->id,
                        'track_id' => $trackId,
                        'position' => $position,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                $playlist->items()->getModel()->newQuery()->insert($rows);
            }

            return $playlist->load('folder:id,name')->loadCount('items');
        });

        return [
            'playlist' => $playlist,
            'totalEntries' => count($entries),
            'importedCount' => count($trackIds),
            'unresolvedCount' => count($warnings),
            'warnings' => $warnings,
        ];
    }

    private function playlistPath(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new PlaylistImportException('The playlist path contains an invalid null byte.');
        }

        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved) || is_link($path)) {
            throw new PlaylistImportException('The selected playlist file does not exist or is not readable.');
        }

        $extension = mb_strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        if (! in_array($extension, ['m3u', 'm3u8'], true)) {
            throw new PlaylistImportException('The selected file must be an M3U or M3U8 playlist.');
        }

        $size = filesize($resolved);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new PlaylistImportException('The selected playlist exceeds the 10 MB file-size limit.');
        }

        return $this->normalize($resolved);
    }

    /**
     * @return list<array{line: int, path: string}>
     */
    private function entries(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new PlaylistImportException('The selected playlist could not be read.');
        }

        $content = $this->decode($content);
        $lines = preg_split('/\R/u', $content);
        if ($lines === false) {
            throw new PlaylistImportException('The selected playlist contains invalid text.');
        }

        $entries = [];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (mb_strlen($line) > 4096) {
                throw new PlaylistImportException('A playlist entry exceeds the 4096 character limit.');
            }

            $entries[] = [
                'line' => $index + 1,
                'path' => $this->unquote($line),
            ];

            if (count($entries) > self::MAX_ENTRIES) {
                throw new PlaylistImportException('The selected playlist contains more than 20,000 entries.');
            }
        }

        if ($entries === []) {
            throw new PlaylistImportException('The selected playlist does not contain any file entries.');
        }

        return $entries;
    }

    private function decode(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }
        if (str_starts_with($content, "\xFF\xFE")) {
            return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($content, "\xFE\xFF")) {
            return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        return mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /** @return Collection<int, array{id: int, path: string}> */
    private function roots(): Collection
    {
        return LibraryRoot::query()
            ->where('enabled', true)
            ->orderByDesc(DB::raw('char_length(path)'))
            ->get(['id', 'path'])
            ->map(function (LibraryRoot $root): ?array {
                $path = realpath($root->path);

                return $path === false ? null : [
                    'id' => $root->id,
                    'path' => $this->normalize($path),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param Collection<int, array{id: int, path: string}> $roots
     * @return array{rootId: int, relativePath: string, pathHash: string}|null
     */
    private function catalogLocation(string $entry, string $playlistDirectory, Collection $roots): ?array
    {
        $entry = $this->localPath($entry);
        if ($entry === null) {
            return null;
        }
        $entry = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $entry);

        $candidate = $this->isAbsolute($entry)
            ? $entry
            : $playlistDirectory.DIRECTORY_SEPARATOR.$entry;
        $resolved = realpath($candidate);
        if ($resolved === false || ! is_file($resolved)) {
            return null;
        }

        $absolutePath = $this->normalize($resolved);

        foreach ($roots as $root) {
            if (! $this->isWithin($root['path'], $absolutePath)) {
                continue;
            }

            $relativePath = ltrim(substr($absolutePath, strlen($root['path'])), '/');

            return [
                'rootId' => $root['id'],
                'relativePath' => $relativePath,
                'pathHash' => $this->pathHash($relativePath),
            ];
        }

        return null;
    }

    private function localPath(string $path): ?string
    {
        if (! preg_match('#^file://#i', $path)) {
            return preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 ? null : $path;
        }

        $parts = parse_url($path);
        if ($parts === false || ! isset($parts['path'])) {
            return null;
        }

        $host = $parts['host'] ?? '';
        $decodedPath = rawurldecode($parts['path']);
        if ($host !== '' && mb_strtolower($host) !== 'localhost') {
            return '//'.$host.'/'.ltrim($decodedPath, '/');
        }

        return preg_match('#^/[A-Za-z]:/#', $decodedPath) === 1
            ? substr($decodedPath, 1)
            : $decodedPath;
    }

    /**
     * @param list<array{rootId: int, relativePath: string, pathHash: string}> $locations
     * @return array<string, int>
     */
    private function tracksByLocation(array $locations): array
    {
        $locationsByRoot = collect($locations)->groupBy('rootId');
        $tracks = [];

        foreach ($locationsByRoot as $rootId => $rootLocations) {
            MediaFile::query()
                ->where('library_root_id', $rootId)
                ->where('status', MediaFileStatus::Available->value)
                ->whereIn('relative_path_hash', $rootLocations->pluck('pathHash')->unique())
                ->whereHas('track')
                ->with('track:id,media_file_id')
                ->get(['id', 'library_root_id', 'relative_path', 'relative_path_hash'])
                ->each(function (MediaFile $mediaFile) use (&$tracks): void {
                    if ($mediaFile->track === null) {
                        return;
                    }

                    $tracks[$this->locationKey([
                        'rootId' => $mediaFile->library_root_id,
                        'relativePath' => $mediaFile->relative_path,
                        'pathHash' => $mediaFile->relative_path_hash,
                    ])] = $mediaFile->track->id;
                });
        }

        return $tracks;
    }

    /** @param array{rootId: int, relativePath: string, pathHash: string} $location */
    private function locationKey(array $location): string
    {
        return $location['rootId'].':'.$location['pathHash'];
    }

    /** @param array{line: int, path: string} $entry */
    private function warning(array $entry, string $code, string $message): array
    {
        return [
            'line' => $entry['line'],
            'path' => $entry['path'],
            'code' => $code,
            'message' => $message,
        ];
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function isWithin(string $root, string $candidate): bool
    {
        $root = $this->comparable($root);
        $candidate = $this->comparable($candidate);

        return str_starts_with($candidate, rtrim($root, '/').'/');
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function comparable(string $path): string
    {
        $path = $this->normalize($path);

        return PHP_OS_FAMILY === 'Windows' ? mb_strtolower($path) : $path;
    }

    private function pathHash(string $relativePath): string
    {
        return hash('sha256', mb_strtolower(str_replace('\\', '/', $relativePath)));
    }

    private function unquote(string $path): string
    {
        if (mb_strlen($path) >= 2
            && (($path[0] === '"' && str_ends_with($path, '"'))
                || ($path[0] === "'" && str_ends_with($path, "'")))) {
            return substr($path, 1, -1);
        }

        return $path;
    }
}
