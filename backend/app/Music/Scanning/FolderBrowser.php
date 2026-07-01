<?php

namespace App\Music\Scanning;

use FilesystemIterator;
use Throwable;

class FolderBrowser
{
    public function __construct(private readonly LibraryPathGuard $pathGuard) {}

    /**
     * @return array{
     *     path: ?string,
     *     parent: ?string,
     *     directories: list<array{name: string, path: string}>,
     *     volumes: list<array{name: string, path: string}>
     * }
     */
    public function browse(?string $path): array
    {
        if ($path === null || trim($path) === '') {
            return [
                'path' => null,
                'parent' => null,
                'directories' => [],
                'volumes' => $this->volumes(),
            ];
        }

        $directory = $this->pathGuard->canonicalizeDirectory($path);
        $directories = [];

        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);

            foreach ($iterator as $entry) {
                try {
                    if (! $entry->isDir() || $entry->isLink()) {
                        continue;
                    }

                    $directories[] = [
                        'name' => $entry->getFilename(),
                        'path' => $this->normalize($entry->getPathname()),
                    ];
                } catch (Throwable) {
                    continue;
                }
            }
        } catch (Throwable $exception) {
            throw new InvalidLibraryPath("Directory [{$directory}] could not be listed.", previous: $exception);
        }

        usort(
            $directories,
            static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']),
        );

        return [
            'path' => $directory,
            'parent' => $this->parent($directory),
            'directories' => $directories,
            'volumes' => [],
        ];
    }

    /** @return list<array{name: string, path: string}> */
    private function volumes(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [['name' => '/', 'path' => '/']];
        }

        $volumes = [];

        foreach (range('A', 'Z') as $letter) {
            $path = $letter.':/';

            if (is_dir($path) && is_readable($path)) {
                $volumes[] = ['name' => $letter.':', 'path' => $path];
            }
        }

        return $volumes;
    }

    private function parent(string $path): ?string
    {
        if ($path === '/' || preg_match('/^[A-Za-z]:\/$/', $path) === 1) {
            return null;
        }

        $parent = $this->normalize(dirname($path));

        return $parent === $path ? null : $parent;
    }

    private function normalize(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return preg_match('/^[A-Za-z]:\/$/', $normalized) === 1
            ? $normalized
            : rtrim($normalized, '/');
    }
}
