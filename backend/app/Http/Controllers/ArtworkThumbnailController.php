<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtworkThumbnailController extends Controller
{
    public function __invoke(Artwork $artwork): StreamedResponse
    {
        $storage = Storage::disk(config('music-library.artwork.disk'));

        abort_unless($storage->exists($artwork->thumbnail_path), 404);

        return $storage->response($artwork->thumbnail_path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'Content-Type' => 'image/webp',
        ]);
    }

    public function original(Artwork $artwork): StreamedResponse
    {
        $storage = Storage::disk(config('music-library.artwork.disk'));

        abort_unless($storage->exists($artwork->cache_path), 404);

        return $storage->response($artwork->cache_path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'Content-Type' => $artwork->mime_type,
        ]);
    }
}
