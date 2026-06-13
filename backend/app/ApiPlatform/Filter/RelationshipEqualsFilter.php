<?php

namespace App\ApiPlatform\Filter;

use ApiPlatform\Laravel\Eloquent\Filter\FilterInterface;
use ApiPlatform\Metadata\Parameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class RelationshipEqualsFilter implements FilterInterface
{
    /**
     * @param  Builder<Model>  $builder
     * @param  array<string, mixed>  $context
     * @return Builder<Model>
     */
    public function apply(Builder $builder, mixed $values, Parameter $parameter, array $context = []): Builder
    {
        $relation = $context['relation'] ?? null;
        $property = $context['property'] ?? 'id';

        if (! is_string($relation) || $relation === '') {
            throw new InvalidArgumentException('RelationshipEqualsFilter requires a relation filter context.');
        }

        return $builder->whereHas(
            $relation,
            static fn (Builder $query): Builder => $query->where($property, $values),
        );
    }
}
