<?php

namespace App\Support;

use App\Models\MetadataEditItem;
use App\Models\MetadataEditJob;

class MetadataEditPayloads
{
    /** @return array<string, mixed> */
    public function job(MetadataEditJob $job): array
    {
        $job->loadMissing('items');

        return [
            'id' => $job->id,
            'type' => $job->type,
            'trackId' => $job->track_id,
            'albumId' => $job->album_id,
            'status' => $job->status,
            'preview' => $job->preview,
            'error' => $job->error,
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
            ])->values(),
            'createdAt' => $job->created_at?->toJSON(),
            'startedAt' => $job->started_at?->toJSON(),
            'finishedAt' => $job->finished_at?->toJSON(),
        ];
    }
}
