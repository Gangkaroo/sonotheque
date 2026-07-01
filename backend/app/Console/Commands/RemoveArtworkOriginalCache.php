<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RemoveArtworkOriginalCache extends Command
{
    protected $signature = 'music:artwork:remove-original-cache';

    protected $description = 'Delete the obsolete full-size artwork cache';

    public function handle(): int
    {
        $storage = Storage::disk(config('music-library.artwork.disk'));
        $files = $storage->allFiles('originals');
        $bytes = array_sum(array_map(
            fn (string $path): int => $storage->size($path),
            $files,
        ));

        $storage->deleteDirectory('originals');

        $this->info(sprintf(
            'Removed %d cached originals (%s).',
            count($files),
            $this->formatBytes($bytes),
        ));

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return number_format($value, 2).' '.$unit;
            }
            $value /= 1024;
        }

        return "{$bytes} B";
    }
}
