<?php

namespace App\Models;

use ApiPlatform\Laravel\Eloquent\Filter\EqualsFilter;
use ApiPlatform\Laravel\Eloquent\Filter\RangeFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\ApiPlatform\Filter\CaseInsensitivePartialSearchFilter;
use App\ApiPlatform\Filter\RelationshipEqualsFilter;
use App\Enums\ArtworkSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'library_root_id',
    'primary_artist_id',
    'artwork_id',
    'artwork_source_type',
    'artwork_source_relative_path',
    'title',
    'sort_title',
    'relative_path',
    'relative_path_hash',
    'original_release_year',
    'disc_total',
    'metadata',
])]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(
            order: ['sort_title' => 'ASC', 'title' => 'ASC'],
            parameters: [
                'page' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    castToNativeType: true,
                ),
                'search' => new QueryParameter(
                    filter: CaseInsensitivePartialSearchFilter::class,
                    property: 'title',
                    description: 'Case-insensitive partial album-title search.',
                ),
                'artist' => new QueryParameter(
                    filter: CaseInsensitivePartialSearchFilter::class,
                    property: 'name',
                    description: 'Case-insensitive partial primary-artist search.',
                    filterContext: ['relation' => 'primaryArtist', 'property' => 'name'],
                ),
                'artistId' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    filter: EqualsFilter::class,
                    property: 'primary_artist_id',
                    description: 'Filter by primary artist identifier.',
                    castToNativeType: true,
                ),
                'library' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    filter: RelationshipEqualsFilter::class,
                    property: 'id',
                    description: 'Filter by logical library identifier.',
                    filterContext: ['relation' => 'libraryRoot', 'property' => 'library_id'],
                    castToNativeType: true,
                ),
                'year' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 9999],
                    filter: EqualsFilter::class,
                    property: 'original_release_year',
                    description: 'Filter by original release year.',
                    castToNativeType: true,
                ),
                'yearRange' => new QueryParameter(
                    filter: RangeFilter::class,
                    property: 'original_release_year',
                    description: 'Filter by an original release year range.',
                ),
                'genre' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    filter: RelationshipEqualsFilter::class,
                    property: 'id',
                    description: 'Filter albums containing a track with this genre.',
                    filterContext: ['relation' => 'tracks.genres', 'property' => 'id'],
                    castToNativeType: true,
                ),
            ],
            strictQueryParameterValidation: true,
        ),
    ],
    paginationItemsPerPage: 30,
)]
class Album extends Model
{
    /** @var list<string> */
    protected $hidden = [
        'library_root_id',
        'artwork_id',
        'relative_path',
        'relative_path_hash',
        'metadata',
        'libraryRoot',
        'mediaFiles',
    ];

    /** @return BelongsTo<LibraryRoot, $this> */
    public function libraryRoot(): BelongsTo
    {
        return $this->belongsTo(LibraryRoot::class);
    }

    /** @return BelongsTo<Artist, $this> */
    public function primaryArtist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'primary_artist_id');
    }

    /** @return BelongsTo<Artwork, $this> */
    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    /** @return HasMany<MediaFile, $this> */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /** @return HasOne<AlbumPersonalMetadata, $this> */
    public function personalMetadata(): HasOne
    {
        return $this->hasOne(AlbumPersonalMetadata::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'artwork_source_type' => ArtworkSource::class,
        ];
    }
}
