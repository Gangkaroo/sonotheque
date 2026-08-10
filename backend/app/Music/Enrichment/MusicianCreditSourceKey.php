<?php

namespace App\Music\Enrichment;

final class MusicianCreditSourceKey
{
    public static function make(
        string $provider,
        string $musicianProvider,
        string $musicianReference,
        string $sourceEntityType,
        string $sourceEntityReference,
        string $relationshipType,
        string $role,
        ?string $creditedAs,
        bool $guest,
        bool $additional,
    ): string {
        return hash('sha256', implode('|', [
            $provider,
            $musicianProvider,
            $musicianReference,
            $sourceEntityType,
            $sourceEntityReference,
            $relationshipType,
            $role,
            $creditedAs ?? '',
            $guest ? 'guest' : '',
            $additional ? 'additional' : '',
        ]));
    }
}
