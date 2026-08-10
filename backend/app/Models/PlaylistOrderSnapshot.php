<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'playlist_id',
    'item_ids',
    'source',
    'restored_at',
])]
class PlaylistOrderSnapshot extends Model
{
    /** @return BelongsTo<Playlist, $this> */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    protected function casts(): array
    {
        return [
            'item_ids' => 'array',
            'restored_at' => 'immutable_datetime',
        ];
    }
}
