<?php

namespace App\Console\Commands;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\LibraryRoot;
use App\Music\Scanning\LibraryScanner;
use App\Music\Scanning\ScanDispatcher;
use App\Music\Scanning\ScanDispatchException;
use Illuminate\Console\Command;

class ScanLibraryRoot extends Command
{
    protected $signature = 'music:scan
        {root : The library root ID}
        {--sync : Run immediately instead of dispatching to the queue}';

    protected $description = 'Scan a configured music-library root';

    public function handle(LibraryScanner $scanner, ScanDispatcher $dispatcher): int
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

        if ($this->option('sync')) {
            $scanRun = $root->scanRuns()->create([
                'status' => ScanStatus::Pending,
                'trigger' => ScanTrigger::Manual,
                'summary' => ['phase' => 'queued'],
            ]);
            $scanner->scan($scanRun);
            $this->info("Scan {$scanRun->id} completed.");
        } else {
            try {
                $scanRun = $dispatcher->dispatch($root);
            } catch (ScanDispatchException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
            $this->info("Scan {$scanRun->id} queued.");
        }

        return self::SUCCESS;
    }
}
