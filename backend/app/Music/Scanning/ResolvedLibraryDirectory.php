<?php

namespace App\Music\Scanning;

final readonly class ResolvedLibraryDirectory
{
    public function __construct(
        public string $rootPath,
        public string $absolutePath,
        public ?string $relativePath,
    ) {
    }
}
