<?php

namespace App\Jobs;

use App\Models\ScanRun;
use App\Music\Scanning\LibraryScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanLibraryRoot implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $scanRunId) {}

    public function handle(LibraryScanner $scanner): void
    {
        $scanner->scan(ScanRun::findOrFail($this->scanRunId));
    }
}
