<?php

namespace App\Music\Metadata;

use App\Models\ApplicationSetting;
use App\Models\LibraryRoot;
use App\Models\MediaFile;
use App\Models\MetadataBackup;
use App\Models\MetadataEditItem;
use App\Models\MetadataEditJob;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

class MetadataBackupManager
{
    public function __construct(private readonly LibraryPathGuard $pathGuard) {}

    public function prepareRoot(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidMetadataBackupPath('The metadata backup path must not be empty or contain null bytes.');
        }

        if (! is_dir($path)) {
            $parent = realpath(dirname($path));
            if ($parent === false || ! is_dir($parent) || ! is_writable($parent) || ! mkdir($path, 0775, true)) {
                throw new InvalidMetadataBackupPath("Metadata backup directory [{$path}] could not be created.");
            }
        }

        try {
            $root = $this->pathGuard->canonicalizeDirectory($path);
        } catch (InvalidLibraryPath $exception) {
            throw new InvalidMetadataBackupPath($exception->getMessage(), previous: $exception);
        }

        if (! is_writable($root)) {
            throw new InvalidMetadataBackupPath("Metadata backup directory [{$root}] is not writable.");
        }

        foreach (LibraryRoot::query()->pluck('path') as $libraryPath) {
            try {
                $libraryRoot = $this->pathGuard->canonicalizeDirectory($libraryPath);
            } catch (InvalidLibraryPath) {
                continue;
            }

            if ($this->samePath($root, $libraryRoot) || $this->pathGuard->containsDirectory($libraryRoot, $root)) {
                throw new InvalidMetadataBackupPath('The metadata backup directory must be outside every music library root.');
            }
        }

        return $root;
    }

    public function create(
        MetadataEditJob $job,
        MediaFile $mediaFile,
        string $sourcePath,
        ?MetadataEditItem $item = null,
    ): ?MetadataBackup {
        $settings = ApplicationSetting::current();
        if (! $settings->metadata_backups_enabled) {
            return null;
        }

        $existing = MetadataBackup::query()
            ->where('metadata_edit_job_id', $job->id)
            ->when(
                $item,
                fn ($query) => $query->where('metadata_edit_item_id', $item->id),
                fn ($query) => $query->whereNull('metadata_edit_item_id'),
            )
            ->first();
        if ($existing !== null) {
            $existingPath = $existing->deleted_at === null
                ? $this->pathGuard->resolveExistingFileWithin($existing->backup_root, $existing->backup_relative_path)
                : null;
            if ($existingPath === null
                || ! hash_equals($existing->checksum, (string) hash_file('sha256', $existingPath))) {
                throw new RuntimeException('The existing metadata backup for this edit is unavailable or invalid.');
            }

            return $existing;
        }

        $this->cleanupExpired();
        $root = $this->prepareRoot((string) $settings->metadata_backup_path);
        $relativeSource = $this->pathGuard->normalizeRelativePath($mediaFile->relative_path);
        $timestamp = CarbonImmutable::now('UTC')->format('Ymd\THisu\Z');
        $itemPart = $item ? "-item-{$item->id}" : '';
        $backupRelative = "library-{$mediaFile->library_root_id}/{$timestamp}-job-{$job->id}{$itemPart}-".
            bin2hex(random_bytes(4))."/{$relativeSource}";
        $destination = $this->destinationWithin($root, $backupRelative);

        try {
            $this->copyExclusive($sourcePath, $destination);
            $sourceChecksum = hash_file('sha256', $sourcePath);
            $checksum = hash_file('sha256', $destination);
            $fileSize = filesize($destination);
            if ($sourceChecksum === false || $checksum === false || $fileSize === false
                || ! hash_equals($sourceChecksum, $checksum)) {
                throw new RuntimeException('The metadata backup could not be verified.');
            }

            return MetadataBackup::create([
                'metadata_edit_job_id' => $job->id,
                'metadata_edit_item_id' => $item?->id,
                'media_file_id' => $mediaFile->id,
                'library_root_id' => $mediaFile->library_root_id,
                'source_relative_path' => $relativeSource,
                'backup_root' => $root,
                'backup_relative_path' => $backupRelative,
                'checksum' => $checksum,
                'file_size' => $fileSize,
                'expires_at' => CarbonImmutable::now('UTC')->addDays($settings->metadata_backup_retention_days),
            ]);
        } catch (Throwable $exception) {
            @unlink($destination);

            throw $exception;
        }
    }

    public function cleanupExpired(): int
    {
        $deleted = 0;
        MetadataBackup::query()
            ->whereNull('deleted_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($backups) use (&$deleted): void {
                foreach ($backups as $backup) {
                    try {
                        $path = $this->pathGuard->resolveExistingFileWithin(
                            $backup->backup_root,
                            $backup->backup_relative_path,
                        );
                        if ($path !== null && ! unlink($path)) {
                            continue;
                        }
                        $backup->update(['deleted_at' => now()]);
                        $deleted++;
                    } catch (Throwable) {
                        continue;
                    }
                }
            });

        return $deleted;
    }

    public function restore(MetadataBackup $backup): string
    {
        if ($backup->deleted_at !== null) {
            throw new RuntimeException('This metadata backup has already been removed by retention cleanup.');
        }

        $backupPath = $this->pathGuard->resolveExistingFileWithin(
            $backup->backup_root,
            $backup->backup_relative_path,
        );
        if ($backupPath === null || ! hash_equals($backup->checksum, (string) hash_file('sha256', $backupPath))) {
            throw new RuntimeException('The metadata backup is missing or its checksum is invalid.');
        }

        $backup->loadMissing('libraryRoot');
        if ($backup->libraryRoot === null) {
            throw new RuntimeException('The original library root no longer exists.');
        }
        $target = $this->pathGuard->resolveExistingFileWithin(
            $backup->libraryRoot->path,
            $backup->source_relative_path,
        );
        if ($target === null || ! is_writable($target)) {
            throw new RuntimeException('The original audio file no longer exists or is not writable.');
        }

        $suffix = '.metadata-restore-'.bin2hex(random_bytes(6));
        $temporary = $target.$suffix.'.tmp';
        $rollback = $target.$suffix.'.bak';
        try {
            $this->copyExclusive($backupPath, $temporary);
            if (! hash_equals($backup->checksum, (string) hash_file('sha256', $temporary))) {
                throw new RuntimeException('The temporary restored file did not match the backup checksum.');
            }
            $permissions = fileperms($target);
            if ($permissions !== false) {
                @chmod($temporary, $permissions & 0777);
            }

            if (! rename($target, $rollback)) {
                throw new RuntimeException('The current audio file could not be moved aside for restore.');
            }
            if (! rename($temporary, $target)) {
                @rename($rollback, $target);
                throw new RuntimeException('The restored audio file could not replace the current file.');
            }
            @unlink($rollback);
            $backup->update(['restored_at' => now()]);

            return $target;
        } finally {
            @unlink($temporary);
            if (is_file($rollback) && ! is_file($target)) {
                @rename($rollback, $target);
            }
        }
    }

    public function absolutePath(MetadataBackup $backup): string
    {
        return rtrim(str_replace('\\', '/', $backup->backup_root), '/').'/'.$backup->backup_relative_path;
    }

    private function destinationWithin(string $root, string $relativePath): string
    {
        $relative = $this->pathGuard->normalizeRelativePath($relativePath);
        $destination = rtrim($root, '/').'/'.$relative;
        $directory = dirname($destination);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true)) {
            throw new RuntimeException('The metadata backup directory tree could not be created.');
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false
            || ! $this->pathGuard->containsDirectory($root, str_replace('\\', '/', $resolvedDirectory))) {
            throw new RuntimeException('The metadata backup destination escaped its configured directory.');
        }

        return $destination;
    }

    private function copyExclusive(string $sourcePath, string $destination): void
    {
        $source = fopen($sourcePath, 'rb');
        $target = fopen($destination, 'xb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            throw new RuntimeException('The metadata backup file could not be created.');
        }

        try {
            if (! flock($source, LOCK_SH) || stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException('The audio file could not be copied to the metadata backup.');
            }
            fflush($target);
            if (function_exists('fsync')) {
                fsync($target);
            }
        } finally {
            flock($source, LOCK_UN);
            fclose($source);
            fclose($target);
        }
    }

    private function samePath(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? mb_strtolower($left) === mb_strtolower($right)
            : $left === $right;
    }
}
