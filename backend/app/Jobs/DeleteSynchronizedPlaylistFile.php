<?php

namespace App\Jobs;

use App\Music\Playlists\SynchronizedPlaylistFileCleaner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteSynchronizedPlaylistFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $rootPath,
        public readonly string $relativePath,
    ) {
    }

    public function handle(SynchronizedPlaylistFileCleaner $cleaner): void
    {
        $cleaner->delete($this->rootPath, $this->relativePath);
    }
}
