<?php

namespace App\System\Backups;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PostgresBackupClient
{
    public function dump(string $destination): void
    {
        if ($this->usesDocker()) {
            $this->dumpWithDocker($destination);

            return;
        }

        $process = new Process([
            $this->executable('pg_dump'),
            '--host', (string) config('database.connections.pgsql.host'),
            '--port', (string) config('database.connections.pgsql.port'),
            '--username', (string) config('database.connections.pgsql.username'),
            '--dbname', (string) config('database.connections.pgsql.database'),
            '--format=custom',
            '--file', $destination,
        ], env: $this->environment());
        $this->run($process, 'PostgreSQL could not create the database backup.');
    }

    public function restore(string $source): void
    {
        if ($this->usesDocker()) {
            $this->restoreWithDocker($source);

            return;
        }

        $process = new Process([
            $this->executable('pg_restore'),
            '--host', (string) config('database.connections.pgsql.host'),
            '--port', (string) config('database.connections.pgsql.port'),
            '--username', (string) config('database.connections.pgsql.username'),
            '--dbname', (string) config('database.connections.pgsql.database'),
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            $source,
        ], env: $this->environment());
        $this->run($process, 'PostgreSQL could not restore the database backup.');
    }

    private function dumpWithDocker(string $destination): void
    {
        $containerPath = '/tmp/sonotheque-ui-backup-'.bin2hex(random_bytes(8)).'.dump';
        $container = $this->container();

        try {
            $this->run(new Process([
                'docker', 'exec', '-e', 'PGPASSWORD='.$this->password(), $container,
                'pg_dump', '-U', $this->username(), '-d', $this->database(),
                '--format=custom', '--file', $containerPath,
            ]), 'PostgreSQL could not create the database backup.');
            $this->run(
                new Process(['docker', 'cp', $container.':'.$containerPath, $destination]),
                'The database backup could not be copied from PostgreSQL.',
            );
            $this->run(
                new Process(['docker', 'exec', $container, 'pg_restore', '--list', $containerPath]),
                'The database backup could not be verified.',
            );
        } finally {
            (new Process(['docker', 'exec', $container, 'rm', '-f', $containerPath]))->run();
        }
    }

    private function restoreWithDocker(string $source): void
    {
        $containerPath = '/tmp/sonotheque-ui-restore-'.bin2hex(random_bytes(8)).'.dump';
        $container = $this->container();

        try {
            $this->run(
                new Process(['docker', 'cp', $source, $container.':'.$containerPath]),
                'The database backup could not be copied to PostgreSQL.',
            );
            $this->run(
                new Process(['docker', 'exec', $container, 'pg_restore', '--list', $containerPath]),
                'The database backup could not be verified.',
            );
            $this->run(new Process([
                'docker', 'exec', '-e', 'PGPASSWORD='.$this->password(), $container,
                'pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges',
                '-U', $this->username(), '-d', $this->database(), $containerPath,
            ]), 'PostgreSQL could not restore the database backup.');
        } finally {
            (new Process(['docker', 'exec', $container, 'rm', '-f', $containerPath]))->run();
        }
    }

    private function usesDocker(): bool
    {
        return (bool) config('sonotheque.system_backups.use_docker');
    }

    private function container(): string
    {
        return (string) config('sonotheque.system_backups.postgres_container');
    }

    private function executable(string $name): string
    {
        $configured = (string) config("sonotheque.system_backups.{$name}_path", '');
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $found = (new ExecutableFinder())->find($name);
        if ($found !== null) {
            return $found;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $matches = glob('C:/Program Files/PostgreSQL/*/bin/'.$name.'.exe') ?: [];
            natsort($matches);
            $found = array_pop($matches);
            if (is_string($found)) {
                return $found;
            }
        }

        throw new RuntimeException("{$name} was not found. Install the PostgreSQL client tools or configure its path.");
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return ['PGPASSWORD' => $this->password()];
    }

    private function database(): string
    {
        return (string) config('database.connections.pgsql.database');
    }

    private function username(): string
    {
        return (string) config('database.connections.pgsql.username');
    }

    private function password(): string
    {
        return (string) config('database.connections.pgsql.password');
    }

    private function run(Process $process, string $message): void
    {
        $process->setTimeout(null);
        $process->run();
        if (! $process->isSuccessful()) {
            $detail = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException($detail === '' ? $message : $message.' '.$detail);
        }
    }
}
