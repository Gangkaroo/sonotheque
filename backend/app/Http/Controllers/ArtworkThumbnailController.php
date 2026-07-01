<?php

namespace App\Http\Controllers;

use App\Enums\ArtworkSource;
use App\Models\Album;
use App\Models\Artwork;
use App\Music\Scanning\AudioMetadataReader;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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

    public function albumOriginal(
        Album $album,
        LibraryPathGuard $pathGuard,
        AudioMetadataReader $metadataReader,
    ): Response {
        $album->loadMissing(['artwork', 'libraryRoot', 'mediaFiles']);
        $artwork = $album->artwork;
        abort_if($artwork === null || ! $album->libraryRoot?->enabled, 404);

        if ($album->artwork_source_type === ArtworkSource::Folder
            && $album->artwork_source_relative_path !== null) {
            try {
                $source = $pathGuard->resolveExistingFileWithinFrom(
                    $album->libraryRoot->path,
                    $album->relative_path,
                    $album->artwork_source_relative_path,
                );
            } catch (InvalidLibraryPath) {
                $source = null;
            }

            if ($source !== null && hash_file('sha256', $source) === $artwork->checksum) {
                return response()->file($source, [
                    'Cache-Control' => 'private, max-age=3600',
                    'Content-Type' => $artwork->mime_type,
                ]);
            }
        }

        if ($album->artwork_source_type === ArtworkSource::Embedded) {
            foreach ($album->mediaFiles as $mediaFile) {
                $source = $pathGuard->resolveExistingFileWithin(
                    $album->libraryRoot->path,
                    $mediaFile->relative_path,
                );
                if ($source === null) {
                    continue;
                }

                try {
                    $embedded = $metadataReader->read($source)->embeddedArtwork;
                } catch (Throwable) {
                    continue;
                }

                if ($embedded !== null && hash('sha256', $embedded->bytes) === $artwork->checksum) {
                    return response($embedded->bytes, 200, [
                        'Cache-Control' => 'private, max-age=3600',
                        'Content-Type' => $embedded->mimeType,
                    ]);
                }
            }
        }

        abort(404);
    }
}
