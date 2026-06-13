<?php

namespace App\Models;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'library_root_id',
    'status',
    'trigger',
    'files_discovered',
    'files_processed',
    'files_added',
    'files_updated',
    'files_missing',
    'warning_count',
    'error_count',
    'started_at',
    'finished_at',
    'cancel_requested_at',
    'summary',
])]
class ScanRun extends Model
{
    /** @return BelongsTo<LibraryRoot, $this> */
    public function libraryRoot(): BelongsTo
    {
        return $this->belongsTo(LibraryRoot::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'trigger' => ScanTrigger::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }
}
