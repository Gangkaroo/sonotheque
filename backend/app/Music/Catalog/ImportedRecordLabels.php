<?php

namespace App\Music\Catalog;

final readonly class ImportedRecordLabels
{
    /** @param list<ImportedRecordLabel> $items */
    public function __construct(public array $items)
    {
    }
}
