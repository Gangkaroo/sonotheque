<?php

namespace App\Music\Enrichment\Contracts;

use App\Music\Enrichment\Data\ArtistInformation;
use App\Music\Enrichment\Data\ArtistLookup;

interface ArtistInformationProvider extends OnlineContentProvider
{
    public function fetchArtist(ArtistLookup $lookup): ?ArtistInformation;
}
