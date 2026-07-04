<?php

namespace App\Jobs;

use App\Enums\OnlineContentType;
use App\Music\Enrichment\OnlineEnrichmentManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshOnlineEnrichment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 120;

    /** @param array<string, mixed> $lookup */
    public function __construct(
        public readonly string $provider,
        public readonly string $resourceType,
        public readonly array $lookup,
        public readonly string $lookupHash,
    ) {
    }

    public function uniqueId(): string
    {
        return "{$this->provider}:{$this->resourceType}:{$this->lookupHash}";
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(OnlineEnrichmentManager $manager): void
    {
        $manager->refreshLookup(
            $this->provider,
            OnlineContentType::from($this->resourceType),
            $this->lookup,
        );
    }
}
