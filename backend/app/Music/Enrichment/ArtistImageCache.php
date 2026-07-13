<?php

namespace App\Music\Enrichment;

use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ArtistImageCache
{
    private const ALLOWED_MIME_TYPES = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function response(string $url): ?Response
    {
        if (! $this->isAllowedUrl($url)) {
            return null;
        }

        $hash = hash('sha256', $url);
        $cached = $this->cached($hash);
        if ($cached !== null) {
            return $this->imageResponse(...$cached);
        }

        try {
            $download = $this->download($url);
        } catch (Throwable) {
            return null;
        }

        if ($download === null) {
            return null;
        }

        [$bytes, $mimeType] = $download;
        $storage = Storage::disk($this->disk());
        $storage->put($this->imagePath($hash), $bytes);
        $storage->put($this->mimePath($hash), $mimeType);

        return $this->imageResponse($bytes, $mimeType);
    }

    public function clear(): void
    {
        Storage::disk($this->disk())->deleteDirectory($this->basePath());
    }

    /** @return array{string, string}|null */
    private function cached(string $hash): ?array
    {
        $storage = Storage::disk($this->disk());
        $imagePath = $this->imagePath($hash);
        $mimePath = $this->mimePath($hash);
        if (! $storage->exists($imagePath) || ! $storage->exists($mimePath)) {
            return null;
        }

        $bytes = $storage->get($imagePath);
        $mimeType = trim((string) $storage->get($mimePath));

        return is_string($bytes) && in_array($mimeType, self::ALLOWED_MIME_TYPES, true)
            ? [$bytes, $mimeType]
            : null;
    }

    /** @return array{string, string}|null */
    private function download(string $url): ?array
    {
        $options = ['allow_redirects' => false];
        $caBundle = trim((string) config('sonotheque.enrichment.wikimedia.ca_bundle'));
        $proxy = trim((string) config('sonotheque.enrichment.wikimedia.proxy'));
        if ($caBundle !== '') {
            $options['verify'] = $caBundle;
        }
        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }

        $response = Http::withHeaders([
            'User-Agent' => (string) config('sonotheque.enrichment.wikimedia.user_agent'),
        ])->withOptions($options)
            ->timeout(max(1, (int) config('sonotheque.enrichment.wikimedia.timeout_seconds', 20)))
            ->get($url);

        return $this->validate($response);
    }

    /** @return array{string, string}|null */
    private function validate(ClientResponse $response): ?array
    {
        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) > (int) config('sonotheque.enrichment.image_max_bytes')) {
            return null;
        }

        $mimeType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return null;
        }

        $image = @getimagesizefromstring($bytes);
        if ($image === false || ($image[0] * $image[1]) > (int) config('sonotheque.enrichment.image_max_pixels')) {
            return null;
        }

        if (($image['mime'] ?? null) !== $mimeType) {
            return null;
        }

        return [$bytes, $mimeType];
    }

    private function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null)) {
            return false;
        }

        $host = strtolower($parts['host']);
        $allowedHosts = ['upload.wikimedia.org'];

        return collect($allowedHosts)->contains(
            fn (string $allowed): bool => $host === $allowed || str_ends_with($host, '.'.$allowed),
        );
    }

    private function imageResponse(string $bytes, string $mimeType): Response
    {
        return response($bytes, 200, [
            'Cache-Control' => 'private, max-age=86400',
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function disk(): string
    {
        return (string) config('sonotheque.enrichment.image_disk', 'local');
    }

    private function basePath(): string
    {
        return trim((string) config('sonotheque.enrichment.image_path', 'enrichment-images'), '/');
    }

    private function imagePath(string $hash): string
    {
        return $this->basePath().'/'.$hash.'.image';
    }

    private function mimePath(string $hash): string
    {
        return $this->basePath().'/'.$hash.'.mime';
    }
}
