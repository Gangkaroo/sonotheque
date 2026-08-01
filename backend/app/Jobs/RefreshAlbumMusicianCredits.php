<?php

namespace App\Jobs;

use App\Music\Enrichment\AlbumMusicianCreditManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshAlbumMusicianCredits implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $albumId,
        public readonly int $lookupVersion,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "{$this->albumId}:{$this->lookupVersion}";
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(AlbumMusicianCreditManager $manager): void
    {
        if ($this->lookupVersion !== AlbumMusicianCreditManager::LOOKUP_VERSION) {
            return;
        }

        $manager->refresh($this->albumId);
    }
}
