<?php

namespace App\Music\Enrichment\Data;

final readonly class MusicianCredit
{
    /** @param list<string> $attributes */
    public function __construct(
        public string $providerReference,
        public string $name,
        public ?string $sortName,
        public ?string $disambiguation,
        public ?string $entityType,
        public ?int $trackId,
        public string $sourceEntityType,
        public string $sourceEntityReference,
        public string $relationshipType,
        public string $role,
        public ?string $creditedAs,
        public array $attributes,
        public bool $guest,
        public bool $additional,
    ) {
    }
}
