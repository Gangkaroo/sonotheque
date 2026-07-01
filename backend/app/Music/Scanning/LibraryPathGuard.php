<?php

namespace App\Music\Scanning;

class LibraryPathGuard
{
    public function canonicalizeDirectory(string $path): string
    {
        $resolved = realpath($path);

        if ($resolved === false || ! is_dir($resolved) || ! is_readable($resolved)) {
            throw new InvalidLibraryPath("Library root [{$path}] does not exist or is not readable.");
        }

        $normalized = str_replace('\\', '/', $resolved);

        return preg_match('/^[A-Za-z]:\/$/', $normalized) === 1
            ? $normalized
            : rtrim($normalized, '/');
    }

    public function normalizeRelativePath(string $path): string
    {
        return $this->normalizeRelativePathSegments($path, allowParentSegments: false);
    }

    public function normalizeNavigableRelativePath(string $path): string
    {
        return $this->normalizeRelativePathSegments($path, allowParentSegments: true);
    }

    private function normalizeRelativePathSegments(string $path, bool $allowParentSegments): string
    {
        $normalized = str_replace('\\', '/', trim($path));

        if ($normalized === '' || str_contains($normalized, "\0")) {
            throw new InvalidLibraryPath('A relative path must not be empty or contain null bytes.');
        }

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            throw new InvalidLibraryPath("Path [{$path}] must be relative.");
        }

        $segments = explode('/', $normalized);

        if (in_array('', $segments, true)
            || in_array('.', $segments, true)
            || (! $allowParentSegments && in_array('..', $segments, true))) {
            throw new InvalidLibraryPath("Path [{$path}] contains an unsafe segment.");
        }

        return implode('/', $segments);
    }

    public function resolveExistingFileWithin(string $directory, string $relativePath): ?string
    {
        $base = $this->canonicalizeDirectory($directory);
        $relative = $this->normalizeRelativePath($relativePath);
        $candidate = (str_ends_with($base, '/') ? $base : $base.'/').$relative;

        if (! file_exists($candidate)) {
            return null;
        }

        if (is_link($candidate)) {
            throw new InvalidLibraryPath("Path [{$relativePath}] must not be a symbolic link.");
        }

        $resolved = realpath($candidate);

        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            throw new InvalidLibraryPath("File [{$relativePath}] is not readable.");
        }

        $resolved = str_replace('\\', '/', $resolved);
        if (! $this->containsDirectory($base, $resolved)) {
            throw new InvalidLibraryPath("File [{$relativePath}] escapes the library root.");
        }

        return $resolved;
    }

    public function resolveExistingFileWithinFrom(
        string $rootDirectory,
        string $baseRelativeDirectory,
        string $relativePath,
    ): ?string {
        $baseSegments = explode('/', $this->normalizeRelativePath($baseRelativeDirectory));
        $relative = $this->normalizeNavigableRelativePath($relativePath);

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '..') {
                if ($baseSegments === []) {
                    throw new InvalidLibraryPath("Path [{$relativePath}] escapes the library root.");
                }

                array_pop($baseSegments);

                continue;
            }

            $baseSegments[] = $segment;
        }

        return $this->resolveExistingFileWithin($rootDirectory, implode('/', $baseSegments));
    }

    public function containsDirectory(string $parent, string $candidate): bool
    {
        $comparisonParent = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($parent) : $parent;
        $comparisonCandidate = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($candidate) : $candidate;
        $parentPrefix = str_ends_with($comparisonParent, '/')
            ? $comparisonParent
            : $comparisonParent.'/';

        return $comparisonCandidate !== $comparisonParent
            && str_starts_with($comparisonCandidate, $parentPrefix);
    }
}
