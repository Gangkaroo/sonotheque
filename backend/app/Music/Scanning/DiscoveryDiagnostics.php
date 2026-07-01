<?php

namespace App\Music\Scanning;

class DiscoveryDiagnostics
{
    private const READ_FAILURE_CODES = [
        'unreadable_directory',
        'unreadable_entry',
        'unreadable_file',
    ];

    /** @var array<string, array{count: int, message: string, path: ?string}> */
    private array $warnings = [];

    /** @var array<string, true> */
    private array $pathsRequiringPreservation = [];

    private bool $hasUnscopedReadFailure = false;

    public function record(string $code, string $message, ?string $path = null): void
    {
        if (! isset($this->warnings[$code])) {
            $this->warnings[$code] = ['count' => 0, 'message' => $message, 'path' => $path];
        }

        $this->warnings[$code]['count']++;

        if (in_array($code, self::READ_FAILURE_CODES, true)) {
            if ($path === null || $path === '') {
                $this->hasUnscopedReadFailure = true;
            } else {
                $this->pathsRequiringPreservation[$path] = true;
            }
        }
    }

    public function warningCount(): int
    {
        return array_sum(array_column($this->warnings, 'count'));
    }

    /** @return list<array{code: string, severity: string, message: string, path: ?string, count: int}> */
    public function issues(): array
    {
        $issues = [];

        foreach ($this->warnings as $code => $warning) {
            $issues[] = [
                'code' => $code,
                'severity' => 'warning',
                'message' => $warning['message'],
                'path' => $warning['path'],
                'count' => $warning['count'],
            ];
        }

        return $issues;
    }

    /** @return list<string>|null Null indicates that the read failure could not be scoped safely. */
    public function pathsRequiringPreservation(): ?array
    {
        return $this->hasUnscopedReadFailure
            ? null
            : array_keys($this->pathsRequiringPreservation);
    }
}
