<?php

namespace App\Music\Scanning;

use App\Models\LibraryRoot;

class LibraryRootPathValidator
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
    ) {}

    public function assertAvailable(string $path): void
    {
        $pathHash = hash('sha256', mb_strtolower($path));

        if (LibraryRoot::where('path_hash', $pathHash)->exists()) {
            throw new InvalidLibraryPath('This folder is already configured as a library root.');
        }

        foreach (LibraryRoot::query()->get(['name', 'path']) as $root) {
            if ($this->pathGuard->containsDirectory($root->path, $path)) {
                throw new InvalidLibraryPath(
                    "This folder is inside the existing library root [{$root->name}].",
                );
            }

            if ($this->pathGuard->containsDirectory($path, $root->path)) {
                throw new InvalidLibraryPath(
                    "This folder contains the existing library root [{$root->name}].",
                );
            }
        }
    }
}
