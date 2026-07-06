<?php

namespace App\Music\Enrichment\Contracts;

use App\Music\Enrichment\Data\ArtistImageInformation;
use App\Music\Enrichment\Data\ArtistLookup;

interface ArtistImageProvider extends OnlineContentProvider
{
    public function fetchArtistImage(ArtistLookup $lookup): ?ArtistImageInformation;
}
