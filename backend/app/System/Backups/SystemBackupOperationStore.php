<?php

namespace App\System\Backups;

use Illuminate\Support\Str;
use RuntimeException;

class SystemBackupOperationStore
{
    /** @return array<string, mixed> */
    public function create(string $type, array $attributes = []): array
    {
        $operation = array_merge([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'status' => 'queued',
            'progress' => 0,
            'phase' => null,
            'message' => null,
            'archiveName' => null,
            'archivePath' => null,
            'createdAt' => now()->toJSON(),
            'startedAt' => null,
            'finishedAt' => null,
        ], $attributes);
        $this->write($operation);

        return $operation;
    }

    /** @return array<string, mixed> */
    public function find(string $id): array
    {
        $path = $this->path($id);
        if (! is_file($path)) {
            throw new RuntimeException('The backup operation could not be found.');
        }

        $operation = json_decode(
            (string) file_get_contents($path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (! is_array($operation)) {
            throw new RuntimeException('The backup operation status is invalid.');
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    public function update(string $id, array $attributes): array
    {
        $operation = array_merge($this->find($id), $attributes);
        $this->write($operation);

        return $operation;
    }

    /** @param array<string, mixed> $operation */
    private function write(array $operation): void
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('The backup operation directory could not be created.');
        }

        $path = $this->path((string) $operation['id']);
        $temporaryPath = $path.'.tmp-'.bin2hex(random_bytes(6));
        $written = file_put_contents(
            $temporaryPath,
            json_encode($operation, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
        if ($written === false || ! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('The backup operation status could not be written.');
        }
    }

    private function path(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('The backup operation identifier is invalid.');
        }

        return $this->directory().DIRECTORY_SEPARATOR.$id.'.json';
    }

    private function directory(): string
    {
        return (string) config('sonotheque.system_backups.operation_path');
    }
}
