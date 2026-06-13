<?php

namespace App\Music\Scanning;

use App\Models\LibraryRoot;
use Generator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class AudioFileDiscoverer
{
    /** @param list<string> $extensions */
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly array $extensions,
    ) {}

    /** @return Generator<int, DiscoveredAudioFile> */
    public function discover(LibraryRoot $libraryRoot): Generator
    {
        $rootPath = $this->pathGuard->canonicalizeDirectory($libraryRoot->path);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink() || ! $file->isReadable()) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            if (! in_array($extension, $this->extensions, true)) {
                continue;
            }

            $absolutePath = str_replace('\\', '/', $file->getPathname());
            $relativePath = ltrim(substr($absolutePath, strlen($rootPath)), '/');
            $segments = explode('/', $relativePath);

            if (count($segments) < 3 || ! $this->matchesPatterns($relativePath, $libraryRoot)) {
                continue;
            }

            yield new DiscoveredAudioFile(
                absolutePath: $absolutePath,
                relativePath: $relativePath,
                albumRelativePath: $segments[0].'/'.$segments[1],
                artistFolder: $segments[0],
                albumFolder: $segments[1],
                fileSize: $file->getSize(),
                modifiedAt: $file->getMTime(),
            );
        }
    }

    private function matchesPatterns(string $relativePath, LibraryRoot $libraryRoot): bool
    {
        $included = empty($libraryRoot->include_patterns)
            || collect($libraryRoot->include_patterns)->contains(fn (string $pattern): bool => Str::is($pattern, $relativePath));

        $excluded = collect($libraryRoot->exclude_patterns ?? [])
            ->contains(fn (string $pattern): bool => Str::is($pattern, $relativePath));

        return $included && ! $excluded;
    }
}
