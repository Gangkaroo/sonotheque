<?php

namespace App\Console\Commands;

use App\Models\MetadataBackup;
use App\Music\Metadata\MetadataBackupManager;
use Illuminate\Console\Command;
use Throwable;

class RestoreMetadataBackup extends Command
{
    protected $signature = 'music:metadata-backups:restore
        {backup : The metadata backup record ID}
        {--force : Restore without an interactive confirmation}';

    protected $description = 'Restore an audio file from a retained metadata backup';

    public function handle(MetadataBackupManager $backups): int
    {
        $backup = MetadataBackup::find($this->argument('backup'));
        if ($backup === null) {
            $this->error('The requested metadata backup does not exist.');

            return self::FAILURE;
        }

        $this->line("Source: {$backup->source_relative_path}");
        $this->line("Backup: {$backups->absolutePath($backup)}");
        if (! $this->option('force')
            && ! $this->confirm('Replace the current audio file with this backup?', false)) {
            $this->warn('Restore cancelled.');

            return self::SUCCESS;
        }

        try {
            $target = $backups->restore($backup);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored [{$target}]. Run a library rescan to refresh catalog metadata.");

        return self::SUCCESS;
    }
}
