<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'library_root_id',
    'lookup_version',
    'status',
    'max_album_id',
    'last_album_id',
    'total_album_count',
    'processed_album_count',
    'ready_album_count',
    'not_found_album_count',
    'ambiguous_album_count',
    'failed_album_count',
    'processing_milliseconds',
    'last_error',
    'retry_after',
    'started_at',
    'finished_at',
    'pause_requested_at',
    'cancel_requested_at',
    'heartbeat_at',
])]
class MusicianCreditBackfillRun extends Model
{
    /** @return BelongsTo<LibraryRoot, $this> */
    public function libraryRoot(): BelongsTo
    {
        return $this->belongsTo(LibraryRoot::class);
    }

    protected function casts(): array
    {
        return [
            'lookup_version' => 'integer',
            'max_album_id' => 'integer',
            'last_album_id' => 'integer',
            'total_album_count' => 'integer',
            'processed_album_count' => 'integer',
            'ready_album_count' => 'integer',
            'not_found_album_count' => 'integer',
            'ambiguous_album_count' => 'integer',
            'failed_album_count' => 'integer',
            'processing_milliseconds' => 'integer',
            'retry_after' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'pause_requested_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
        ];
    }
}
