<?php

namespace App\System\Backups;

use App\Enums\ScanStatus;
use App\Models\AudioAnalysisRun;
use App\Models\ScanRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class SystemBackupManager
{
    public function __construct(
        private readonly PostgresBackupClient $postgres,
        private readonly SystemBackupArchive $archive,
        private readonly ApplicationStorageArchive $storageArchive,
    ) {
    }

    /** @param callable(int, string): void|null $progress */
    public function create(string $destination, ?callable $progress = null): array
    {
        $destination = realpath($destination) ?: '';
        if ($destination === '' || ! is_dir($destination) || ! is_writable($destination)) {
            throw new RuntimeException('The selected backup folder is not writable.');
        }

        $this->removeAbandonedCreateDirectories();
        $staging = $this->temporaryDirectory('create');
        try {
            $progress?->__invoke(10, 'database');
            $this->postgres->dump($staging.DIRECTORY_SEPARATOR.'database.dump');
            $progress?->__invoke(45, 'storage');
            $this->storageArchive->create(
                storage_path('app'),
                $staging.DIRECTORY_SEPARATOR.'storage.tar',
            );
            file_put_contents($staging.DIRECTORY_SEPARATOR.'app-key.txt', (string) config('app.key'));

            $name = $this->availableArchiveName($destination);
            $path = $destination.DIRECTORY_SEPARATOR.$name;
            $progress?->__invoke(75, 'archive');
            $manifest = $this->archive->create($staging, $path, $this->mode());
            $this->writeLatestMarker('backup', $name, filesize($path));

            return [
                'path' => $path,
                'name' => $name,
                'bytes' => filesize($path),
                'manifest' => $manifest,
            ];
        } finally {
            File::deleteDirectory($staging);
        }
    }

    /** @return array<string, mixed> */
    public function inspect(string $path): array
    {
        $staging = $this->temporaryDirectory('inspect');
        try {
            $result = $this->archive->extract($path, $staging);
            $manifest = $result['manifest'];

            return [
                'path' => $result['path'],
                'name' => basename($result['path']),
                'createdAt' => $manifest['createdAt'] ?? null,
                'mode' => $manifest['mode'] ?? null,
                'database' => $manifest['database'] ?? null,
                'bytes' => filesize($result['path']),
                'modeMatches' => ($manifest['mode'] ?? null) === $this->mode(),
                'appKeyMatches' => hash_equals(
                    trim((string) file_get_contents($staging.DIRECTORY_SEPARATOR.'app-key.txt')),
                    (string) config('app.key'),
                ),
            ];
        } finally {
            File::deleteDirectory($staging);
        }
    }

    /** @param callable(int, string): void|null $progress */
    public function restore(string $path, ?callable $progress = null): array
    {
        $this->assertRestoreIsIdle();
        $staging = $this->temporaryDirectory('restore');
        $maintenanceEnabled = false;
        $safeToReopen = true;
        $destructiveRestoreStarted = false;
        $safety = null;

        try {
            $progress?->__invoke(5, 'validate');
            $result = $this->archive->extract($path, $staging);
            if (($result['manifest']['mode'] ?? null) !== $this->mode()) {
                throw new RuntimeException(
                    'This backup was created in a different runtime mode. Use the documented command-line migration workflow instead.',
                );
            }
            $backupKey = trim((string) file_get_contents($staging.DIRECTORY_SEPARATOR.'app-key.txt'));
            if (! hash_equals($backupKey, (string) config('app.key'))) {
                throw new RuntimeException(
                    'This backup uses a different application encryption key. Use the command-line restore with the backup key for a different installation.',
                );
            }

            $progress?->__invoke(10, 'safetyBackup');
            $safety = $this->create($this->safetyDirectory());
            Artisan::call('down', ['--retry' => 15]);
            $maintenanceEnabled = true;

            $destructiveRestoreStarted = true;
            $this->applyExtractedRestore($staging, $progress);
            $name = basename($result['path']);
            $this->writeLatestMarker('restore', $name, filesize($result['path']));

            return [
                'path' => $result['path'],
                'name' => $name,
                'bytes' => filesize($result['path']),
                'safetyBackupName' => $safety['name'],
            ];
        } catch (Throwable $exception) {
            if (! $destructiveRestoreStarted || $safety === null) {
                throw $exception;
            }

            $safeToReopen = false;
            $rollbackStaging = $this->temporaryDirectory('rollback');
            try {
                $this->archive->extract($safety['path'], $rollbackStaging);
                $this->applyExtractedRestore($rollbackStaging);
                $safeToReopen = true;
            } catch (Throwable $rollbackException) {
                throw new RuntimeException(
                    $exception->getMessage().' Automatic rollback also failed: '
                    .$rollbackException->getMessage().' The safety backup is '.$safety['path'].'.',
                    previous: $exception,
                );
            } finally {
                File::deleteDirectory($rollbackStaging);
            }

            throw new RuntimeException(
                $exception->getMessage().' The current installation was restored from the safety backup.',
                previous: $exception,
            );
        } finally {
            File::deleteDirectory($staging);
            if ($maintenanceEnabled && $safeToReopen) {
                Artisan::call('up');
            }
        }
    }

    /** @param callable(int, string): void|null $progress */
    private function applyExtractedRestore(string $staging, ?callable $progress = null): void
    {
        $progress?->__invoke(35, 'restoreDatabase');
        $this->postgres->restore($staging.DIRECTORY_SEPARATOR.'database.dump');
        $progress?->__invoke(70, 'restoreStorage');
        $this->storageArchive->restore(
            $staging.DIRECTORY_SEPARATOR.'storage.tar',
            storage_path('app'),
        );
        $progress?->__invoke(88, 'migrations');
        $this->runMigrations();

        DB::purge();
        DB::table('jobs')->truncate();
    }

    private function assertRestoreIsIdle(): void
    {
        if (ScanRun::query()->whereIn('status', [
            ScanStatus::Pending->value,
            ScanStatus::Running->value,
        ])->exists()) {
            throw new RuntimeException('Wait for the active library scan to finish before restoring.');
        }
        if (AudioAnalysisRun::query()->whereIn('status', [
            'fingerprinting',
            'queued',
            'running',
        ])->exists()) {
            throw new RuntimeException('Pause or finish Audio Intelligence analysis before restoring.');
        }
        if (DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('queue', '!=', 'default')
            ->exists()) {
            throw new RuntimeException('Wait for active background work to finish before restoring.');
        }
    }

    private function runMigrations(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'migrate', '--force', '--no-interaction']);
        $process->setTimeout(null);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Database migrations failed after restore. '.trim($process->getErrorOutput()),
            );
        }
    }

    private function temporaryDirectory(string $purpose): string
    {
        $path = storage_path('framework/system-backups/'.$purpose.'-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($path);

        return $path;
    }

    private function removeAbandonedCreateDirectories(): void
    {
        $directory = storage_path('framework/system-backups');
        if (! is_dir($directory)) {
            return;
        }
        foreach (File::directories($directory) as $path) {
            if (str_starts_with(basename($path), 'create-')) {
                File::deleteDirectory($path);
            }
        }
    }

    private function safetyDirectory(): string
    {
        $path = (string) config('sonotheque.system_backups.safety_path');
        File::ensureDirectoryExists($path);

        return $path;
    }

    private function availableArchiveName(string $destination): string
    {
        $base = 'sonotheque-'.strtolower($this->mode()).'-'.now()->utc()->format('Ymd-His');
        $name = $base.'.'.SystemBackupArchive::EXTENSION;
        $suffix = 2;
        while (file_exists($destination.DIRECTORY_SEPARATOR.$name)) {
            $name = $base.'-'.$suffix.'.'.SystemBackupArchive::EXTENSION;
            $suffix++;
        }

        return $name;
    }

    private function mode(): string
    {
        return app()->environment('production') ? 'Packaged' : 'Development';
    }

    private function writeLatestMarker(string $operation, string $name, int|false $bytes): void
    {
        $path = (string) config('sonotheque.system_health.backup_status_path');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode([
            'operation' => $operation,
            'status' => 'completed',
            'mode' => $this->mode(),
            'completedAt' => now()->toJSON(),
            'bundleName' => $name,
            'bytes' => $bytes === false ? null : $bytes,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
