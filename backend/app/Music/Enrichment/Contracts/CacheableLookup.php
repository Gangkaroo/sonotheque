<?php

namespace App\Music\Enrichment\Contracts;

interface CacheableLookup
{
    /** @return array<string, mixed> */
    public function cachePayload(): array;
}
