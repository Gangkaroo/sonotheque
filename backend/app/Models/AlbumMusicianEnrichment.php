<?php

namespace App\Models;

use App\Enums\OnlineContentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'album_id',
    'provider',
    'lookup_version',
    'status',
    'provider_release_id',
    'source_url',
    'fetched_at',
    'expires_at',
    'retry_after',
    'failure_count',
    'last_error_code',
    'candidate_releases',
    'selected_release_id',
    'related_discogs_release_ids',
])]
class AlbumMusicianEnrichment extends Model
{
    protected $primaryKey = 'album_id';

    public $incrementing = false;

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    protected function casts(): array
    {
        return [
            'lookup_version' => 'integer',
            'status' => OnlineContentStatus::class,
            'fetched_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'retry_after' => 'immutable_datetime',
            'failure_count' => 'integer',
            'candidate_releases' => 'array',
            'related_discogs_release_ids' => 'array',
        ];
    }
}
