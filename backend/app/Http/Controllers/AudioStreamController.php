<?php

namespace App\Http\Controllers;

use App\Enums\MediaFileStatus;
use App\Models\Track;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use App\Music\Streaming\ByteRangeParser;
use App\Music\Streaming\InvalidByteRange;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioStreamController extends Controller
{
    private const CHUNK_SIZE = 64 * 1024;

    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly ByteRangeParser $rangeParser,
    ) {
    }

    public function __invoke(Request $request, Track $track): StreamedResponse
    {
        $track->loadMissing('mediaFile.libraryRoot');
        $mediaFile = $track->mediaFile;
        $libraryRoot = $mediaFile?->libraryRoot;

        abort_if(
            $mediaFile === null
            || $libraryRoot === null
            || ! $libraryRoot->enabled
            || $mediaFile->status !== MediaFileStatus::Available,
            404,
        );

        try {
            $path = $this->pathGuard->resolveExistingFileWithin(
                $libraryRoot->path,
                $mediaFile->relative_path,
            );
        } catch (InvalidLibraryPath) {
            abort(404);
        }

        abort_if($path === null, 404);
        $fileSize = filesize($path);
        abort_if($fileSize === false || $fileSize <= 0, 404);

        try {
            $range = $this->rangeParser->parse(
                $request->header('Range'),
                $fileSize,
                max(1, (int) config('music-library.audio_stream_open_ended_range_bytes')),
            );
        } catch (InvalidByteRange) {
            return $this->rangeNotSatisfiable($fileSize);
        }

        $status = $range === null ? 200 : 206;
        $start = $range?->start ?? 0;
        $length = $range?->length() ?? $fileSize;
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'Content-Length' => (string) $length,
            'Content-Type' => $mediaFile->mime_type ?: $this->fallbackMimeType($path),
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$range->start}-{$range->end}/{$fileSize}";
        }

        return response()->stream(
            fn () => $this->stream($path, $start, $length),
            $status,
            $headers,
        );
    }

    private function stream(string $path, int $start, int $length): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            if ($start > 0) {
                fseek($handle, $start);
            }

            $remaining = $length;

            while ($remaining > 0 && ! feof($handle) && ! connection_aborted()) {
                $bytes = fread($handle, min(self::CHUNK_SIZE, $remaining));

                if ($bytes === false || $bytes === '') {
                    break;
                }

                echo $bytes;
                $remaining -= strlen($bytes);
            }
        } finally {
            fclose($handle);
        }
    }

    private function rangeNotSatisfiable(int $fileSize): StreamedResponse
    {
        return response()->stream(
            static fn () => null,
            416,
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => "bytes */{$fileSize}",
                'Content-Length' => '0',
            ],
        );
    }

    private function fallbackMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'aac' => 'audio/aac',
            'aif', 'aiff' => 'audio/aiff',
            'flac' => 'audio/flac',
            'm4a', 'alac' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'oga', 'ogg' => 'audio/ogg',
            'opus' => 'audio/ogg; codecs=opus',
            'wav' => 'audio/wav',
            'wma' => 'audio/x-ms-wma',
            default => 'application/octet-stream',
        };
    }
}
