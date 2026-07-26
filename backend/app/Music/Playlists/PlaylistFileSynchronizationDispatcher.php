<?php

namespace App\Music\Playlists;

use App\Jobs\DeleteSynchronizedPlaylistFile;
use App\Jobs\SynchronizePlaylistFile;
use App\Models\ApplicationSetting;
use App\Models\Playlist;
use App\Models\PlaylistItem;

class PlaylistFileSynchronizationDispatcher
{
    public function playlist(Playlist|int $playlist): void
    {
        if (! $this->enabled()) {
            return;
        }

        $playlistId = $playlist instanceof Playlist ? $playlist->id : $playlist;
        Playlist::query()->whereKey($playlistId)->update([
            'playlist_export_sync_pending_at' => now(),
            'playlist_export_sync_error' => null,
        ]);
        SynchronizePlaylistFile::dispatch($playlistId)->afterCommit();
    }

    /** @param iterable<int> $playlistIds */
    public function playlists(iterable $playlistIds): void
    {
        if (! $this->enabled()) {
            return;
        }

        $playlistIds = collect($playlistIds)
            ->map(fn (int $playlistId): int => $playlistId)
            ->unique()
            ->values();
        if ($playlistIds->isEmpty()) {
            return;
        }

        Playlist::query()->whereIn('id', $playlistIds->all())->update([
            'playlist_export_sync_pending_at' => now(),
            'playlist_export_sync_error' => null,
        ]);
        foreach ($playlistIds as $playlistId) {
            SynchronizePlaylistFile::dispatch((int) $playlistId)->afterCommit();
        }
    }

    public function all(): void
    {
        if (! $this->enabled()) {
            return;
        }

        Playlist::query()->update([
            'playlist_export_sync_pending_at' => now(),
            'playlist_export_sync_error' => null,
        ]);
        Playlist::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(250, function ($playlists): void {
                foreach ($playlists as $playlist) {
                    SynchronizePlaylistFile::dispatch($playlist->id)->afterCommit();
                }
            });
    }

    /** @param iterable<int> $trackIds */
    public function tracks(iterable $trackIds): void
    {
        if (! $this->enabled()) {
            return;
        }

        $ids = collect($trackIds)
            ->map(fn (int $trackId): int => $trackId)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return;
        }

        $this->playlists(
            PlaylistItem::query()
                ->whereIn('track_id', $ids)
                ->distinct()
                ->pluck('playlist_id'),
        );
    }

    public function delete(?string $rootPath, ?string $relativePath): void
    {
        if (! $this->enabled() || $rootPath === null || $relativePath === null) {
            return;
        }

        DeleteSynchronizedPlaylistFile::dispatch($rootPath, $relativePath)->afterCommit();
    }

    private function enabled(): bool
    {
        return (bool) ApplicationSetting::current()->synchronize_playlists_to_files;
    }
}
