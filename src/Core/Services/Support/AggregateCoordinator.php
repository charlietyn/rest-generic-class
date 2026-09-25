<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Illuminate\Database\Eloquent\Builder;

class AggregateCoordinator
{
    public function global(Builder $query, array $specs): array
    {
        $result = [];
        $normalizer = new AggregateResultNormalizer();
        foreach ($specs as $spec) {
            $metric = clone $query;
            $column = $spec['column'] === '*' ? '*' : $metric->getModel()->qualifyColumn($spec['column']);
            $value = $metric->reorder()->{$spec['function']}($column);
            $result[$spec['as']] = $normalizer->value($spec['function'], $value);
        }
        return $result;
    }

    public function relations(Builder $query, array $specs, callable $applyFilters): Builder
    {
        foreach ($specs as $spec) {
            $relation = [$spec['relation'].' as '.$spec['as'] => function (Builder $related) use ($spec, $applyFilters) {
                $applyFilters($related, $spec);
            }];
            // Native Eloquent builds and binds the correlated subquery, including pivot/type constraints.
            $query->withAggregate($relation, $spec['column'], $spec['function']);
        }
        $normalizer = new AggregateResultNormalizer();
        $query->afterQuery(fn ($result) => $normalizer->records($result, $specs));
        return $query;
    }
}
