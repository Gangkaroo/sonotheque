<?php

namespace App\Music\Scanning;

use App\Enums\ScanStatus;
use App\Models\Album;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\Track;
use App\Music\Playlists\PlaylistFileSynchronizationDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class LibraryEntryRenamer
{
    /** @var list<string> */
    private const WINDOWS_RESERVED_NAMES = [
        'aux',
        'clock$',
        'con',
        'nul',
        'prn',
        'com1',
        'com2',
        'com3',
        'com4',
        'com5',
        'com6',
        'com7',
        'com8',
        'com9',
        'lpt1',
        'lpt2',
        'lpt3',
        'lpt4',
        'lpt5',
        'lpt6',
        'lpt7',
        'lpt8',
        'lpt9',
    ];

    /** @param list<string> $extensions */
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly LibraryDirectoryResolver $directoryResolver,
        private readonly PlaylistFileSynchronizationDispatcher $playlistSynchronizationDispatcher,
        private readonly array $extensions,
    ) {
    }

    /** @return array{kind: string, oldPath: string, newPath: string, affectedFiles: int, affectedTracks: int} */
    public function rename(LibraryRoot $libraryRoot, string $relativePath, string $newName): array
    {
        if (! $libraryRoot->enabled) {
            throw new LibraryEntryRenameException('The requested library root is disabled.');
        }

        $relativePath = $this->pathGuard->normalizeRelativePath($relativePath);
        $newName = $this->validName($newName);

        if ($this->directoryResolver->isExcluded($libraryRoot, $relativePath)) {
            throw new LibraryEntryRenameException('The requested entry is excluded from this library root.');
        }

        $parentPath = $this->parentPath($relativePath);
        $sourceName = basename(str_replace('\\', '/', $relativePath));
        if ($sourceName === $newName) {
            throw new LibraryEntryRenameException('The new name is identical to the current name.');
        }

        $parent = $this->directoryResolver->resolve($libraryRoot, $parentPath);
        $sourcePath = $parent->absolutePath.'/'.$sourceName;
        if (is_link($sourcePath)) {
            throw new LibraryEntryRenameException('Symbolic links cannot be renamed.');
        }

        $kind = is_dir($sourcePath) ? 'directory' : (is_file($sourcePath) ? 'file' : null);
        if ($kind === null) {
            throw new LibraryEntryRenameException("Entry [{$relativePath}] does not exist.", 404);
        }

        if ($kind === 'file') {
            $this->validateAudioFileRename($sourceName, $newName);
        }

        $newPath = $parentPath === null ? $newName : $parentPath.'/'.$newName;
        if ($this->directoryResolver->isExcluded($libraryRoot, $newPath)) {
            throw new LibraryEntryRenameException('The new name is reserved or excluded from this library root.');
        }

        $targetPath = $parent->absolutePath.'/'.$newName;
        if (file_exists($targetPath) && ! $this->isSameEntry($sourcePath, $targetPath)) {
            throw new LibraryEntryRenameException("An entry named [{$newName}] already exists.", 409);
        }

        if (! is_writable($parent->absolutePath)) {
            throw new LibraryEntryRenameException('The parent folder is not writable.');
        }

        $activeScan = $libraryRoot->scanRuns()
            ->whereIn('status', [ScanStatus::Pending->value, ScanStatus::Running->value])
            ->exists();
        if ($activeScan) {
            throw new LibraryEntryRenameException('Entries cannot be renamed while this library root is being scanned.', 409);
        }

        $mediaFiles = $this->matchingPaths(MediaFile::query(), $libraryRoot->id, $relativePath, $kind === 'directory')
            ->get(['id', 'relative_path']);
        $albums = $kind === 'directory'
            ? $this->matchingPaths(Album::query(), $libraryRoot->id, $relativePath, true)
                ->get(['id', 'relative_path'])
            : collect();

        $mediaUpdates = $this->pathUpdates($mediaFiles, $relativePath, $newPath);
        $albumUpdates = $this->pathUpdates($albums, $relativePath, $newPath);
        $this->assertCatalogPathsAvailable(MediaFile::query(), $libraryRoot->id, $mediaUpdates, $mediaFiles->pluck('id'));
        $this->assertCatalogPathsAvailable(Album::query(), $libraryRoot->id, $albumUpdates, $albums->pluck('id'));

        if (! @rename($sourcePath, $targetPath)) {
            throw new LibraryEntryRenameException('The entry could not be renamed. It may be in use or the folder may be read-only.');
        }

        try {
            DB::transaction(function () use ($mediaUpdates, $albumUpdates): void {
                $this->applyPathUpdates(MediaFile::class, $mediaUpdates);
                $this->applyPathUpdates(Album::class, $albumUpdates);
            });
        } catch (Throwable $exception) {
            $restored = @rename($targetPath, $sourcePath);
            $message = $restored
                ? 'The catalog could not be updated, so the filesystem rename was reverted.'
                : 'The catalog update failed and the filesystem rename could not be reverted. Run a library rescan before continuing.';

            throw new LibraryEntryRenameException($message, 500, $exception);
        }

        $affectedTracks = MediaFile::query()
            ->whereIn('id', $mediaFiles->pluck('id'))
            ->whereHas('track')
            ->count();
        $this->playlistSynchronizationDispatcher->tracks(
            Track::query()
                ->whereIn('media_file_id', $mediaFiles->pluck('id'))
                ->pluck('id'),
        );

        return [
            'kind' => $kind,
            'oldPath' => $relativePath,
            'newPath' => $newPath,
            'affectedFiles' => $mediaFiles->count(),
            'affectedTracks' => $affectedTracks,
        ];
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || in_array($name, ['.', '..'], true)) {
            throw new LibraryEntryRenameException('A new file or folder name is required.');
        }

        if (mb_strlen($name) > 255) {
            throw new LibraryEntryRenameException('The new name must not exceed 255 characters.');
        }

        if (preg_match('/[<>:"\/\\\\|?*\x00-\x1F]/u', $name) === 1 || preg_match('/[. ]$/u', $name) === 1) {
            throw new LibraryEntryRenameException('The new name contains characters that are unsafe for a library path.');
        }

        $baseName = mb_strtolower(explode('.', $name, 2)[0]);
        if (in_array($baseName, self::WINDOWS_RESERVED_NAMES, true)) {
            throw new LibraryEntryRenameException('The new name is reserved by the operating system.');
        }

        return $name;
    }

    private function validateAudioFileRename(string $sourceName, string $newName): void
    {
        $sourceExtension = mb_strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
        $newExtension = mb_strtolower(pathinfo($newName, PATHINFO_EXTENSION));

        if (! in_array($sourceExtension, $this->extensions, true)) {
            throw new LibraryEntryRenameException('Only supported audio files can be renamed from the library browser.');
        }

        if ($newExtension !== $sourceExtension) {
            throw new LibraryEntryRenameException('Renaming an audio file must not change its file extension.');
        }
    }

    private function parentPath(string $relativePath): ?string
    {
        $position = strrpos($relativePath, '/');

        return $position === false ? null : substr($relativePath, 0, $position);
    }

    private function isSameEntry(string $sourcePath, string $targetPath): bool
    {
        $source = realpath($sourcePath);
        $target = realpath($targetPath);
        if ($source === false || $target === false) {
            return false;
        }

        return PHP_OS_FAMILY === 'Windows'
            ? mb_strtolower(str_replace('\\', '/', $source)) === mb_strtolower(str_replace('\\', '/', $target))
            : $source === $target;
    }

    /** @param Builder<MediaFile>|Builder<Album> $query */
    private function matchingPaths(Builder $query, int $libraryRootId, string $path, bool $recursive): Builder
    {
        $comparisonColumn = PHP_OS_FAMILY === 'Windows' ? 'LOWER(relative_path)' : 'relative_path';
        $comparisonPath = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($path) : $path;

        return $query
            ->where('library_root_id', $libraryRootId)
            ->where(function (Builder $query) use ($comparisonColumn, $comparisonPath, $recursive): void {
                $query->whereRaw("{$comparisonColumn} = ?", [$comparisonPath]);
                if ($recursive) {
                    $query->orWhereRaw("starts_with({$comparisonColumn}, ?)", [$comparisonPath.'/']);
                }
            });
    }

    /**
     * @param  Collection<int, MediaFile>|Collection<int, Album>  $models
     * @return list<array{id: int, relative_path: string, relative_path_hash: string, updated_at: mixed}>
     */
    private function pathUpdates(Collection $models, string $oldPath, string $newPath): array
    {
        $updatedAt = now();

        return $models->map(function (MediaFile|Album $model) use ($oldPath, $newPath, $updatedAt): array {
            $suffix = substr($model->relative_path, strlen($oldPath));
            $relativePath = $newPath.$suffix;

            return [
                'id' => $model->id,
                'relative_path' => $relativePath,
                'relative_path_hash' => $this->pathHash($relativePath),
                'updated_at' => $updatedAt,
            ];
        })->all();
    }

    /**
     * @param  Builder<MediaFile>|Builder<Album>  $query
     * @param  list<array{id: int, relative_path: string, relative_path_hash: string, updated_at: mixed}>  $updates
     * @param  Collection<int, int>  $sourceIds
     */
    private function assertCatalogPathsAvailable(Builder $query, int $libraryRootId, array $updates, Collection $sourceIds): void
    {
        if ($updates === []) {
            return;
        }

        $collision = $query
            ->where('library_root_id', $libraryRootId)
            ->whereIn('relative_path_hash', array_column($updates, 'relative_path_hash'))
            ->whereNotIn('id', $sourceIds)
            ->exists();

        if ($collision) {
            throw new LibraryEntryRenameException('The catalog already contains an entry at the destination path. Run a rescan before renaming.', 409);
        }
    }

    /** @param list<array{id: int, relative_path: string, relative_path_hash: string, updated_at: mixed}> $updates */
    private function applyPathUpdates(string $model, array $updates): void
    {
        $table = (new $model())->getTable();

        foreach (array_chunk($updates, 500) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
            $bindings = collect($chunk)
                ->flatMap(fn (array $update): array => [
                    $update['id'],
                    $update['relative_path'],
                    $update['relative_path_hash'],
                    $update['updated_at'],
                ])
                ->all();

            DB::update(
                "UPDATE {$table} AS target "
                .'SET relative_path = source.relative_path, '
                .'relative_path_hash = source.relative_path_hash, '
                .'updated_at = source.updated_at::timestamptz '
                ."FROM (VALUES {$values}) AS source(id, relative_path, relative_path_hash, updated_at) "
                .'WHERE target.id = source.id::bigint',
                $bindings,
            );
        }
    }

    private function pathHash(string $relativePath): string
    {
        return hash('sha256', mb_strtolower(str_replace('\\', '/', $relativePath)));
    }
}
