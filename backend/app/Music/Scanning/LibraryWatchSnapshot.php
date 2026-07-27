<?php

namespace App\Music\Scanning;

class LibraryWatchSnapshot
{
    /**
     * @param  list<array{
     *     relative_path: string,
     *     relative_path_hash: string,
     *     signature: string,
     *     artwork_signature: string
     * }>  $directories
     */
    public function __construct(public readonly array $directories)
    {
    }
}
