<?php

namespace App\Models;

use App\Enums\MusicianReviewDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'album_id',
    'lookup_version',
    'decision',
    'reviewed_at',
])]
class AlbumMusicianReview extends Model
{
    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    protected function casts(): array
    {
        return [
            'lookup_version' => 'integer',
            'decision' => MusicianReviewDecision::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
