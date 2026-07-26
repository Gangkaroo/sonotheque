<?php

namespace App\Music\Intelligence;

interface AudioAnalyzer
{
    public function health(): AudioAnalyzerHealth;

    public function shutdown(): void;

    /**
     * @param  list<array{
     *     itemId: int,
     *     path: string,
     *     durationSeconds: int|float|null,
     *     libraryRootPath?: string,
     *     relativePath?: string
     * }>  $requests
     * @return list<AudioAnalyzerResult>
     */
    public function analyzeBatch(array $requests): array;
}
