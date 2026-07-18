<?php

namespace App\Models;

use App\Enums\MediaFileStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'library_root_id',
    'album_id',
    'relative_path',
    'relative_path_hash',
    'file_size',
    'modified_at',
    'mime_type',
    'container',
    'codec',
    'bitrate',
    'sample_rate',
    'channels',
    'status',
    'last_seen_at',
    'scan_error',
    'raw_metadata',
    'metadata_parser_version',
    'content_fingerprint',
    'content_fingerprint_version',
])]
class MediaFile extends Model
{
    /** @return BelongsTo<LibraryRoot, $this> */
    public function libraryRoot(): BelongsTo
    {
        return $this->belongsTo(LibraryRoot::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return HasOne<Track, $this> */
    public function track(): HasOne
    {
        return $this->hasOne(Track::class);
    }

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'modified_at' => 'immutable_datetime',
            'status' => MediaFileStatus::class,
            'last_seen_at' => 'immutable_datetime',
            'raw_metadata' => 'array',
            'metadata_parser_version' => 'integer',
            'content_fingerprint_version' => 'integer',
        ];
    }
}
