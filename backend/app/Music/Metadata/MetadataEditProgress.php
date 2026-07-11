<?php

namespace App\Music\Metadata;

use App\Models\MetadataEditJob;

class MetadataEditProgress
{
    public function refresh(MetadataEditJob $edit): void
    {
        $counts = $edit->items()
            ->selectRaw("count(*) filter (where status in ('completed', 'failed')) as processed")
            ->selectRaw("count(*) filter (where status = 'completed') as succeeded")
            ->selectRaw("count(*) filter (where status = 'failed') as failed")
            ->first();

        $edit->update([
            'processed_items' => (int) $counts->processed,
            'succeeded_items' => (int) $counts->succeeded,
            'failed_items' => (int) $counts->failed,
        ]);
    }
}
