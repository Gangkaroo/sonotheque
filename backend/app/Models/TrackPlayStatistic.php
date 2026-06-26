<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'track_id',
    'play_count',
    'first_played_at',
    'last_played_at',
    'source_metadata',
])]
class TrackPlayStatistic extends Model
{
    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    protected function casts(): array
    {
        return [
            'first_played_at' => 'immutable_datetime',
            'last_played_at' => 'immutable_datetime',
            'source_metadata' => 'array',
        ];
    }
}
