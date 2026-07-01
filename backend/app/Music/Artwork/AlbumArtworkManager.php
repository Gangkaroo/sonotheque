<?php

namespace App\Music\Artwork;

use App\Enums\ArtworkSource;
use App\Models\Album;
use App\Models\Artwork;
use App\Models\LibraryRoot;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AlbumArtworkManager
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly string $disk,
        private readonly int $thumbnailWidth,
        private readonly int $thumbnailHeight,
        private readonly int $thumbnailQuality,
        private readonly int $maxSourceBytes,
        private readonly int $maxSourcePixels,
    ) {}

    public function sync(
        Album $album,
        LibraryRoot $libraryRoot,
        ?EmbeddedArtwork $embeddedArtwork = null,
        bool $embeddedInspected = false,
    ): ArtworkSyncResult {
        $warnings = [];

        try {
            $albumRelativePath = $this->pathGuard->normalizeRelativePath($album->relative_path);
            $coverCandidates = $libraryRoot->cover_image_paths ?: ['cover.jpg'];
            $source = null;
            $selectedCoverPath = null;
            foreach ($coverCandidates as $candidate) {
                try {
                    $coverRelativePath = $this->pathGuard->normalizeNavigableRelativePath($candidate);
                    $source = $this->pathGuard->resolveExistingFileWithinFrom(
                        $libraryRoot->path,
                        $albumRelativePath,
                        $coverRelativePath,
                    );
                } catch (InvalidLibraryPath $exception) {
                    $warnings[] = "Album artwork path [{$candidate}] for [{$album->relative_path}] was skipped: {$exception->getMessage()}";

                    continue;
                }

                if ($source !== null) {
                    $selectedCoverPath = $coverRelativePath;
                    break;
                }
            }

            if ($source !== null) {
                $bytes = $this->readSourceFile($source);
                $artwork = $this->cache(
                    $bytes,
                    ArtworkSource::Folder,
                    $selectedCoverPath,
                );
                $this->attach($album, $artwork, ArtworkSource::Folder, $selectedCoverPath);

                return new ArtworkSyncResult($artwork, $warnings);
            }

            if ($embeddedArtwork !== null) {
                $artwork = $this->cache(
                    $embeddedArtwork->bytes,
                    ArtworkSource::Embedded,
                    null,
                    $embeddedArtwork->mimeType,
                );
                $this->attach($album, $artwork, ArtworkSource::Embedded);

                return new ArtworkSyncResult($artwork, $warnings);
            }

            if ($this->hasEmbeddedArtwork($album)) {
                return new ArtworkSyncResult($album->artwork, $warnings);
            }

            if (! $embeddedInspected) {
                return new ArtworkSyncResult(
                    $album->artwork,
                    $warnings,
                    requiresEmbeddedFallback: true,
                );
            }

            $this->detach($album);

            return new ArtworkSyncResult(null, $warnings);
        } catch (Throwable $exception) {
            $warning = "Album artwork for [{$album->relative_path}] was skipped: {$exception->getMessage()}";
            $warnings[] = $warning;

            if ($embeddedArtwork !== null) {
                try {
                    $artwork = $this->cache(
                        $embeddedArtwork->bytes,
                        ArtworkSource::Embedded,
                        null,
                        $embeddedArtwork->mimeType,
                    );
                    $this->attach($album, $artwork, ArtworkSource::Embedded);

                    return new ArtworkSyncResult($artwork, $warnings);
                } catch (Throwable $fallbackException) {
                    $warnings[] = "Embedded artwork was skipped: {$fallbackException->getMessage()}";

                    return new ArtworkSyncResult(
                        $album->artwork,
                        $warnings,
                    );
                }
            }

            if ($this->hasEmbeddedArtwork($album)) {
                return new ArtworkSyncResult($album->artwork, $warnings);
            }

            return new ArtworkSyncResult(
                $album->artwork,
                $warnings,
                requiresEmbeddedFallback: ! $embeddedInspected,
            );
        }
    }

    private function readSourceFile(string $source): string
    {
        $fileSize = filesize($source);

        if ($fileSize === false || $fileSize > $this->maxSourceBytes) {
            throw new RuntimeException('The source image exceeds the configured file-size limit.');
        }

        $bytes = file_get_contents($source);

        if ($bytes === false) {
            throw new RuntimeException('The source image could not be read.');
        }

        return $bytes;
    }

    private function cache(
        string $bytes,
        ArtworkSource $sourceType,
        ?string $sourceRelativePath,
        ?string $declaredMimeType = null,
    ): Artwork {
        if (strlen($bytes) > $this->maxSourceBytes) {
            throw new RuntimeException('The source image exceeds the configured file-size limit.');
        }

        $imageInfo = @getimagesizefromstring($bytes);

        if ($imageInfo === false) {
            throw new RuntimeException('The selected artwork is not a valid image.');
        }

        [$width, $height] = $imageInfo;
        $mimeType = $imageInfo['mime'] ?? null;

        if ($declaredMimeType !== null && $declaredMimeType !== $mimeType) {
            throw new RuntimeException('The embedded artwork MIME type does not match its image data.');
        }

        $this->extensionForMime($mimeType);

        if ($width * $height > $this->maxSourcePixels) {
            throw new RuntimeException('The source image exceeds the configured pixel limit.');
        }

        $checksum = hash('sha256', $bytes);
        $prefix = substr($checksum, 0, 2);
        $thumbnailPath = sprintf(
            'thumbnails/%s/%s-%dx%d-q%d.webp',
            $prefix,
            $checksum,
            $this->thumbnailWidth,
            $this->thumbnailHeight,
            $this->thumbnailQuality,
        );
        $storage = Storage::disk($this->disk);
        $artwork = Artwork::firstOrNew(['checksum' => $checksum]);

        if (! $storage->exists($thumbnailPath)) {
            $storage->put($thumbnailPath, $this->thumbnail($bytes, $width, $height));
        }

        $attributes = [
            'thumbnail_path' => $thumbnailPath,
            'mime_type' => $mimeType,
            'width' => $width,
            'height' => $height,
        ];

        if (! $artwork->exists) {
            $attributes['source_type'] = $sourceType;
            $attributes['source_relative_path'] = $sourceRelativePath;
        }

        $artwork->fill($attributes);
        $artwork->save();

        return $artwork;
    }

    private function attach(
        Album $album,
        Artwork $artwork,
        ArtworkSource $sourceType,
        ?string $sourceRelativePath = null,
    ): void {
        $album->update([
            'artwork_id' => $artwork->id,
            'artwork_source_type' => $sourceType,
            'artwork_source_relative_path' => $sourceRelativePath,
        ]);
    }

    private function detach(Album $album): void
    {
        $album->update([
            'artwork_id' => null,
            'artwork_source_type' => null,
            'artwork_source_relative_path' => null,
        ]);
    }

    private function hasEmbeddedArtwork(Album $album): bool
    {
        return $album->artwork_source_type === ArtworkSource::Embedded
            || ($album->artwork_source_type === null
                && $album->artwork?->source_type === ArtworkSource::Embedded);
    }

    private function thumbnail(string $bytes, int $sourceWidth, int $sourceHeight): string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException('GD could not decode the selected artwork.');
        }

        $scale = min(
            $this->thumbnailWidth / $sourceWidth,
            $this->thumbnailHeight / $sourceHeight,
            1,
        );
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $thumbnail = imagecreatetruecolor($width, $height);

        if ($thumbnail === false) {
            imagedestroy($image);
            throw new RuntimeException('GD could not allocate the thumbnail image.');
        }

        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
        imagefill($thumbnail, 0, 0, $transparent);
        imagecopyresampled(
            $thumbnail,
            $image,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $sourceWidth,
            $sourceHeight,
        );

        ob_start();
        $encoded = imagewebp($thumbnail, null, $this->thumbnailQuality);
        $thumbnailBytes = ob_get_clean();
        imagedestroy($thumbnail);
        imagedestroy($image);

        if (! $encoded || ! is_string($thumbnailBytes)) {
            throw new RuntimeException('GD could not encode the thumbnail as WebP.');
        }

        return $thumbnailBytes;
    }

    private function extensionForMime(?string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => throw new RuntimeException("Unsupported artwork image type [{$mimeType}]."),
        };
    }
}
