<?php

namespace App\Music\Scanning;

use App\Enums\MediaFileStatus;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Support\CatalogPayloads;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class LibraryFolderBrowser
{
    /** @param list<string> $extensions */
    public function __construct(
        private readonly LibraryDirectoryResolver $directoryResolver,
        private readonly CatalogPayloads $payloads,
        private readonly array $extensions,
    ) {
    }

    /** @return array<string, mixed> */
    public function browse(LibraryRoot $libraryRoot, ?string $relativePath): array
    {
        $directory = $this->resolveEnabledRoot($libraryRoot, $relativePath);
        $directories = [];
        $filesByHash = [];

        try {
            $iterator = new FilesystemIterator($directory->absolutePath, FilesystemIterator::SKIP_DOTS);

            foreach ($iterator as $entry) {
                try {
                    if ($entry->isLink()) {
                        continue;
                    }

                    $entryRelativePath = $this->childPath($directory->relativePath, $entry->getFilename());

                    if ($entry->isDir()) {
                        if (! $this->directoryResolver->isExcluded($libraryRoot, $entryRelativePath)) {
                            $directories[] = [
                                'name' => $entry->getFilename(),
                                'path' => $entryRelativePath,
                            ];
                        }

                        continue;
                    }

                    if (! $entry->isFile()
                        || ! $entry->isReadable()
                        || ! in_array(mb_strtolower($entry->getExtension()), $this->extensions, true)) {
                        continue;
                    }

                    $filesByHash[$this->pathHash($entryRelativePath)] = [
                        'name' => $entry->getFilename(),
                        'path' => $entryRelativePath,
                        'indexed' => false,
                        'available' => false,
                        'track' => null,
                    ];
                } catch (Throwable) {
                    continue;
                }
            }
        } catch (Throwable $exception) {
            throw new InvalidLibraryPath(
                "Directory [{$directory->relativePath}] could not be listed.",
                previous: $exception,
            );
        }

        $this->attachCatalogTracks($libraryRoot, $filesByHash);
        usort($directories, static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
        $files = array_values($filesByHash);
        usort($files, static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

        return [
            'libraryRoot' => ['id' => $libraryRoot->id, 'name' => $libraryRoot->name],
            'path' => $directory->relativePath,
            'parentPath' => $this->parentPath($directory->relativePath),
            'breadcrumbs' => $this->breadcrumbs($libraryRoot, $directory->relativePath),
            'directories' => $directories,
            'files' => $files,
        ];
    }

    /** @return array{path: ?string, total: int, requiresConfirmation: bool, tracks: mixed} */
    public function tracks(
        LibraryRoot $libraryRoot,
        ?string $relativePath,
        ?int $confirmationThreshold = null,
    ): array {
        $directory = $this->resolveEnabledRoot($libraryRoot, $relativePath);
        $query = MediaFile::query()
            ->where('library_root_id', $libraryRoot->id)
            ->where('status', MediaFileStatus::Available->value)
            ->whereHas('track')
            ->orderBy('relative_path');
        $this->scopeToDirectory($query, $directory->relativePath);

        if ($confirmationThreshold !== null) {
            $total = (clone $query)->count();

            if ($total >= $confirmationThreshold) {
                return [
                    'path' => $directory->relativePath,
                    'total' => $total,
                    'requiresConfirmation' => true,
                    'tracks' => [],
                ];
            }
        }

        $tracks = $query->with([
                'track.album:id,title,original_release_year,artwork_id',
                'track.album.personalMetadata',
                'track.artists:id,name',
                'track.playStatistic:track_id,play_count,first_played_at,last_played_at',
            ])
            ->get()
            ->map(fn (MediaFile $mediaFile): array => $this->payloads->trackSummary($mediaFile->track))
            ->values();

        return [
            'path' => $directory->relativePath,
            'total' => $tracks->count(),
            'requiresConfirmation' => false,
            'tracks' => $tracks,
        ];
    }

    private function resolveEnabledRoot(LibraryRoot $libraryRoot, ?string $relativePath): ResolvedLibraryDirectory
    {
        if (! $libraryRoot->enabled) {
            throw new InvalidLibraryPath('The requested library root is disabled.');
        }

        return $this->directoryResolver->resolve($libraryRoot, $relativePath);
    }

    /** @param array<string, array<string, mixed>> $filesByHash */
    private function attachCatalogTracks(LibraryRoot $libraryRoot, array &$filesByHash): void
    {
        if ($filesByHash === []) {
            return;
        }

        $mediaFiles = MediaFile::query()
            ->where('library_root_id', $libraryRoot->id)
            ->whereIn('relative_path_hash', array_keys($filesByHash))
            ->with([
                'track.album:id,title,original_release_year,artwork_id',
                'track.album.personalMetadata',
                'track.artists:id,name',
                'track.playStatistic:track_id,play_count,first_played_at,last_played_at',
            ])
            ->get();

        foreach ($mediaFiles as $mediaFile) {
            $entry = &$filesByHash[$mediaFile->relative_path_hash];
            $entry['indexed'] = $mediaFile->track !== null;
            $entry['available'] = $mediaFile->status === MediaFileStatus::Available;
            $entry['track'] = $mediaFile->track === null
                ? null
                : $this->payloads->trackSummary($mediaFile->track);
            unset($entry);
        }
    }

    /** @param Builder<MediaFile> $query */
    private function scopeToDirectory(Builder $query, ?string $relativePath): void
    {
        if ($relativePath === null) {
            return;
        }

        $comparisonPath = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($relativePath) : $relativePath;
        $comparisonColumn = PHP_OS_FAMILY === 'Windows' ? 'LOWER(relative_path)' : 'relative_path';

        $query->where(function (Builder $query) use ($comparisonColumn, $comparisonPath): void {
            $query
                ->whereRaw("{$comparisonColumn} = ?", [$comparisonPath])
                ->orWhereRaw("starts_with({$comparisonColumn}, ?)", [$comparisonPath.'/']);
        });
    }

    /** @return list<array{name: string, path: ?string}> */
    private function breadcrumbs(LibraryRoot $libraryRoot, ?string $relativePath): array
    {
        $breadcrumbs = [['name' => $libraryRoot->name, 'path' => null]];

        if ($relativePath === null) {
            return $breadcrumbs;
        }

        $path = [];
        foreach (explode('/', $relativePath) as $segment) {
            $path[] = $segment;
            $breadcrumbs[] = ['name' => $segment, 'path' => implode('/', $path)];
        }

        return $breadcrumbs;
    }

    private function childPath(?string $relativePath, string $name): string
    {
        return $relativePath === null ? $name : $relativePath.'/'.$name;
    }

    private function parentPath(?string $relativePath): ?string
    {
        if ($relativePath === null || ! str_contains($relativePath, '/')) {
            return null;
        }

        return substr($relativePath, 0, (int) strrpos($relativePath, '/'));
    }

    private function pathHash(string $relativePath): string
    {
        return hash('sha256', mb_strtolower(str_replace('\\', '/', $relativePath)));
    }
}
