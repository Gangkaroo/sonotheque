<?php

namespace App\Models;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\ApiPlatform\Filter\CaseInsensitivePartialSearchFilter;
use App\ApiPlatform\Filter\RelationshipEqualsFilter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name'])]
#[ApiResource(
    operations: [
        new Get,
        new GetCollection(
            order: ['name' => 'ASC'],
            parameters: [
                'page' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    castToNativeType: true,
                ),
                'search' => new QueryParameter(
                    filter: CaseInsensitivePartialSearchFilter::class,
                    property: 'name',
                    description: 'Case-insensitive partial genre-name search.',
                ),
                'library' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    filter: RelationshipEqualsFilter::class,
                    property: 'id',
                    description: 'Only genres used by tracks in this library.',
                    filterContext: ['relation' => 'tracks.album.libraryRoot', 'property' => 'library_id'],
                    castToNativeType: true,
                ),
            ],
            strictQueryParameterValidation: true,
        ),
    ],
    paginationItemsPerPage: 100,
)]
class Genre extends Model
{
    /** @return BelongsToMany<Track, $this> */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class)->withTimestamps();
    }
}
