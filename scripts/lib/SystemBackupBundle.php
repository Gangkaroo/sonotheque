<?php

declare(strict_types=1);

namespace Sonotheque\Packaging;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class SystemBackupBundle
{
    public const VERSION = 1;

    /** @var list<string> */
    private const FILES = ['database.dump', 'storage.tar', 'app-key.txt'];

    public function create(string $bundlePath, string $mode, string $database): void
    {
        $bundlePath = $this->validatedDirectory($bundlePath);
        $files = [];
        foreach (self::FILES as $name) {
            $path = $bundlePath.'/'.$name;
            if (! is_file($path)) {
                throw new InvalidArgumentException("Backup file is missing: {$name}");
            }

            $bytes = filesize($path);
            $hash = hash_file('sha256', $path);
            if ($bytes === false || $hash === false) {
                throw new RuntimeException("Backup file could not be inspected: {$name}");
            }
            $files[] = ['name' => $name, 'bytes' => $bytes, 'sha256' => $hash];
        }

        $manifest = json_encode([
            'version' => self::VERSION,
            'createdAt' => gmdate('c'),
            'mode' => $this->validatedMode($mode),
            'database' => $database,
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($bundlePath.'/manifest.json', $manifest.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('The backup manifest could not be written.');
        }
    }

    /** @return array{version: int, createdAt: string, mode: string, database: string, files: list<array{name: string, bytes: int, sha256: string}>} */
    public function validate(string $bundlePath, ?string $expectedMode = null): array
    {
        $bundlePath = $this->validatedDirectory($bundlePath);
        $manifestPath = $bundlePath.'/manifest.json';
        if (! is_file($manifestPath)) {
            throw new InvalidArgumentException('The backup bundle does not contain manifest.json.');
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The backup manifest is not valid JSON.', previous: $exception);
        }
        if (! is_array($manifest) || ($manifest['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported backup bundle version.');
        }

        $mode = $this->validatedMode((string) ($manifest['mode'] ?? ''));
        if ($expectedMode !== null && $mode !== $this->validatedMode($expectedMode)) {
            throw new InvalidArgumentException("This is a {$mode} backup, but restore mode is {$expectedMode}.");
        }

        $descriptors = $manifest['files'] ?? null;
        if (! is_array($descriptors) || count($descriptors) !== count(self::FILES)) {
            throw new InvalidArgumentException('The backup manifest has an invalid file list.');
        }

        $validatedFiles = [];
        foreach ($descriptors as $descriptor) {
            if (! is_array($descriptor)) {
                throw new InvalidArgumentException('The backup manifest has an invalid file descriptor.');
            }
            $name = (string) ($descriptor['name'] ?? '');
            if (! in_array($name, self::FILES, true) || isset($validatedFiles[$name])) {
                throw new InvalidArgumentException("Unexpected backup file descriptor: {$name}");
            }

            $path = $bundlePath.'/'.$name;
            if (! is_file($path)) {
                throw new InvalidArgumentException("Backup file is missing: {$name}");
            }
            $expectedBytes = filter_var($descriptor['bytes'] ?? null, FILTER_VALIDATE_INT);
            $expectedHash = strtolower((string) ($descriptor['sha256'] ?? ''));
            $actualBytes = filesize($path);
            $actualHash = hash_file('sha256', $path);
            if ($expectedBytes === false || $actualBytes !== $expectedBytes || $actualHash !== $expectedHash) {
                throw new InvalidArgumentException("Backup checksum is invalid: {$name}");
            }

            $validatedFiles[$name] = [
                'name' => $name,
                'bytes' => $actualBytes,
                'sha256' => $actualHash,
            ];
        }

        foreach (self::FILES as $name) {
            if (! isset($validatedFiles[$name])) {
                throw new InvalidArgumentException("Backup file descriptor is missing: {$name}");
            }
        }

        return [
            'version' => self::VERSION,
            'createdAt' => (string) ($manifest['createdAt'] ?? ''),
            'mode' => $mode,
            'database' => (string) ($manifest['database'] ?? ''),
            'files' => array_values($validatedFiles),
        ];
    }

    private function validatedDirectory(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException("Backup directory does not exist: {$path}");
        }

        return str_replace('\\', '/', $resolved);
    }

    private function validatedMode(string $mode): string
    {
        if (! in_array($mode, ['Development', 'Packaged'], true)) {
            throw new InvalidArgumentException("Unsupported backup mode: {$mode}");
        }

        return $mode;
    }
}
