<?php

namespace App\Http\Controllers;

use App\Music\Discogs\DiscogsImageCache;
use Illuminate\Http\Response;

class DiscogsImageController extends Controller
{
    public function __invoke(string $hash, DiscogsImageCache $images): Response
    {
        return $images->response($hash) ?? abort(404);
    }
}
