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
        $normalized = str_replace('\\', '/', trim($path));

        if ($normalized === '' || str_contains($normalized, "\0")) {
            throw new InvalidLibraryPath('A relative path must not be empty or contain null bytes.');
        }

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            throw new InvalidLibraryPath("Path [{$path}] must be relative.");
        }

        $segments = explode('/', $normalized);

        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidLibraryPath("Path [{$path}] contains an unsafe segment.");
        }

        return implode('/', $segments);
    }

    public function resolveExistingFileWithin(string $directory, string $relativePath): ?string
    {
        $base = $this->canonicalizeDirectory($directory);
        $relative = $this->normalizeRelativePath($relativePath);
        $candidate = $base.'/'.$relative;

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
        $comparisonBase = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($base) : $base;
        $comparisonResolved = PHP_OS_FAMILY === 'Windows' ? mb_strtolower($resolved) : $resolved;

        if (! str_starts_with($comparisonResolved, $comparisonBase.'/')) {
            throw new InvalidLibraryPath("File [{$relativePath}] escapes its album directory.");
        }

        return $resolved;
    }
}
