<?php

declare(strict_types=1);

use Sonotheque\Packaging\SystemBackupBundle;

require __DIR__.'/lib/SystemBackupBundle.php';

/** @return array<string, string> */
function backupOptions(array $arguments): array
{
    $options = [];
    foreach (array_slice($arguments, 2) as $argument) {
        if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
            throw new InvalidArgumentException("Expected --name=value, received: {$argument}");
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
    }

    return $options;
}

try {
    $command = $argv[1] ?? '';
    $options = backupOptions($argv);
    $path = $options['path'] ?? throw new InvalidArgumentException('--path is required.');
    $bundle = new SystemBackupBundle;

    if ($command === 'create') {
        $bundle->create(
            $path,
            $options['mode'] ?? 'Packaged',
            $options['database'] ?? 'sonotheque',
        );
    } elseif ($command === 'validate') {
        $bundle->validate($path, $options['mode'] ?? null);
    } else {
        throw new InvalidArgumentException('Usage: system-backup-bundle.php create|validate --path=PATH [--mode=MODE]');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Sonotheque backup error: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
