<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'album_id',
    'source_type',
    'owned_album_copy_id',
    'release_id',
    'source_url',
    'fetched_at',
])]
class AlbumDiscogsMusicianSource extends Model
{
    public const SOURCE_MUSICBRAINZ = 'musicbrainz';

    public const SOURCE_OWNED_COPY = 'owned_copy';

    protected $primaryKey = 'album_id';

    public $incrementing = false;

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<OwnedAlbumCopy, $this> */
    public function ownedCopy(): BelongsTo
    {
        return $this->belongsTo(OwnedAlbumCopy::class, 'owned_album_copy_id');
    }

    protected function casts(): array
    {
        return [
            'release_id' => 'integer',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
