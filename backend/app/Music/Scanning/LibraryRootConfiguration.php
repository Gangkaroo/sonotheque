<?php

namespace App\Music\Scanning;

class LibraryRootConfiguration
{
    public function __construct(private readonly LibraryPathGuard $pathGuard)
    {
    }

    /** @param list<string>|null $paths
     * @return list<string>
     */
    public function coverPaths(?array $paths): array
    {
        $paths ??= ['cover.jpg'];

        return $this->normalizeMany(
            $paths,
            'At least one cover image path is required.',
            fn (string $path): string => $this->pathGuard->normalizeNavigableRelativePath($path),
        );
    }

    /** @param list<string>|null $directories
     * @return list<string>
     */
    public function excludedDirectories(?array $directories): array
    {
        if ($directories === null || $directories === []) {
            return [];
        }

        return $this->normalizeMany(
            $directories,
            'Excluded folder paths must not be empty.',
            fn (string $path): string => $this->pathGuard->normalizeRelativePath($path),
        );
    }

    /** @param list<string> $paths
     * @return list<string>
     */
    private function normalizeMany(array $paths, string $emptyMessage, callable $normalize): array
    {
        $normalized = collect($paths)
            ->map($normalize)
            ->unique(fn (string $path): string => mb_strtolower($path))
            ->values()
            ->all();

        if ($normalized === []) {
            throw new InvalidLibraryPath($emptyMessage);
        }

        return $normalized;
    }
}
