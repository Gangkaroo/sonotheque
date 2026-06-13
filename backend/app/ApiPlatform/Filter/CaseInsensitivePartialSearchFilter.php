<?php

namespace App\ApiPlatform\Filter;

use ApiPlatform\Laravel\Eloquent\Filter\FilterInterface;
use ApiPlatform\Metadata\Parameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CaseInsensitivePartialSearchFilter implements FilterInterface
{
    /**
     * @param  Builder<Model>  $builder
     * @param  array<string, mixed>  $context
     * @return Builder<Model>
     */
    public function apply(Builder $builder, mixed $values, Parameter $parameter, array $context = []): Builder
    {
        $value = (string) $values;
        $explicitRelation = $context['relation'] ?? null;
        $explicitProperty = $context['property'] ?? null;

        if (is_string($explicitRelation) && $explicitRelation !== '') {
            return $builder->whereHas(
                $explicitRelation,
                static fn (Builder $query): Builder => $query->where(
                    is_string($explicitProperty) ? $explicitProperty : 'name',
                    'ilike',
                    "%{$value}%",
                ),
            );
        }

        $nestedProperties = $parameter->getExtraProperties()['nested_properties_info'] ?? [];
        $nestedProperty = $nestedProperties ? reset($nestedProperties) : null;

        if (! $nestedProperty || count($nestedProperty['relation_segments']) === 0) {
            $property = $parameter->getExtraProperties()['_query_property']
                ?? $parameter->getProperty();

            return $builder->where($property, 'ilike', "%{$value}%");
        }

        $relation = implode('.', $nestedProperty['relation_segments']);
        $property = $nestedProperty['leaf_property'];

        return $builder->whereHas(
            $relation,
            static fn (Builder $query): Builder => $query->where($property, 'ilike', "%{$value}%"),
        );
    }
}
