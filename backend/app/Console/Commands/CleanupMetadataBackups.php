<?php

namespace App\Console\Commands;

use App\Music\Metadata\MetadataBackupManager;
use Illuminate\Console\Command;

class CleanupMetadataBackups extends Command
{
    protected $signature = 'music:metadata-backups:cleanup';

    protected $description = 'Remove expired metadata backup files while retaining their audit records';

    public function handle(MetadataBackupManager $backups): int
    {
        $count = $backups->cleanupExpired();
        $this->info("Removed {$count} expired metadata backup file(s).");

        return self::SUCCESS;
    }
}
