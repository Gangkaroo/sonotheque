<?php

namespace App\Jobs;

use App\Models\ApplicationSetting;
use App\Models\Playlist;
use App\Models\PlaylistExportLocation;
use App\Music\Playlists\CustomPlaylistExporter;
use App\Music\Playlists\PlaylistSynchronizationPath;
use App\Music\Playlists\SynchronizedPlaylistFileCleaner;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SynchronizePlaylistFile implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $playlistId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->playlistId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(
        PlaylistSynchronizationPath $synchronizationPath,
        CustomPlaylistExporter $exporter,
        SynchronizedPlaylistFileCleaner $cleaner,
    ): void {
        $settings = ApplicationSetting::current();
        if (! $settings->synchronize_playlists_to_files) {
            return;
        }

        $playlist = Playlist::find($this->playlistId);
        if ($playlist === null) {
            return;
        }

        $location = PlaylistExportLocation::query()
            ->where('is_default', true)
            ->first();
        if ($location === null) {
            $playlist->update([
                'playlist_export_sync_pending_at' => null,
                'playlist_export_sync_error' => 'No default playlist folder is configured.',
            ]);

            return;
        }

        try {
            $format = $settings->playlist_export_format ?: 'm3u8';
            $destination = $synchronizationPath->prepare($playlist, $location, $format);
            $exporter->exportToDirectory(
                $playlist,
                $destination['directory'],
                $format,
                $destination['filename'],
                true,
                null,
                allowEmpty: true,
            );

            $oldRootPath = $playlist->playlist_export_root_path;
            $oldRelativePath = $playlist->playlist_export_relative_path;
            $playlist->update([
                'playlist_export_location_id' => $location->id,
                'playlist_export_root_path' => $destination['rootPath'],
                'playlist_export_relative_path' => $destination['relativePath'],
                'playlist_export_synced_at' => now(),
                'playlist_export_sync_pending_at' => null,
                'playlist_export_sync_error' => null,
            ]);

            if ($oldRootPath !== $destination['rootPath']
                || $oldRelativePath !== $destination['relativePath']) {
                $cleaner->delete($oldRootPath, $oldRelativePath);
            }
        } catch (Throwable $exception) {
            $playlist->update([
                'playlist_export_sync_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Playlist::query()->whereKey($this->playlistId)->update([
            'playlist_export_sync_pending_at' => null,
            'playlist_export_sync_error' => $exception->getMessage(),
        ]);
    }
}
