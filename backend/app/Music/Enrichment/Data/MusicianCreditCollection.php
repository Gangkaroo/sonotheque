<?php

namespace App\Music\Enrichment\Data;

final readonly class MusicianCreditCollection
{
    /**
     * @param  list<MusicianCredit>  $credits
     * @param  list<int>  $discogsReleaseIds
     */
    public function __construct(
        public string $releaseId,
        public string $sourceUrl,
        public array $credits,
        public array $discogsReleaseIds,
    ) {
    }
}
