<?php

namespace App\Support;

use App\Models\MetadataBackup;
use App\Models\MetadataEditItem;
use App\Models\MetadataEditJob;
use App\Music\Metadata\MetadataBackupManager;

class MetadataEditPayloads
{
    public function __construct(private readonly MetadataBackupManager $backups)
    {
    }

    /** @return array<string, mixed> */
    public function job(MetadataEditJob $job): array
    {
        $job->loadMissing(['backup', 'items.backup']);

        return [
            'id' => $job->id,
            'type' => $job->type,
            'trackId' => $job->track_id,
            'albumId' => $job->album_id,
            'status' => $job->status,
            'preview' => $job->preview,
            'error' => $job->error,
            'failureReason' => $this->failureReason($job),
            'backup' => $this->backup($job->backup),
            'totalItems' => $job->total_items,
            'processedItems' => $job->processed_items,
            'succeededItems' => $job->succeeded_items,
            'failedItems' => $job->failed_items,
            'items' => $job->items->map(fn (MetadataEditItem $item) => [
                'id' => $item->id,
                'trackId' => $item->track_id,
                'status' => $item->status,
                'file' => $item->preview['file'] ?? null,
                'trackTitle' => $item->preview['trackTitle'] ?? null,
                'error' => $item->error,
                'backup' => $this->backup($item->backup),
            ])->values(),
            'createdAt' => $job->created_at?->toJSON(),
            'startedAt' => $job->started_at?->toJSON(),
            'finishedAt' => $job->finished_at?->toJSON(),
        ];
    }

    private function failureReason(MetadataEditJob $job): ?string
    {
        $itemError = $job->items
            ->first(fn (MetadataEditItem $item): bool => $item->status === 'failed' && filled($item->error))
            ?->error;

        return $itemError ?? $job->error;
    }

    /** @return array<string, mixed>|null */
    private function backup(?MetadataBackup $backup): ?array
    {
        if ($backup === null) {
            return null;
        }

        return [
            'id' => $backup->id,
            'path' => $this->backups->absolutePath($backup),
            'fileSize' => $backup->file_size,
            'checksum' => $backup->checksum,
            'expiresAt' => $backup->expires_at?->toJSON(),
            'restoredAt' => $backup->restored_at?->toJSON(),
            'deletedAt' => $backup->deleted_at?->toJSON(),
        ];
    }
}
