<?php

namespace App\Console\Commands;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanLibraryRoot as ScanLibraryRootJob;
use App\Models\LibraryRoot;
use App\Music\Scanning\LibraryScanner;
use Illuminate\Console\Command;

class ScanLibraryRoot extends Command
{
    protected $signature = 'music:scan
        {root : The library root ID}
        {--sync : Run immediately instead of dispatching to the queue}';

    protected $description = 'Scan a configured music-library root';

    public function handle(LibraryScanner $scanner): int
    {
        $root = LibraryRoot::find($this->argument('root'));

        if ($root === null) {
            $this->error('The requested library root does not exist.');

            return self::FAILURE;
        }

        if (! $root->enabled) {
            $this->error('The requested library root is disabled.');

            return self::FAILURE;
        }

        $scanRun = $root->scanRuns()->create([
            'status' => ScanStatus::Pending,
            'trigger' => ScanTrigger::Manual,
            'summary' => ['phase' => 'queued'],
        ]);

        if ($this->option('sync')) {
            $scanner->scan($scanRun);
            $this->info("Scan {$scanRun->id} completed.");
        } else {
            ScanLibraryRootJob::dispatch($scanRun->id);
            $this->info("Scan {$scanRun->id} queued.");
        }

        return self::SUCCESS;
    }
}
