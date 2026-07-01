<?php

namespace App\Music\Scanning;

use App\Models\LibraryRoot;
use FilesystemIterator;
use Generator;
use Illuminate\Support\Str;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

class AudioFileDiscoverer
{
    /** @param list<string> $extensions */
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly array $extensions,
    ) {}

    /** @return Generator<int, DiscoveredAudioFile> */
    public function discover(LibraryRoot $libraryRoot, ?DiscoveryDiagnostics $diagnostics = null): Generator
    {
        $diagnostics ??= new DiscoveryDiagnostics;
        $rootPath = $this->pathGuard->canonicalizeDirectory($libraryRoot->path);

        yield from $this->walk($rootPath, $rootPath, $libraryRoot, $diagnostics);
    }

    /** @return Generator<int, DiscoveredAudioFile> */
    private function walk(
        string $directory,
        string $rootPath,
        LibraryRoot $libraryRoot,
        DiscoveryDiagnostics $diagnostics,
    ): Generator {
        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            $diagnostics->record(
                'unreadable_directory',
                'A directory could not be read and was skipped.',
                $this->relativePath($directory, $rootPath),
            );

            return;
        }

        foreach ($iterator as $file) {
            $relativePath = null;

            try {
                if (! $file instanceof SplFileInfo) {
                    continue;
                }

                $relativePath = $this->relativePath($file->getPathname(), $rootPath);

                if ($file->isLink()) {
                    if ($file->isDir() || in_array(strtolower($file->getExtension()), $this->extensions, true)) {
                        $diagnostics->record('symlink_skipped', 'A symbolic link was skipped for safety.', $relativePath);
                    }

                    continue;
                }

                if ($file->isDir()) {
                    if ($this->isExcludedDirectory($relativePath, $libraryRoot)) {
                        continue;
                    }

                    yield from $this->walk($file->getPathname(), $rootPath, $libraryRoot, $diagnostics);

                    continue;
                }

                if (! $file->isFile()) {
                    continue;
                }

                $extension = strtolower($file->getExtension());

                if (! in_array($extension, $this->extensions, true)) {
                    continue;
                }

                if (! $file->isReadable()) {
                    $diagnostics->record('unreadable_file', 'A supported audio file could not be read.', $relativePath);

                    continue;
                }

                $absolutePath = str_replace('\\', '/', $file->getPathname());
                $segments = explode('/', $relativePath);

                if (count($segments) < 3) {
                    $diagnostics->record(
                        'invalid_layout',
                        'A supported audio file was outside the expected Artist/Album folder layout.',
                        $relativePath,
                    );

                    continue;
                }

                if (! $this->matchesPatterns($relativePath, $libraryRoot)) {
                    continue;
                }

                $fileName = array_pop($segments);
                $albumFolder = array_pop($segments);
                $artistFolder = array_pop($segments);

                if ($fileName === null || $albumFolder === null || $artistFolder === null) {
                    $diagnostics->record(
                        'invalid_layout',
                        'A supported audio file was outside the expected Artist/Album folder layout.',
                        $relativePath,
                    );

                    continue;
                }

                $albumRelativePath = implode('/', [...$segments, $artistFolder, $albumFolder]);

                yield new DiscoveredAudioFile(
                    absolutePath: $absolutePath,
                    relativePath: $relativePath,
                    albumRelativePath: $albumRelativePath,
                    artistFolder: $artistFolder,
                    albumFolder: $albumFolder,
                    fileSize: $file->getSize(),
                    modifiedAt: $file->getMTime(),
                );
            } catch (Throwable) {
                $diagnostics->record(
                    'unreadable_entry',
                    'A filesystem entry could not be inspected and was skipped.',
                    $relativePath,
                );
            }
        }
    }

    private function relativePath(string $path, string $rootPath): string
    {
        $normalized = str_replace('\\', '/', $path);

        return ltrim(substr($normalized, strlen($rootPath)), '/');
    }

    private function matchesPatterns(string $relativePath, LibraryRoot $libraryRoot): bool
    {
        $includePatterns = $libraryRoot->include_patterns ?? [];
        $included = $includePatterns === [] || $this->matchesAnyPattern($relativePath, $includePatterns);
        $excluded = $this->matchesAnyPattern($relativePath, $libraryRoot->exclude_patterns ?? []);

        return $included && ! $excluded;
    }

    /** @param list<string> $patterns */
    private function matchesAnyPattern(string $relativePath, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    private function isExcludedDirectory(string $relativePath, LibraryRoot $libraryRoot): bool
    {
        $comparisonPath = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($relativePath) : $relativePath;

        foreach ($libraryRoot->excluded_directories ?? [] as $excluded) {
            $excluded = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($excluded) : $excluded;

            if ($comparisonPath === $excluded || str_starts_with($comparisonPath, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }
}
