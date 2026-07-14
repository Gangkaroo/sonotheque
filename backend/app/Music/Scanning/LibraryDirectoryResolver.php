<?php

namespace App\Music\Scanning;

use App\Models\LibraryRoot;

class LibraryDirectoryResolver
{
    /** @var list<string> */
    private const SYSTEM_DIRECTORIES = [
        '$getcurrent',
        '$recycle.bin',
        '$sysreset',
        '$windows.~bt',
        '$windows.~ws',
        '$winreagent',
        '.documentrevisions-v100',
        '.fseventsd',
        '.spotlight-v100',
        '.temporaryitems',
        '.trashes',
        'config.msi',
        'deliveryoptimization',
        'lost+found',
        'msocache',
        'onedrivetemp',
        'recycled',
        'recycler',
        'system volume information',
        'windowsapps',
        'wpsystem',
        'wudownloadcache',
    ];

    public function __construct(private readonly LibraryPathGuard $pathGuard)
    {
    }

    public function resolve(LibraryRoot $libraryRoot, ?string $relativePath): ResolvedLibraryDirectory
    {
        $rootPath = $this->pathGuard->canonicalizeDirectory($libraryRoot->path);
        $relativePath = $this->pathGuard->normalizeRelativeDirectoryPath($relativePath);

        if ($relativePath !== null && $this->isExcluded($libraryRoot, $relativePath)) {
            throw new InvalidLibraryPath("Directory [{$relativePath}] is excluded from this library root.");
        }

        return new ResolvedLibraryDirectory(
            rootPath: $rootPath,
            absolutePath: $this->pathGuard->resolveExistingDirectoryWithin($rootPath, $relativePath),
            relativePath: $relativePath,
        );
    }

    public function isExcluded(LibraryRoot $libraryRoot, string $relativePath): bool
    {
        if ($this->isSystemDirectory($relativePath)) {
            return true;
        }

        $comparisonPath = $this->comparable($relativePath);

        foreach ($libraryRoot->excluded_directories ?? [] as $excluded) {
            $comparisonExcluded = $this->comparable($excluded);

            if ($comparisonPath === $comparisonExcluded
                || str_starts_with($comparisonPath, $comparisonExcluded.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isSystemDirectory(string $relativePath): bool
    {
        $normalizedPath = str_replace('\\', '/', $relativePath);
        $rootDirectory = mb_strtolower(explode('/', $normalizedPath, 2)[0]);

        return in_array($rootDirectory, self::SYSTEM_DIRECTORIES, true)
            || preg_match('/^found\.\d{3}$/', $rootDirectory) === 1;
    }

    private function comparable(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? mb_strtolower($path) : $path;
    }
}
