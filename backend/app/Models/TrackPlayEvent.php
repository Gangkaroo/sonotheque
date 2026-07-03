<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'track_id',
    'media_file_id',
    'played_at',
    'listened_ms',
    'duration_ms',
    'counted',
    'source',
    'context',
    'session_key',
    'lastfm_status',
    'lastfm_attempts',
    'lastfm_scrobbled_at',
    'lastfm_error',
    'lastfm_ignored_code',
])]
class TrackPlayEvent extends Model
{
    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    protected function casts(): array
    {
        return [
            'played_at' => 'immutable_datetime',
            'counted' => 'boolean',
            'lastfm_scrobbled_at' => 'immutable_datetime',
        ];
    }
}
