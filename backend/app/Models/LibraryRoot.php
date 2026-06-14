<?php

namespace App\Models;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\ApiPlatform\State\CreateLibraryRootProcessor;
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
#[ApiResource(
    operations: [
        new Get,
        new GetCollection(order: ['name' => 'ASC']),
        new Post(
            rules: [
                'name' => ['required', 'string', 'max:255'],
                'path' => ['required', 'string', 'max:4096'],
                'cover_image_path' => ['nullable', 'string', 'max:1024'],
            ],
            processor: CreateLibraryRootProcessor::class,
        ),
        new Delete,
    ],
    paginationItemsPerPage: 100,
)]
class LibraryRoot extends Model
{
    /** @var list<string> */
    protected $hidden = ['path_hash', 'library', 'scanRuns', 'albums', 'mediaFiles'];

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
