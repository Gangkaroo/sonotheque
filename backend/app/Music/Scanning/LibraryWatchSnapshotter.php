<?php

namespace App\Music\Scanning;

use App\Models\LibraryRoot;
use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

class LibraryWatchSnapshotter
{
    /** @var list<string> */
    private const ARTWORK_EXTENSIONS = ['gif', 'jpeg', 'jpg', 'png', 'webp'];

    /** @param list<string> $audioExtensions */
    public function __construct(
        private readonly LibraryDirectoryResolver $directoryResolver,
        private readonly array $audioExtensions,
    ) {
    }

    public function capture(LibraryRoot $libraryRoot): LibraryWatchSnapshot
    {
        $directory = $this->directoryResolver->resolve($libraryRoot, null);
        $rows = [];

        $this->walk(
            $directory->absolutePath,
            $directory->rootPath,
            $libraryRoot,
            $rows,
        );

        return new LibraryWatchSnapshot($rows);
    }

    /**
     * @param  list<array{
     *     relative_path: string,
     *     relative_path_hash: string,
     *     signature: string,
     *     artwork_signature: string
     * }>  $rows
     */
    private function walk(
        string $directory,
        string $rootPath,
        LibraryRoot $libraryRoot,
        array &$rows,
    ): void {
        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException(
                "The directory [{$this->relativePath($directory, $rootPath)}] could not be read.",
                previous: $exception,
            );
        }

        $tokens = [];
        $artworkTokens = [];

        foreach ($iterator as $entry) {
            try {
                if (! $entry instanceof SplFileInfo || $entry->isLink()) {
                    continue;
                }

                $relativePath = $this->relativePath($entry->getPathname(), $rootPath);

                if ($entry->isDir()) {
                    if ($this->directoryResolver->isExcluded($libraryRoot, $relativePath)) {
                        continue;
                    }

                    $tokens[] = 'd:'.$entry->getFilename();
                    $this->walk($entry->getPathname(), $rootPath, $libraryRoot, $rows);

                    continue;
                }

                if (! $entry->isFile()) {
                    continue;
                }

                $extension = mb_strtolower($entry->getExtension());
                $token = implode(':', [
                    $entry->getFilename(),
                    (string) $entry->getSize(),
                    (string) $entry->getMTime(),
                ]);

                if (in_array($extension, $this->audioExtensions, true)) {
                    $tokens[] = 'a:'.$token;
                } elseif (in_array($extension, self::ARTWORK_EXTENSIONS, true)) {
                    $tokens[] = 'i:'.$token;
                    $artworkTokens[] = $token;
                }
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "A filesystem entry in [{$this->relativePath($directory, $rootPath)}] could not be inspected.",
                    previous: $exception,
                );
            }
        }

        sort($tokens, SORT_STRING);
        sort($artworkTokens, SORT_STRING);
        $relativePath = $this->relativePath($directory, $rootPath);
        $rows[] = [
            'relative_path' => $relativePath,
            'relative_path_hash' => hash('sha256', $this->comparisonPath($relativePath)),
            'signature' => hash('sha256', implode("\n", $tokens)),
            'artwork_signature' => hash('sha256', implode("\n", $artworkTokens)),
        ];
    }

    private function relativePath(string $path, string $rootPath): string
    {
        $normalized = str_replace('\\', '/', $path);

        return ltrim(substr($normalized, strlen($rootPath)), '/');
    }

    private function comparisonPath(string $path): string
    {
        return PHP_OS_FAMILY === 'Windows' ? mb_strtolower($path) : $path;
    }
}
