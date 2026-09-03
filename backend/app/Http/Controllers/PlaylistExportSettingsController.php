<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Models\Playlist;
use App\Models\PlaylistExportLocation;
use App\Music\Playlists\PlaylistFileSynchronizationDispatcher;
use App\Music\Playlists\PlaylistFilesystemName;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use App\Support\DirectoryWriteProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlaylistExportSettingsController extends Controller
{
    public function __construct(
        private readonly DirectoryWriteProbe $directoryWriteProbe,
        private readonly PlaylistFileSynchronizationDispatcher $synchronizationDispatcher,
        private readonly PlaylistFilesystemName $filesystemName,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'defaultFormat' => ['required', 'string', 'in:m3u,m3u8'],
            'synchronizePlaylists' => ['sometimes', 'boolean'],
        ]);

        $settings = ApplicationSetting::current();
        $synchronize = (bool) ($validated['synchronizePlaylists']
            ?? $settings->synchronize_playlists_to_files);
        if ($synchronize
            && ! PlaylistExportLocation::query()->where('is_default', true)->exists()) {
            throw ValidationException::withMessages([
                'synchronizePlaylists' => 'Configure a default playlist folder before enabling synchronization.',
            ]);
        }

        $shouldSynchronizeAll = $synchronize && (
            ! $settings->synchronize_playlists_to_files
            || $settings->playlist_export_format !== $validated['defaultFormat']
        );
        $settings->update([
            'playlist_export_format' => $validated['defaultFormat'],
            'synchronize_playlists_to_files' => $synchronize,
        ]);
        if ($shouldSynchronizeAll) {
            $this->synchronizationDispatcher->all();
        } elseif (! $synchronize) {
            Playlist::query()
                ->whereNotNull('playlist_export_sync_pending_at')
                ->update(['playlist_export_sync_pending_at' => null]);
        }

        return response()->json($this->payload());
    }

    public function retryFailedSynchronization(): JsonResponse
    {
        if (! ApplicationSetting::current()->synchronize_playlists_to_files) {
            throw ValidationException::withMessages([
                'synchronizePlaylists' => 'Enable playlist synchronization before retrying failed exports.',
            ]);
        }

        $this->synchronizationDispatcher->playlists(
            Playlist::query()
                ->whereNull('playlist_export_sync_pending_at')
                ->whereNotNull('playlist_export_sync_error')
                ->pluck('id'),
        );

        return response()->json($this->payload(), 202);
    }

    public function storeLocation(
        Request $request,
        LibraryPathGuard $pathGuard,
    ): JsonResponse {
        $validated = $this->validateLocation($request);
        $path = $this->writablePath(
            $pathGuard,
            $validated['path'],
            $validated['name'],
            (bool) ($validated['createSubfolder'] ?? false),
        );
        $hash = $this->pathHash($path);
        if (PlaylistExportLocation::query()->where('path_hash', $hash)->exists()) {
            throw ValidationException::withMessages([
                'path' => 'This playlist export folder is already configured.',
            ]);
        }

        $makeDefault = (bool) ($validated['makeDefault'] ?? false)
            || ! PlaylistExportLocation::query()->exists();
        DB::transaction(function () use ($validated, $path, $hash, $makeDefault): void {
            if ($makeDefault) {
                PlaylistExportLocation::query()->update(['is_default' => false]);
            }

            PlaylistExportLocation::create([
                'name' => trim($validated['name']),
                'path' => $path,
                'path_hash' => $hash,
                'is_default' => $makeDefault,
            ]);
        });
        if ($makeDefault) {
            $this->synchronizationDispatcher->all();
        }

        return response()->json($this->payload(), 201);
    }

    public function updateLocation(
        Request $request,
        PlaylistExportLocation $playlistExportLocation,
        LibraryPathGuard $pathGuard,
    ): JsonResponse {
        $validated = $this->validateLocation($request);
        $path = $this->writablePath(
            $pathGuard,
            $validated['path'],
            $validated['name'],
            (bool) ($validated['createSubfolder'] ?? false),
        );
        $hash = $this->pathHash($path);
        if (PlaylistExportLocation::query()
            ->where('path_hash', $hash)
            ->whereKeyNot($playlistExportLocation->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'path' => 'This playlist export folder is already configured.',
            ]);
        }

        $resynchronize = ($playlistExportLocation->is_default
            && $playlistExportLocation->path !== $path)
            || (! $playlistExportLocation->is_default
                && (bool) ($validated['makeDefault'] ?? false));
        DB::transaction(function () use ($playlistExportLocation, $validated, $path, $hash): void {
            if ($validated['makeDefault'] ?? false) {
                PlaylistExportLocation::query()
                    ->whereKeyNot($playlistExportLocation->id)
                    ->update(['is_default' => false]);
            }
            $playlistExportLocation->update([
                'name' => trim($validated['name']),
                'path' => $path,
                'path_hash' => $hash,
                'is_default' => $playlistExportLocation->is_default
                    || (bool) ($validated['makeDefault'] ?? false),
            ]);
        });
        if ($resynchronize) {
            $this->synchronizationDispatcher->all();
        }

        return response()->json($this->payload());
    }

    public function setDefault(
        PlaylistExportLocation $playlistExportLocation,
    ): JsonResponse {
        $changed = ! $playlistExportLocation->is_default;
        DB::transaction(function () use ($playlistExportLocation): void {
            PlaylistExportLocation::query()
                ->whereKeyNot($playlistExportLocation->id)
                ->update(['is_default' => false]);
            $playlistExportLocation->update(['is_default' => true]);
        });
        if ($changed) {
            $this->synchronizationDispatcher->all();
        }

        return response()->json($this->payload());
    }

    public function destroyLocation(
        PlaylistExportLocation $playlistExportLocation,
    ): JsonResponse {
        if ($playlistExportLocation->is_default
            && ApplicationSetting::current()->synchronize_playlists_to_files
            && ! PlaylistExportLocation::query()
                ->whereKeyNot($playlistExportLocation->id)
                ->exists()) {
            throw ValidationException::withMessages([
                'location' => 'Disable playlist synchronization or choose another default folder before removing this folder.',
            ]);
        }

        $resynchronize = $playlistExportLocation->is_default;
        DB::transaction(function () use ($playlistExportLocation): void {
            $wasDefault = $playlistExportLocation->is_default;
            $playlistExportLocation->delete();
            if ($wasDefault) {
                PlaylistExportLocation::query()
                    ->orderBy('name')
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_default' => true]);
            }
        });
        if ($resynchronize) {
            $this->synchronizationDispatcher->all();
        }

        return response()->json($this->payload());
    }

    /** @return array{name: string, path: string, makeDefault?: bool, createSubfolder?: bool} */
    private function validateLocation(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'path' => ['required', 'string', 'max:4096', 'not_regex:/^\s*$/'],
            'makeDefault' => ['sometimes', 'boolean'],
            'createSubfolder' => ['sometimes', 'boolean'],
        ]);
    }

    private function writablePath(
        LibraryPathGuard $pathGuard,
        string $path,
        string $name,
        bool $createSubfolder,
    ): string {
        try {
            $path = $pathGuard->canonicalizeDirectory($path);
        } catch (InvalidLibraryPath $exception) {
            throw ValidationException::withMessages([
                'path' => $exception->getMessage(),
            ]);
        }
        if ($createSubfolder || $this->isFilesystemRoot($path)) {
            $path = $this->namedSubfolder($pathGuard, $path, $name);
        }
        if (! $this->directoryWriteProbe->canWrite($path)) {
            throw ValidationException::withMessages([
                'path' => 'Sonotheque could not create files in this playlist export folder.',
            ]);
        }

        return $path;
    }

    private function namedSubfolder(
        LibraryPathGuard $pathGuard,
        string $parent,
        string $name,
    ): string {
        if (! $this->directoryWriteProbe->canWrite($parent)) {
            throw ValidationException::withMessages([
                'path' => 'Sonotheque could not create a playlist folder in the selected location.',
            ]);
        }

        $path = rtrim($parent, '/\\')
            .DIRECTORY_SEPARATOR
            .$this->filesystemName->component($name, 'Playlists');
        if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
            throw ValidationException::withMessages([
                'path' => 'The playlist folder name is already used by a file or symbolic link.',
            ]);
        }
        if (! is_dir($path) && ! @mkdir($path, 0777)) {
            throw ValidationException::withMessages([
                'path' => 'Sonotheque could not create the named playlist folder.',
            ]);
        }

        try {
            return $pathGuard->canonicalizeDirectory($path);
        } catch (InvalidLibraryPath $exception) {
            throw ValidationException::withMessages([
                'path' => $exception->getMessage(),
            ]);
        }
    }

    private function isFilesystemRoot(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return $normalized === '/'
            || preg_match('#^[A-Za-z]:/$#', $normalized) === 1
            || preg_match('#^//[^/]+/[^/]+/?$#', $normalized) === 1;
    }

    private function pathHash(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY === 'Windows') {
            $normalized = mb_strtolower($normalized);
        }

        return hash('sha256', $normalized);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $settings = ApplicationSetting::current();

        return [
            'defaultFormat' => $settings->playlist_export_format ?: 'm3u8',
            'synchronizePlaylists' => (bool) $settings->synchronize_playlists_to_files,
            'synchronization' => [
                'playlistCount' => Playlist::query()->count(),
                'syncedCount' => Playlist::query()
                    ->whereNotNull('playlist_export_synced_at')
                    ->whereNull('playlist_export_sync_pending_at')
                    ->whereNull('playlist_export_sync_error')
                    ->count(),
                'failedCount' => Playlist::query()
                    ->whereNull('playlist_export_sync_pending_at')
                    ->whereNotNull('playlist_export_sync_error')
                    ->count(),
                'pendingCount' => Playlist::query()
                    ->whereNotNull('playlist_export_sync_pending_at')
                    ->count(),
            ],
            'locations' => PlaylistExportLocation::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (PlaylistExportLocation $location): array => $this->locationPayload($location))
                ->values(),
        ];
    }

    /** @return array{id: int, name: string, path: string, isDefault: bool} */
    private function locationPayload(PlaylistExportLocation $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'path' => $location->path,
            'isDefault' => $location->is_default,
        ];
    }
}
