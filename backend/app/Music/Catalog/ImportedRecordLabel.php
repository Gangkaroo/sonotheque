<?php

namespace App\Music\Catalog;

final readonly class ImportedRecordLabel
{
    public function __construct(
        public string $name,
        public ?string $catalogNumber = null,
    ) {
    }
}
