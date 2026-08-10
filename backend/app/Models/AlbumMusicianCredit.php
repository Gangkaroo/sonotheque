<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'album_id',
    'track_id',
    'musician_id',
    'provider',
    'source_credit_key',
    'source_entity_type',
    'source_entity_reference',
    'relationship_type',
    'role',
    'credited_as',
    'attributes',
    'is_guest',
    'is_additional',
])]
class AlbumMusicianCredit extends Model
{
    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return BelongsTo<Musician, $this> */
    public function musician(): BelongsTo
    {
        return $this->belongsTo(Musician::class);
    }

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'is_guest' => 'boolean',
            'is_additional' => 'boolean',
        ];
    }
}
