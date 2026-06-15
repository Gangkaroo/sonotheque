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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_name', 'browse_initial'])]
#[ApiResource(
    operations: [
        new Get,
        new GetCollection(
            order: ['sort_name' => 'ASC', 'name' => 'ASC'],
            parameters: [
                'page' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    castToNativeType: true,
                ),
                'search' => new QueryParameter(
                    filter: CaseInsensitivePartialSearchFilter::class,
                    property: 'name',
                    description: 'Case-insensitive partial artist-name search.',
                ),
                'initial' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => ['#', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z']],
                    filter: EqualsFilter::class,
                    property: 'browse_initial',
                    description: 'Artist browse bucket from A to Z, or #.',
                ),
                'library' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    filter: RelationshipEqualsFilter::class,
                    property: 'id',
                    description: 'Only artists with albums in this library.',
                    filterContext: ['relation' => 'albums.libraryRoot', 'property' => 'library_id'],
                    castToNativeType: true,
                ),
            ],
            strictQueryParameterValidation: true,
        ),
    ],
    paginationItemsPerPage: 50,
)]
class Artist extends Model
{
    /** @return HasMany<Album, $this> */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class, 'primary_artist_id');
    }

    /** @return BelongsToMany<Track, $this> */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class)
            ->withPivot(['role', 'position'])
            ->withTimestamps();
    }
}
