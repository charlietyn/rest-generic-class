<?php

/**
 * @author Charlietyn
 */

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait HasDynamicOrderBy
{
    /**
     * Apply orderby entries that may use dot notation for relation fields.
     *
     * Each entry can be:
     *  - {"column": "asc|desc"}                     local column
     *  - {"relation.column": "asc|desc"}            single-level relation
     *  - {"relation.sub.column": "asc|desc"}        nested relations
     *
     * Local columns are prefixed with the model's table name to avoid ambiguity
     * when a JOIN is also present. Relation paths are translated to a scalar
     * ordering subquery built via Eloquent's getRelationExistenceQuery, which
     * correctly handles belongsTo, hasOne, hasMany, belongsToMany, polymorphic
     * and hasManyThrough relations without producing duplicate rows.
     *
     * @param EloquentBuilder|QueryBuilder|Relation $query
     * @param array|string $params
     * @param Model|string $modelContext Model instance or class
     * @return EloquentBuilder|QueryBuilder|Relation
     */
    protected function applyDynamicOrderBy($query, array|string $params, $modelContext)
    {
        if (is_string($params)) {
            $decoded = json_decode($params, true);
            $params = is_array($decoded) ? $decoded : [];
        }

        foreach ($params as $elements) {
            if (is_string($elements)) {
                $decoded = json_decode($elements, true);
                $elements = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($elements)) {
                continue;
            }

            foreach ($elements as $column => $direction) {
                $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
                $query = $this->applyOrderByEntry($query, (string) $column, $direction, $modelContext);
            }
        }

        return $query;
    }

    /**
     * Apply a single orderby entry. Detects dot notation and builds the
     * appropriate subquery for relation fields, or prefixes the column with
     * the model's table for local fields.
     */
    private function applyOrderByEntry($query, string $column, string $direction, $modelContext)
    {
        if (!str_contains($column, '.')) {
            $model = $this->resolveModelInstance($modelContext);
            $table = $model ? $model->getTable() : null;
            $target = $table ? $table . '.' . $column : $column;
            return $query->orderBy($target, $direction);
        }

        $segments = explode('.', $column);
        $finalColumn = array_pop($segments);
        $relationPath = $segments;
        $firstSegment = $relationPath[0];

        $rootModel = $this->resolveModelInstance($modelContext);

        // Backward-compat: if there is no resolvable model context, or the first
        // segment is not even a method on the model, treat it as a literal
        // "table.column" reference (consumers may already JOIN manually).
        if (!$rootModel || !method_exists($rootModel, $firstSegment)) {
            return $query->orderBy($column, $direction);
        }

        // The segment IS a method on the model. Now enforce the whitelist:
        // unless it appears in const RELATIONS, refuse to build a subquery
        // for it. This prevents ordering by arbitrary methods/relations the
        // model author did not opt-in.
        $this->assertAllowedRelation($rootModel, $firstSegment);

        $subquery = $this->buildOrderingSubquery($rootModel, $relationPath, $finalColumn, 0);

        return $query->orderBy($subquery, $direction);
    }

    /**
     * Recursively build a scalar ordering subquery walking the relation chain.
     *
     * For path [a, b, c] and column "x" it produces:
     *
     *   (SELECT (SELECT (SELECT c.x FROM c WHERE c.fk = b.id LIMIT 1)
     *                   FROM b WHERE b.fk = a.id LIMIT 1)
     *           FROM a WHERE a.fk = parent.id LIMIT 1)
     *
     * Eloquent's getRelationExistenceQuery is reused so each link uses the
     * exact join keys, owner keys, polymorphic type filter or pivot defined
     * by the relation type — no manual SQL stitching required.
     *
     * @param Model $parent
     * @param array<int,string> $relationPath
     * @param string $finalColumn
     * @param int $depth
     * @return EloquentBuilder
     */
    private function buildOrderingSubquery(Model $parent, array $relationPath, string $finalColumn, int $depth): EloquentBuilder
    {
        $maxDepth = $this->getOrderByMaxDepth();
        if ($depth >= $maxDepth) {
            throw new HttpException(
                400,
                "Maximum orderby relation depth ({$maxDepth}) exceeded."
            );
        }

        $relationName = array_shift($relationPath);
        $this->assertAllowedRelation($parent, $relationName);

        if (!method_exists($parent, $relationName)) {
            throw new HttpException(
                400,
                "Relation '{$relationName}' does not exist on " . get_class($parent) . '.'
            );
        }

        $relation = $parent->{$relationName}();
        if (!$relation instanceof Relation) {
            throw new HttpException(
                400,
                "Method '{$relationName}' on " . get_class($parent) . ' is not a valid Eloquent relation.'
            );
        }

        $related = $relation->getRelated();

        // getRelationExistenceQuery returns a properly-scoped Eloquent builder
        // that already wires up the WHERE that links parent ↔ related.
        $sub = $relation->getRelationExistenceQuery(
            $related->newQuery(),
            $parent->newQuery()
        );

        if (empty($relationPath)) {
            return $sub->select($related->getTable() . '.' . $finalColumn)->limit(1);
        }

        // Nested: replace the SELECT with the inner subquery as the only column.
        // Using select(['alias' => $builder]) resets columns AND select-bindings
        // before adding the sub-select — crucial because getRelationExistenceQuery
        // initializes the query with implicit "select *" semantics, which would
        // otherwise produce a multi-column scalar subquery.
        $innerSub = $this->buildOrderingSubquery($related, $relationPath, $finalColumn, $depth + 1);
        return $sub->select(['value' => $innerSub])->limit(1);
    }

    private function resolveModelInstance($modelContext): ?Model
    {
        if ($modelContext instanceof Model) {
            return $modelContext;
        }
        if (is_string($modelContext) && class_exists($modelContext)) {
            $instance = new $modelContext;
            return $instance instanceof Model ? $instance : null;
        }
        return null;
    }

    private function isDeclaredRelation(Model $model, string $name): bool
    {
        $declared = $this->getDeclaredRelations($model);
        return in_array($name, $declared, true);
    }

    private function assertAllowedRelation(Model $model, string $name): void
    {
        if (!$this->isDeclaredRelation($model, $name)) {
            $allowed = $this->getDeclaredRelations($model);
            throw new HttpException(
                400,
                "Relation '{$name}' is not allowed for ordering on " . get_class($model) .
                '. Allowed: ' . (empty($allowed) ? '(none)' : implode(', ', $allowed))
            );
        }
    }

    private function getDeclaredRelations(Model $model): array
    {
        $class = get_class($model);
        if (!defined($class . '::RELATIONS')) {
            return [];
        }
        $relations = $class::RELATIONS;
        return is_array($relations) ? $relations : [];
    }

    private function getOrderByMaxDepth(): int
    {
        if (function_exists('config')) {
            try {
                $value = config('rest-generic-class.filtering.max_depth');
                if (is_numeric($value)) {
                    return (int) $value;
                }
            } catch (\Throwable $e) {
                // fall through to default
            }
        }
        return 5;
    }
}
