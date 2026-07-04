<?php

namespace App\Music\Enrichment\Contracts;

use App\Music\Enrichment\Data\LyricsContent;
use App\Music\Enrichment\Data\LyricsLookup;

interface LyricsProvider extends OnlineContentProvider
{
    public function fetchLyrics(LyricsLookup $lookup): ?LyricsContent;
}
