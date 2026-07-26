<?php

namespace App\Music\Playlists;

class SynchronizedPlaylistFileCleaner
{
    public function delete(?string $rootPath, ?string $relativePath): void
    {
        if ($rootPath === null || $relativePath === null || ! is_dir($rootPath)) {
            return;
        }

        $segments = array_values(array_filter(
            preg_split('#[\\\\/]#', $relativePath) ?: [],
            fn (string $segment): bool => $segment !== '',
        ));
        if ($segments === [] || in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return;
        }

        $root = realpath($rootPath);
        if ($root === false) {
            return;
        }

        $target = $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
        if (! $this->isWithin($target, $root) || is_link($target)) {
            return;
        }
        if (is_file($target)) {
            @unlink($target);
        }

        $directory = dirname($target);
        while ($directory !== $root && $this->isWithin($directory, $root)) {
            if (! is_dir($directory) || is_link($directory) || ! @rmdir($directory)) {
                break;
            }
            $directory = dirname($directory);
        }
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = mb_strtolower($path);
            $root = mb_strtolower($root);
        }

        return $path !== $root && str_starts_with($path, $root.'/');
    }
}
