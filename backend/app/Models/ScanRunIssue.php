<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'scan_run_id',
    'code',
    'severity',
    'message',
    'path',
    'occurrence_count',
])]
class ScanRunIssue extends Model
{
    /** @return BelongsTo<ScanRun, $this> */
    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class);
    }

    protected function casts(): array
    {
        return [
            'occurrence_count' => 'integer',
        ];
    }
}
