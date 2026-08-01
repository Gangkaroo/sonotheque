<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'album_id',
    'musician_id',
    'role',
    'credited_as',
    'is_guest',
    'is_additional',
])]
class ManualAlbumMusicianCredit extends Model
{
    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<Musician, $this> */
    public function musician(): BelongsTo
    {
        return $this->belongsTo(Musician::class);
    }

    /** @return BelongsToMany<Track, $this> */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(
            Track::class,
            'manual_album_musician_credit_track',
        );
    }

    protected function casts(): array
    {
        return [
            'is_guest' => 'boolean',
            'is_additional' => 'boolean',
        ];
    }
}
