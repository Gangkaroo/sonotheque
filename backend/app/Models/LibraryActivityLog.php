<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'library_root_id',
    'scan_run_id',
    'source',
    'severity',
    'code',
    'message',
    'path',
    'occurrence_count',
    'context',
])]
class LibraryActivityLog extends Model
{
    /** @return BelongsTo<LibraryRoot, $this> */
    public function libraryRoot(): BelongsTo
    {
        return $this->belongsTo(LibraryRoot::class);
    }

    /** @return BelongsTo<ScanRun, $this> */
    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class);
    }

    protected function casts(): array
    {
        return [
            'occurrence_count' => 'integer',
            'context' => 'array',
        ];
    }
}
