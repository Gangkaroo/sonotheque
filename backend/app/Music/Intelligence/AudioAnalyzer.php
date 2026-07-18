<?php

namespace App\Music\Intelligence;

interface AudioAnalyzer
{
    public function health(): AudioAnalyzerHealth;

    /**
     * @param  list<array{
     *     itemId: int,
     *     path: string,
     *     durationSeconds: int|float|null
     * }>  $requests
     * @return list<AudioAnalyzerResult>
     */
    public function analyzeBatch(array $requests): array;
}
