<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'library_id',
    'name',
    'path',
    'path_hash',
    'cover_image_path',
    'enabled',
    'include_patterns',
    'exclude_patterns',
    'last_scanned_at',
])]
class LibraryRoot extends Model
{
    /** @return BelongsTo<Library, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    /** @return HasMany<ScanRun, $this> */
    public function scanRuns(): HasMany
    {
        return $this->hasMany(ScanRun::class);
    }

    /** @return HasMany<Album, $this> */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    /** @return HasMany<MediaFile, $this> */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'include_patterns' => 'array',
            'exclude_patterns' => 'array',
            'last_scanned_at' => 'immutable_datetime',
        ];
    }
}
