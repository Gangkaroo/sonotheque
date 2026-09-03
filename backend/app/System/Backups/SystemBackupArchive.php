<?php

namespace App\System\Backups;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use ZipArchive;

class SystemBackupArchive
{
    public const EXTENSION = 'sonotheque-backup';

    /** @return array<string, mixed> */
    public function create(string $stagingPath, string $archivePath, string $mode): array
    {
        $files = collect(['database.dump', 'storage.tar', 'app-key.txt'])
            ->map(function (string $name) use ($stagingPath): array {
                $path = $stagingPath.DIRECTORY_SEPARATOR.$name;
                if (! is_file($path)) {
                    throw new RuntimeException("Backup file is missing: {$name}");
                }

                return [
                    'name' => $name,
                    'bytes' => filesize($path),
                    'sha256' => hash_file('sha256', $path),
                ];
            })
            ->all();
        $manifest = [
            'version' => 1,
            'createdAt' => now()->toJSON(),
            'mode' => $mode,
            'database' => (string) config('database.connections.pgsql.database'),
            'files' => $files,
        ];
        file_put_contents(
            $stagingPath.DIRECTORY_SEPARATOR.'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $temporaryPath = $archivePath.'.partial-'.bin2hex(random_bytes(6));
        $zip = new ZipArchive();
        if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('The Sonotheque backup archive could not be created.');
        }
        foreach (['manifest.json', 'database.dump', 'storage.tar', 'app-key.txt'] as $name) {
            if (! $zip->addFile($stagingPath.DIRECTORY_SEPARATOR.$name, $name)) {
                $zip->close();
                @unlink($temporaryPath);
                throw new RuntimeException("{$name} could not be added to the backup archive.");
            }
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        if (! $zip->close() || ! rename($temporaryPath, $archivePath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('The Sonotheque backup archive could not be finalized.');
        }

        return $manifest;
    }

    /** @return array{path: string, manifest: array<string, mixed>} */
    public function extract(string $archivePath, string $destination): array
    {
        $archivePath = realpath($archivePath) ?: '';
        if ($archivePath === '' || ! is_file($archivePath)) {
            throw new InvalidArgumentException('The selected backup archive does not exist.');
        }
        if (mb_strtolower(pathinfo($archivePath, PATHINFO_EXTENSION)) !== self::EXTENSION) {
            throw new InvalidArgumentException('Select a .sonotheque-backup file.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new InvalidArgumentException('The selected backup archive could not be opened.');
        }
        $expected = ['app-key.txt', 'database.dump', 'manifest.json', 'storage.tar'];
        $actual = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if ($name === false || basename($name) !== $name) {
                $zip->close();
                throw new InvalidArgumentException('The backup archive contains an unsafe path.');
            }
            $actual[] = $name;
        }
        sort($actual);
        if ($actual !== $expected) {
            $zip->close();
            throw new InvalidArgumentException('The backup archive does not contain the expected files.');
        }
        if (! is_dir($destination) && ! mkdir($destination, 0777, true) && ! is_dir($destination)) {
            $zip->close();
            throw new RuntimeException('The backup staging directory could not be created.');
        }
        if (! $zip->extractTo($destination, $expected)) {
            $zip->close();
            throw new RuntimeException('The backup archive could not be extracted.');
        }
        $zip->close();

        try {
            $manifest = json_decode(
                (string) file_get_contents($destination.DIRECTORY_SEPARATOR.'manifest.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The backup manifest is not valid JSON.', previous: $exception);
        }
        if (! is_array($manifest)) {
            throw new InvalidArgumentException('The backup manifest is invalid.');
        }
        $this->validateManifest($manifest, $destination);

        return ['path' => $archivePath, 'manifest' => $manifest];
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifest(array $manifest, string $path): void
    {
        if (($manifest['version'] ?? null) !== 1 || ! is_array($manifest['files'] ?? null)) {
            throw new InvalidArgumentException('The backup manifest is not supported.');
        }
        $expected = ['app-key.txt', 'database.dump', 'storage.tar'];
        $actual = [];
        foreach ($manifest['files'] as $descriptor) {
            if (! is_array($descriptor)
                || ! is_string($descriptor['name'] ?? null)
                || ! is_string($descriptor['sha256'] ?? null)
                || ! is_int($descriptor['bytes'] ?? null)) {
                throw new InvalidArgumentException('The backup manifest contains an invalid file descriptor.');
            }
            $name = $descriptor['name'];
            $actual[] = $name;
            $file = $path.DIRECTORY_SEPARATOR.$name;
            if (! is_file($file)
                || filesize($file) !== $descriptor['bytes']
                || ! hash_equals($descriptor['sha256'], hash_file('sha256', $file))) {
                throw new InvalidArgumentException("Backup checksum is invalid: {$name}");
            }
        }
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('The backup manifest contains unexpected files.');
        }
    }
}
