<?php

namespace App\Models;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['name', 'description'])]
#[ApiResource(
    operations: [
        new Get,
        new GetCollection(order: ['name' => 'ASC']),
    ],
    paginationItemsPerPage: 50,
)]
class Library extends Model
{
    /** @var list<string> */
    protected $hidden = ['roots', 'albums', 'mediaFiles', 'scanRuns'];

    /** @return HasMany<LibraryRoot, $this> */
    public function roots(): HasMany
    {
        return $this->hasMany(LibraryRoot::class);
    }

    /** @return HasManyThrough<Album, LibraryRoot, $this> */
    public function albums(): HasManyThrough
    {
        return $this->hasManyThrough(Album::class, LibraryRoot::class);
    }

    /** @return HasManyThrough<MediaFile, LibraryRoot, $this> */
    public function mediaFiles(): HasManyThrough
    {
        return $this->hasManyThrough(MediaFile::class, LibraryRoot::class);
    }

    /** @return HasManyThrough<ScanRun, LibraryRoot, $this> */
    public function scanRuns(): HasManyThrough
    {
        return $this->hasManyThrough(ScanRun::class, LibraryRoot::class);
    }
}
