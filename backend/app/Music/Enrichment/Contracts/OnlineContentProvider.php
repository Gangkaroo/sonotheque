<?php

namespace App\Music\Enrichment\Contracts;

interface OnlineContentProvider
{
    public function key(): string;
}
