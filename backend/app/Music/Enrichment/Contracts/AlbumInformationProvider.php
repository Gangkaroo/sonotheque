<?php

namespace App\Music\Enrichment\Contracts;

use App\Music\Enrichment\Data\AlbumInformation;
use App\Music\Enrichment\Data\AlbumLookup;

interface AlbumInformationProvider extends OnlineContentProvider
{
    public function fetchAlbum(AlbumLookup $lookup): ?AlbumInformation;
}
