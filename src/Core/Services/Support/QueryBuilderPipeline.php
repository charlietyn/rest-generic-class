<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QueryBuilderPipeline
{
    private Closure $applyEqualityFilters;
    private Closure $applyOperTree;
    private Closure $applyRelations;
    private Closure $applyOrderBy;

    public function __construct(
        private Model $model,
        callable $applyEqualityFilters,
        callable $applyOperTree,
        callable $applyRelations,
        callable $applyOrderBy
    ) {
        $this->applyEqualityFilters = Closure::fromCallable($applyEqualityFilters);
        $this->applyOperTree = Closure::fromCallable($applyOperTree);
        $this->applyRelations = Closure::fromCallable($applyRelations);
        $this->applyOrderBy = Closure::fromCallable($applyOrderBy);
    }

    public function process(mixed $params, Builder $query): Builder
    {
        $params = (new AggregateSpecParser())->normalize((array) $params);
        if (array_key_exists('aggregate', $params)) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(400, 'aggregate: use list_all to execute global metrics.');
        }
        if (isset($params['with_aggregates'])) {
            (new AggregatePolicyResolver())->validate($this->model, $params);
        }
        $nested = isset($params['_nested']) ? $params['_nested'] : false;
        $query = $this->filter($params, $query);
        $oper = $this->decodeOper($params['oper'] ?? null);

        if (isset($params['relations'])) {
            $query = ($this->applyRelations)($query, $params['relations'], $nested ? $oper : null);
        }

        if (isset($params['select'])) {
            $query = $query->select($params['select']);
        } else {
            $query = $query->select($this->model->getTable() . '.*');
        }

        if (isset($params['with_aggregates'])) {
            $query = (new AggregateCoordinator())->relations($query, $params['with_aggregates'], function (Builder $related, array $spec) {
                foreach ($spec['attr'] ?? [] as $field => $value) {
                    $field = $related->getModel()->qualifyColumn($field);
                    is_array($value) ? $related->whereIn($field, $value) : $related->where($field, $value);
                }
                if (!empty($spec['oper'])) {
                    ($this->applyOperTree)($related, $spec['oper'], 'and', $related->getModel());
                }
            });
        }

        if (isset($params['orderby'])) {
            $query = ($this->applyOrderBy)($query, $params['orderby'], array_column($params['with_aggregates'] ?? [], 'as'));
        }

        return $query;
    }

    public function filter(array $params, Builder $query): Builder
    {
        if (AggregateSpecParser::requested($params) && isset($params['eq'])) {
            $params['attr'] = array_merge($params['eq'], $params['attr'] ?? []);
        }
        if (isset($params['attr'])) {
            $query = ($this->applyEqualityFilters)($query, $params['attr']);
        }
        $oper = $this->decodeOper($params['oper'] ?? null);
        if (!empty($oper)) {
            $query = ($this->applyOperTree)($query, $oper, 'and', $this->model);
        }
        return $query;
    }

    public function processAll(mixed $params, Builder $query, callable $applyPagination): mixed
    {
        $query = $this->process($params, $query);

        if (isset($params['pagination'])) {
            return $applyPagination($params, $query);
        }

        return $query;
    }

    private function decodeOper(mixed $oper): mixed
    {
        if (!is_string($oper)) {
            return $oper;
        }

        $decoded = json_decode($oper, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $oper;
    }
}
