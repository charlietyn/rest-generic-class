<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Ronu\RestGenericClass\Core\Contracts\HasRestAggregates;
use Ronu\RestGenericClass\Core\Contracts\HasRestRelations;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AggregatePolicyResolver
{
    private array $columns = [];

    public function validate(Model $model, array $params): void
    {
        $key = isset($params['aggregate']) ? 'aggregate' : 'with_aggregates';
        foreach ($params[$key] ?? [] as $i => $spec) {
            $path = "{$key}.{$i}";
            $target = $key === 'aggregate' ? $model : $this->relation($model, $spec['relation'], $path)->getRelated();
            $permitted = $target instanceof HasRestAggregates ? $target->getRestAggregateColumns() : [];
            if (!in_array($spec['function'], $permitted[$spec['column']] ?? [], true)) {
                throw new HttpException(400, "{$path}.column: aggregate is not authorized on this model.");
            }
            if ($spec['column'] !== '*' && !in_array($spec['column'], $this->columns($target), true)) {
                throw new HttpException(400, "{$path}.column: column does not exist.");
            }
            $alias = $spec['as'];
            $reserved = array_merge($this->columns($model), array_keys($model->getAttributes()), array_keys($model->getCasts()), $model->getAppends(), $model->getMutatedAttributes());
            if (in_array(strtolower($alias), array_map('strtolower', $reserved), true) || method_exists($model, $alias)) {
                throw new HttpException(400, "{$path}.as: alias conflicts with a model attribute or relation.");
            }
            foreach ($params['select'] ?? [] as $selected) {
                if (is_string($selected) && preg_match('/\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*)$/i', $selected, $match) && strcasecmp($match[1], $alias) === 0) {
                    throw new HttpException(400, "{$path}.as: alias conflicts with select.");
                }
            }
        }
        $aliases = array_column($params['with_aggregates'] ?? [], 'as');
        foreach ($params['orderby'] ?? [] as $entry) {
            if (!is_array($entry)) {
                throw new HttpException(400, 'orderby: expected objects with column/direction pairs.');
            }
            foreach ($entry as $column => $direction) {
                if (!is_string($direction) || !in_array(strtolower($direction), ['asc', 'desc'], true)) {
                    throw new HttpException(400, 'orderby: direction must be asc or desc.');
                }
                if (!str_contains($column, '.') && !in_array($column, $aliases, true) && !in_array($column, $this->columns($model), true)) {
                    throw new HttpException(400, 'orderby: unknown column or aggregate alias.');
                }
            }
        }
    }

    public function relation(Model $model, string $name, string $path): Relation
    {
        // Aggregations always require explicit permission, even when legacy filters auto-detect relations.
        $allowed = $model instanceof HasRestRelations
            ? $model->getRestRelations()
            : (defined(get_class($model).'::RELATIONS') ? $model::RELATIONS : []);
        if (!in_array($name, $allowed, true) || !method_exists($model, $name)) {
            throw new HttpException(400, "{$path}.relation: relation is not authorized.");
        }
        $relation = Relation::noConstraints(fn () => $model->{$name}());
        if ($relation instanceof MorphTo || !($relation instanceof BelongsTo || $relation instanceof BelongsToMany || $relation instanceof HasOneOrMany || $relation instanceof HasOneOrManyThrough)) {
            throw new HttpException(400, "{$path}.relation: unsupported relation type.");
        }
        if ($relation->getRelated()->getConnection()->getName() !== $model->getConnection()->getName()) {
            throw new HttpException(400, "{$path}.relation: cross-connection aggregates are not supported.");
        }
        return $relation;
    }

    private function columns(Model $model): array
    {
        $key = $model->getConnection()->getName().':'.$model->getTable();
        return $this->columns[$key] ??= $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
    }
}
