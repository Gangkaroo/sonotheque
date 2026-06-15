<?php

namespace App\Music\Scanning;

class DiscoveryDiagnostics
{
    /** @var array<string, array{count: int, message: string, path: ?string}> */
    private array $warnings = [];

    public function record(string $code, string $message, ?string $path = null): void
    {
        if (! isset($this->warnings[$code])) {
            $this->warnings[$code] = ['count' => 0, 'message' => $message, 'path' => $path];
        }

        $this->warnings[$code]['count']++;
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
}
