<?php

declare(strict_types=1);

use Sonotheque\Packaging\PackagedConfiguration;

require __DIR__.'/lib/PackagedConfiguration.php';

/** @return array{command: string, options: array<string, list<string>>} */
function parseArguments(array $arguments): array
{
    $command = $arguments[1] ?? '';
    $options = [];
    for ($index = 2; $index < count($arguments); $index++) {
        $argument = $arguments[$index];
        if (! str_starts_with($argument, '--')) {
            throw new InvalidArgumentException("Unexpected argument: {$argument}");
        }

        $name = substr($argument, 2);
        $value = 'true';
        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
        } elseif (isset($arguments[$index + 1]) && ! str_starts_with($arguments[$index + 1], '--')) {
            $value = $arguments[++$index];
        }
        $options[$name][] = $value;
    }

    return ['command' => $command, 'options' => $options];
}

/** @param array<string, list<string>> $options */
function option(array $options, string $name): ?string
{
    return $options[$name][0] ?? null;
}

try {
    $parsed = parseArguments($argv);
    $command = $parsed['command'];
    $options = $parsed['options'];
    $configuration = new PackagedConfiguration(dirname(__DIR__));

    switch ($command) {
        case 'init':
            $configuration->initialize(option($options, 'music-root'));
            break;
        case 'roots':
            $configuration->configureRoots(
                $options['root'] ?? [],
                option($options, 'case-insensitive') === 'true',
            );
            break;
        case 'get':
            fwrite(STDOUT, (string) $configuration->environmentValue(
                option($options, 'name') ?? throw new InvalidArgumentException('--name is required.'),
            ));
            break;
        case 'set':
            $configuration->setEnvironmentValue(
                option($options, 'name') ?? throw new InvalidArgumentException('--name is required.'),
                option($options, 'value') ?? throw new InvalidArgumentException('--value is required.'),
            );
            break;
        case 'network':
            $token = $configuration->configureNetwork(
                option($options, 'address') ?? throw new InvalidArgumentException('--address is required.'),
                integerOption($options, 'port', 8080),
                option($options, 'lan') === 'true',
                option($options, 'hostname') ?? '',
            );
            if ($token !== null) {
                fwrite(STDOUT, $token);
            }
            break;
        case 'host-identity':
            $configuration->configureHostIdentity(
                integerOption($options, 'uid'),
                integerOption($options, 'gid'),
                integerOption($options, 'docker-gid'),
            );
            break;
        case 'audio-intelligence':
            if (option($options, 'disable') === 'true') {
                $configuration->disableAudioIntelligence();
                break;
            }
            $configuration->configureAudioIntelligence(
                option($options, 'model') ?? throw new InvalidArgumentException('--model is required.'),
                option($options, 'accelerator') ?? 'cpu',
            );
            break;
        default:
            throw new InvalidArgumentException(
                'Usage: packaged-config.php init|roots|get|set|network|host-identity|audio-intelligence [options]',
            );
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Sonotheque configuration error: '.$exception->getMessage().PHP_EOL);
    exit(1);
}

/** @param array<string, list<string>> $options */
function integerOption(array $options, string $name, ?int $default = null): int
{
    $value = option($options, $name);
    if ($value === null && $default !== null) {
        return $default;
    }
    if ($value === null || preg_match('/^\d+$/', $value) !== 1) {
        throw new InvalidArgumentException("--{$name} must be a non-negative integer.");
    }

    return (int) $value;
}
