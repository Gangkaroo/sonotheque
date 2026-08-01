<?php

namespace App\Music\Enrichment;

class AmbiguousMusicBrainzReleaseException extends AmbiguousEnrichmentMatchException
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public function __construct(
        string $message,
        public readonly array $candidates,
    ) {
        parent::__construct($message);
    }
}
