<?php

namespace App\Music\Ratings;

final readonly class ImportedRatingTags
{
    /** @param list<string> $warnings */
    public function __construct(
        public ?int $trackHalfSteps,
        public bool $trackTagPresent,
        public ?int $albumHalfSteps,
        public bool $albumTagPresent,
        public array $warnings = [],
    ) {
    }
}
