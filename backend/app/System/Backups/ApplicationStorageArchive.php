<?php

namespace App\System\Backups;

use Illuminate\Support\Facades\File;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ApplicationStorageArchive
{
    public function create(string $source, string $archivePath): void
    {
        @unlink($archivePath);
        if (! is_dir($source)) {
            throw new RuntimeException('Sonotheque application storage does not exist.');
        }

        $entries = collect(File::directories($source))
            ->map(fn (string $path): string => basename($path))
            ->merge(collect(File::files($source))->map(
                fn ($file): string => $file->getFilename(),
            ))
            ->reject(fn (string $name): bool => $name === 'system-backups')
            ->map(fn (string $name): string => './'.$name)
            ->values()
            ->all();
        if ($entries === []) {
            new PharData($archivePath);

            return;
        }

        $tar = (new ExecutableFinder())->find('tar');
        if ($tar === null) {
            throw new RuntimeException('The tar command required for application backups was not found.');
        }
        $process = new Process(array_merge([
            $tar,
            '-cf',
            $archivePath,
            '-C',
            $source,
        ], $entries));
        $process->setTimeout(null);
        $process->run();
        if (! $process->isSuccessful()) {
            @unlink($archivePath);
            $detail = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException(
                $detail === ''
                    ? 'Sonotheque application storage could not be archived.'
                    : 'Sonotheque application storage could not be archived. '.$detail,
            );
        }
    }

    public function restore(string $archivePath, string $destination): void
    {
        File::ensureDirectoryExists($destination);
        $archive = new PharData($archivePath);
        $prefix = 'phar://'.str_replace('\\', '/', realpath($archivePath) ?: $archivePath).'/';
        foreach (new RecursiveIteratorIterator($archive) as $key => $entry) {
            $normalized = str_replace('\\', '/', (string) $key);
            $relative = str_starts_with($normalized, $prefix)
                ? substr($normalized, strlen($prefix))
                : '';
            if ($relative === ''
                || str_starts_with($relative, '/')
                || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1
                || $entry->isLink()) {
                throw new RuntimeException('The storage backup contains an unsafe path.');
            }
        }

        foreach (File::directories($destination) as $directory) {
            if (basename($directory) !== 'system-backups') {
                File::deleteDirectory($directory);
            }
        }
        foreach (File::files($destination) as $file) {
            File::delete($file->getPathname());
        }
        if (! $archive->extractTo($destination, null, true)) {
            throw new RuntimeException('Sonotheque application storage could not be restored.');
        }
    }
}
