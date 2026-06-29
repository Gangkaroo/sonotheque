<?php

namespace App\Models;

use ApiPlatform\Laravel\Eloquent\Filter\EqualsFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\ApiPlatform\Filter\CaseInsensitivePartialSearchFilter;
use App\ApiPlatform\Filter\RelationshipEqualsFilter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'album_id',
    'media_file_id',
    'title',
    'sort_title',
    'duration_ms',
    'track_number',
    'disc_number',
    'year',
    'comment',
    'composers',
    'performers',
    'metadata',
])]
#[ApiResource(
    operations: [
        new Get,
        new GetCollection(
            order: ['album_id' => 'ASC', 'disc_number' => 'ASC', 'track_number' => 'ASC'],
            parameters: [
                'page' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    castToNativeType: true,
                ),
                'search' => new QueryParameter(
                    filter: CaseInsensitivePartialSearchFilter::class,
                    property: 'title',
                    description: 'Case-insensitive partial track-title search.',
                ),
                'album' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    filter: EqualsFilter::class,
                    property: 'album_id',
                    description: 'Filter by album identifier.',
                    castToNativeType: true,
                ),
                'artist' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    filter: RelationshipEqualsFilter::class,
                    property: 'id',
                    description: 'Filter by artist identifier.',
                    filterContext: ['relation' => 'artists', 'property' => 'id'],
                    castToNativeType: true,
                ),
                'genre' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    filter: RelationshipEqualsFilter::class,
                    property: 'id',
                    description: 'Filter by genre identifier.',
                    filterContext: ['relation' => 'genres', 'property' => 'id'],
                    castToNativeType: true,
                ),
                'library' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    filter: RelationshipEqualsFilter::class,
                    property: 'id',
                    description: 'Filter by logical library identifier.',
                    filterContext: ['relation' => 'album.libraryRoot', 'property' => 'library_id'],
                    castToNativeType: true,
                ),
            ],
            strictQueryParameterValidation: true,
        ),
    ],
    paginationItemsPerPage: 50,
)]
class Track extends Model
{
    /** @var list<string> */
    protected $hidden = ['album_id', 'media_file_id', 'metadata', 'mediaFile'];

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /** @return BelongsToMany<Artist, $this> */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class)
            ->withPivot(['role', 'position'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Genre, $this> */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class)->withTimestamps();
    }

    /** @return HasOne<TrackPlayStatistic, $this> */
    public function playStatistic(): HasOne
    {
        return $this->hasOne(TrackPlayStatistic::class);
    }

    /** @return HasMany<TrackPlayEvent, $this> */
    public function playEvents(): HasMany
    {
        return $this->hasMany(TrackPlayEvent::class);
    }

    protected function casts(): array
    {
        return [
            'composers' => 'array',
            'performers' => 'array',
            'metadata' => 'array',
        ];
    }
}
