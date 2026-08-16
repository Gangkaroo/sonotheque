<?php

declare(strict_types=1);

namespace Sonotheque\Packaging;

use InvalidArgumentException;
use RuntimeException;

final class PackagedConfiguration
{
    public function __construct(private readonly string $repositoryRoot) {}

    public function initialize(?string $musicRoot = null): void
    {
        $created = ! is_file($this->environmentPath());
        $this->ensureEnvironment();
        if ($created) {
            fwrite(STDOUT, "Created .env.packaged from .env.packaged.example.\n");
        }

        if (trim((string) $this->environmentValue('APP_KEY')) === '') {
            $this->setEnvironmentValue('APP_KEY', 'base64:'.base64_encode(random_bytes(32)));
            fwrite(STDOUT, "Generated APP_KEY for packaged mode.\n");
        }

        $password = $this->environmentValue('POSTGRES_PASSWORD');
        if ($password === null || trim($password) === '' || $password === 'change-this-local-password') {
            $this->setEnvironmentValue('POSTGRES_PASSWORD', 'sonotheque_'.bin2hex(random_bytes(24)));
            fwrite(STDOUT, "Generated PostgreSQL password for packaged mode.\n");
        }

        if ($musicRoot !== null && trim($musicRoot) !== '') {
            $this->setEnvironmentValue('SONOTHEQUE_ROOT_1', trim($musicRoot));
        }
    }

    public function environmentValue(string $name): ?string
    {
        if (! is_file($this->environmentPath())) {
            return null;
        }

        $pattern = '/^\s*'.preg_quote($name, '/').'\s*=\s*(.*)$/';
        foreach (file($this->environmentPath(), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }

            $value = trim($matches[1]);
            if (strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))) {
                return substr($value, 1, -1);
            }

            return trim((string) preg_replace('/\s+#.*$/', '', $value));
        }

        return null;
    }

    public function setEnvironmentValue(string $name, string $value): void
    {
        $this->ensureEnvironment();
        if ($name === '' || preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid environment variable name: {$name}");
        }
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException('Environment values must not contain line breaks.');
        }

        $lines = file($this->environmentPath(), FILE_IGNORE_NEW_LINES) ?: [];
        $pattern = '/^\s*'.preg_quote($name, '/').'\s*=/';
        $replacement = $name.'='.$value;
        $updated = false;
        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line) !== 1) {
                continue;
            }

            $lines[$index] = $replacement;
            $updated = true;
            break;
        }
        if (! $updated) {
            $lines[] = $replacement;
        }

        $this->writeFile($this->environmentPath(), implode(PHP_EOL, $lines).PHP_EOL);
    }

    public function configureNetwork(string $address, int $port, bool $lan, string $hostname): ?string
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The HTTP port must be between 1 and 65535.');
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException("Invalid IPv4 address: {$address}");
        }
        if ($lan && ! $this->isPrivateIpv4Address($address)) {
            throw new InvalidArgumentException("LAN address '{$address}' is not a private IPv4 address.");
        }
        if ($hostname !== '' && preg_match('/^[A-Za-z0-9.-]+$/', $hostname) !== 1) {
            throw new InvalidArgumentException("Invalid host name: {$hostname}");
        }

        $this->setEnvironmentValue('APP_HTTP_BIND', $address);
        $this->setEnvironmentValue('APP_URL', "http://{$address}:{$port}");
        $this->setEnvironmentValue('SONOTHEQUE_LAN_ENABLED', $lan ? 'true' : 'false');
        $this->setEnvironmentValue('SONOTHEQUE_LOCAL_PROXY_ENABLED', $lan ? 'false' : 'true');

        $trustedHosts = ['localhost', '127.0.0.1', '::1'];
        if ($lan) {
            $trustedHosts[] = $address;
            if ($hostname !== '') {
                $trustedHosts[] = $hostname;
            }
        }
        $this->setEnvironmentValue('SONOTHEQUE_TRUSTED_HOSTS', implode(',', $trustedHosts));

        if (! $lan) {
            return null;
        }

        $adminToken = $this->environmentValue('SONOTHEQUE_ADMIN_TOKEN');
        if ($adminToken === null || strlen(trim($adminToken)) < 32) {
            $adminToken = bin2hex(random_bytes(24));
            $this->setEnvironmentValue('SONOTHEQUE_ADMIN_TOKEN', $adminToken);
        }

        return $adminToken;
    }

    public function configureHostIdentity(int $userId, int $groupId, int $dockerGroupId): void
    {
        if ($userId < 0 || $groupId < 0 || $dockerGroupId < 0) {
            throw new InvalidArgumentException('Host user and group IDs must not be negative.');
        }

        $this->setEnvironmentValue('SONOTHEQUE_HOST_UID', (string) $userId);
        $this->setEnvironmentValue('SONOTHEQUE_HOST_GID', (string) $groupId);
        $this->setEnvironmentValue('SONOTHEQUE_DOCKER_GID', (string) $dockerGroupId);
    }

    public function configureAudioIntelligence(string $modelPath, string $accelerator): void
    {
        $modelPath = str_replace('\\', '/', trim($modelPath));
        if ($modelPath === '' || strtolower(pathinfo($modelPath, PATHINFO_EXTENSION)) !== 'pb') {
            throw new InvalidArgumentException('Select a reviewed TensorFlow .pb model file.');
        }
        if (! in_array($accelerator, ['cpu', 'cuda'], true)) {
            throw new InvalidArgumentException('Audio Intelligence accelerator must be cpu or cuda.');
        }

        $directory = dirname($modelPath);
        $filename = basename($modelPath);
        $cpuImage = 'sonotheque-audio-intelligence:analysis';
        $cudaImage = 'sonotheque-audio-intelligence:cuda';

        $this->setEnvironmentValue('AUDIO_INTELLIGENCE_MODEL_DIRECTORY', $directory);
        $this->setEnvironmentValue('AUDIO_INTELLIGENCE_MODEL_FILENAME', $filename);
        $this->setEnvironmentValue(
            'AUDIO_INTELLIGENCE_DOCKER_IMAGE',
            $accelerator === 'cuda' ? $cudaImage : $cpuImage,
        );
        $this->setEnvironmentValue('AUDIO_INTELLIGENCE_BENCHMARK_CPU_IMAGE', $cpuImage);
        $this->setEnvironmentValue('AUDIO_INTELLIGENCE_BENCHMARK_CUDA_IMAGE', $cudaImage);
        $this->setEnvironmentValue('AUDIO_INTELLIGENCE_ACCELERATOR', $accelerator);
        $this->setEnvironmentValue('AUDIO_INTELLIGENCE_PERSISTENT', 'true');
        $this->setEnvironmentValue('AUDIO_INTELLIGENCE_MOUNT_SOURCE_CONTAINER', 'self');
        $this->setEnvironmentValue('AUDIO_INTELLIGENCE_HEALTH_VIA_QUEUE', 'true');
        $this->setEnvironmentValue('AUDIO_INTELLIGENCE_DRIVER', 'essentia_docker');
    }

    public function disableAudioIntelligence(): void
    {
        $this->setEnvironmentValue('AUDIO_INTELLIGENCE_DRIVER', 'none');
    }

    /** @param list<string> $roots */
    public function configureRoots(array $roots, bool $caseInsensitive = false): void
    {
        $normalizedRoots = [];
        foreach ($roots as $root) {
            $root = trim($root);
            if ($root === '' || str_contains($root, "\n") || str_contains($root, "\r")) {
                throw new InvalidArgumentException('Music root paths must not be empty or contain line breaks.');
            }

            $comparisonRoot = $this->comparisonPath($root, $caseInsensitive);
            foreach ($normalizedRoots as $existing) {
                $existingComparison = $existing['comparison'];
                if ($comparisonRoot === $existingComparison) {
                    continue 2;
                }
                if (str_starts_with($comparisonRoot.'/', $existingComparison.'/')
                    || str_starts_with($existingComparison.'/', $comparisonRoot.'/')) {
                    throw new InvalidArgumentException(
                        "Music roots must not overlap: '{$root}' and '{$existing['path']}'.",
                    );
                }
            }

            $normalizedRoots[] = ['path' => $root, 'comparison' => $comparisonRoot];
        }

        if ($normalizedRoots === []) {
            throw new InvalidArgumentException('At least one music folder must be configured.');
        }

        $this->initialize($normalizedRoots[0]['path']);
        $rootEntries = [];
        foreach ($normalizedRoots as $index => $root) {
            $rootEntries[] = [
                'hostPath' => $root['path'],
                'containerPath' => '/music/root-'.($index + 1),
            ];
        }

        $this->writeFile($this->rootsPath(), json_encode([
            'version' => 1,
            'roots' => $rootEntries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        $this->writeFile($this->composeOverridePath(), $this->composeOverride($rootEntries));
    }

    private function ensureEnvironment(): void
    {
        if (is_file($this->environmentPath())) {
            return;
        }
        if (! is_file($this->environmentExamplePath())
            || ! copy($this->environmentExamplePath(), $this->environmentPath())) {
            throw new RuntimeException('The packaged environment could not be created.');
        }
    }

    private function comparisonPath(string $path, bool $caseInsensitive): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('/^[A-Za-z]:\/$/', $normalized) !== 1 && $normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        return $caseInsensitive ? strtolower($normalized) : $normalized;
    }

    private function isPrivateIpv4Address(string $address): bool
    {
        $parts = array_map('intval', explode('.', $address));

        return $parts[0] === 10
            || ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31)
            || ($parts[0] === 192 && $parts[1] === 168);
    }

    /** @param list<array{hostPath: string, containerPath: string}> $roots */
    private function composeOverride(array $roots): string
    {
        $lines = [
            '# Generated by Sonotheque packaged configuration. Do not edit while Sonotheque is running.',
            'services:',
        ];
        foreach ([
            'migrate',
            'backend',
            'queue-default',
            'queue-scans',
            'queue-analysis',
            'queue-analysis-ai',
            'scheduler',
        ] as $service) {
            $lines[] = "  {$service}:";
            $lines[] = '    volumes:';
            foreach ($roots as $root) {
                $lines[] = '      - type: bind';
                $lines[] = "        source: '".str_replace("'", "''", $root['hostPath'])."'";
                $lines[] = '        target: '.$root['containerPath'];
                if ($service === 'queue-analysis-ai') {
                    $lines[] = '        read_only: true';
                }
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function writeFile(string $path, string $contents): void
    {
        $temporaryPath = $path.'.tmp-'.bin2hex(random_bytes(6));
        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException("The configuration file could not be written: {$path}");
        }

        if (@rename($temporaryPath, $path)) {
            return;
        }

        // Windows cannot atomically replace an existing file with rename().
        if (! copy($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException("The configuration file could not be written: {$path}");
        }
        @unlink($temporaryPath);
    }

    private function environmentPath(): string
    {
        return $this->repositoryRoot.'/.env.packaged';
    }

    private function environmentExamplePath(): string
    {
        return $this->repositoryRoot.'/.env.packaged.example';
    }

    private function rootsPath(): string
    {
        return $this->repositoryRoot.'/packaged-roots.json';
    }

    private function composeOverridePath(): string
    {
        return $this->repositoryRoot.'/compose.packaged.override.yaml';
    }
}
