<?php

/**
 * @author Charlietyn
 */
namespace Ronu\RestGenericClass\Core\Traits;

use Ronu\RestGenericClass\Core\Services\Support\ColumnGuard;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait HasDynamicFilter
{
    /**
     * Apply dynamic filters to a query builder instance using a structured array.
     *
     * @param Builder $query
     * @param array $params
     * @param string $condition
     * @param mixed $model Model instance or class (for table prefixing)
     * @return Builder
     */
    public function scopeWithFilters($query, array $params, string $condition = 'and', $model = null)
    {
        return $this->applyFilters($query, $params, $condition, $model);
    }

    /**
     * Build a query with a given array of conditions.
     * Now supports automatic table prefixing to avoid column ambiguity.
     * The first key of the array is a logical operator, either 'and' or 'or'.
     * The value associated with this key is an array of conditions.  Each condition
     * is a string in the format of `column_name operator value`.  For example,
     * `name = John Smith`.  The operator can be any of the following:
     *
     * - `=`
     * - `!=`
     * - `<`
     * - `>`
     * - `<=`
     * - `>=`
     * - `like`
     * - `not like`
     * - `ilike`
     * - `not ilike`
     * - `in`
     * - `not in`
     * - `between`
     * - `not between`
     * - `date`
     * - `not date`
     * - `notdate`
     * - `null`
     * - `not null`
     * - `regexp`
     * - `not regexp`
     *
     * `exists` / `not exists` are not operators since 3.1 (they were always a TypeError: a condition
     * string can only name a column, never the sub-query whereExists needs). "Has related rows" is a
     * relation filter: `{"oper": {"devices": {"and": ["id|>|0"]}}}`.
     *
     * The value can be a single value, or an array of values.
     * @param Builder $query
     * @param array $params
     * @param string $condition
     * @param mixed $model Model instance or class for determining table name
     * @param int $depth Nesting level of $params: the root of oper is 1, each relation hop and each
     *                   nested and/or group adds one (filtering.max_depth, as in the NestJS libraries)
     * @return Builder
     */
    private function applyFilters($query, $params, $condition = "and", $model = null, int $depth = 1): Builder
    {
        if (is_string($params)) {
            $params = json_decode($params, true);
        }

        $maxDepth = $this->getFilterMaxDepth();
        if ($depth > $maxDepth) {
            // Only relation hops were counted, so {"and":{"and":…}} six levels deep passed a limit of 5.
            throw new HttpException(400, "Maximum nesting depth ({$maxDepth}) exceeded.");
        }

        if (!($query instanceof Builder)) {
            throw new HttpException(501, 'The $query must be an instance of Builder or EloquentBuilder.');
        }

        // Determine table name for prefixing
        $tableName = $this->getTableName($query, $model);
        // The model whose columns are named here: hidden ones are refused (ColumnGuard).
        $filterModel = $this->getFilterModel($query, $model);

        $driver = $query->getConnection()->getDriverName();

        foreach ($params as $logic => $conditions) {
            if (!in_array($logic, ['and', 'or'])) {
                throw new HttpException(400, "Unsupported logical key '$logic'. Only 'and' and 'or' are allowed.");
            }

            $query->{$condition === 'or' ? 'orWhere' : 'where'}(function ($subQuery) use ($conditions, $logic, $tableName, $filterModel, $driver, $depth) {
                $laravelOperators = [
                    '=', '!=', '<>', '<', '>', '<=', '>=',
                    'like', 'not like', 'ilike', 'ilikeu', 'not ilike',
                    'in', 'not in', 'notin',
                    'between', 'not between', 'notbetween',
                    'date', 'not date', 'notdate',
                    'null', 'not null', 'notnull',
                    'regexp', 'not regexp'
                ];

                if (is_array($conditions)) {
                    if (array_is_list($conditions)) {
                        foreach ($conditions as $conditionString) {
                            [$field, $operator, $value] = $this->parseConditionString($conditionString);

                            if (!in_array($operator, $laravelOperators)) {
                                throw new HttpException(400, "The $operator value is not a valid operator.");
                            }

                            if ($filterModel) {
                                ColumnGuard::assertQueryable($filterModel, $field, 'filter');
                            }

                            // Prefix field with table name if not already prefixed
                            $field = $this->prefixColumn($field, $tableName);

                            $value = $this->decodeValue($value);
                            $method = $logic === 'or' ? 'orWhere' : 'where';

                            match (strtolower($operator)) {
                                'in' => $subQuery->{$method . 'In'}($field, (array)$value),
                                'notin', 'not in' => $subQuery->{$method . 'NotIn'}($field, (array)$value),
                                'null' => $subQuery->{$method . 'Null'}($field),
                                'notnull', 'not null' => $subQuery->{$method . 'NotNull'}($field),
                                'between' => $subQuery->{$method . 'Between'}($field, $this->toBetweenArray($value)),
                                'notbetween', 'not between' => $subQuery->{$method . 'NotBetween'}($field, $this->toBetweenArray($value)),
                                'date' => $subQuery->{$method . 'Date'}($field, $value),
                                'notdate', 'not date' => $subQuery->{$method . 'Date'}($field, '!=', $value),
                                'regexp', 'not regexp' => $subQuery->{$method}($field, $this->regexpOperator($driver, strtolower($operator)), $value),
                                'ilikeu' => $logic == 'or'
                                    ? $subQuery->orWhereRaw("unaccent({$field}) ILIKE unaccent(?)", ["%$value%"])
                                    : $subQuery->whereRaw("unaccent({$field}) ILIKE unaccent(?)", ["%$value%"]),
                                default => $subQuery->{$method}($field, $operator, $value),
                            };
                        }
                    } else {
                        // Recursive call for nested conditions
                        $this->applyFilters($subQuery, $conditions, $logic, $filterModel, $depth + 1);
                    }
                }
            });
        }

        return $query;
    }

    private function getFilterMaxDepth(): int
    {
        try {
            return (int) config('rest-generic-class.filtering.max_depth', 5);
        } catch (\Throwable $e) {
            return 5;
        }
    }

    /**
     * The SQL operator of `regexp` / `not regexp` on `$driver` (the same translation as the
     * NestJS libraries). PostgreSQL has no REGEXP keyword: forwarding it verbatim was a 500
     * (KI-REGEXP-POSTGRES). SQLite and SQL Server have no regular-expression operator at all.
     * `filtering.translate_regexp=false` forwards the operator verbatim, as before 3.1 (for a
     * SQLite connection with a user-defined REGEXP function).
     */
    private function regexpOperator(string $driver, string $operator): string
    {
        $negated = $operator === 'not regexp';

        try {
            $translate = config('rest-generic-class.filtering.translate_regexp', true);
        } catch (\Throwable $e) {
            $translate = true;
        }
        if (!$translate) {
            return $operator;
        }

        return match ($driver) {
            'pgsql' => $negated ? '!~' : '~',
            'mysql', 'mariadb' => $operator,
            default => throw new HttpException(400, "The regexp operator is not supported on the {$driver} driver."),
        };
    }

    /**
     * Get table name from query or model.
     *
     * @param Builder $query
     * @param mixed $model
     * @return string|null
     */
    private function getTableName(Builder $query, $model = null): ?string
    {
        // The builder's own model first: inside whereHas over a self relation (roles.father) Laravel
        // aliases the inner table and sets that alias on the builder's model (laravel_reserved_N).
        // The static table name bound every column to the OUTER row (KI-SELF-RELATION-FILTER).
        try {
            $builderModel = $query->getModel();
            if ($builderModel && (!$model || is_a($builderModel, is_object($model) ? get_class($model) : $model))) {
                return $builderModel->getTable();
            }
        } catch (\Throwable $e) {
            // fall back to the given model
        }

        if ($model) {
            if (is_string($model)) {
                $modelInstance = new $model;
                return $modelInstance->getTable();
            }
            if (is_object($model) && method_exists($model, 'getTable')) {
                return $model->getTable();
            }
        }

        // Fallback: get from query
        try {
            return $query->getModel()->getTable();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The model whose columns a filter names: the given one, else the builder's.
     *
     * @param Builder $query
     * @param mixed $model
     * @return Model|null
     */
    private function getFilterModel(Builder $query, $model = null): ?Model
    {
        if ($model instanceof Model) {
            return $model;
        }
        if (is_string($model) && is_subclass_of($model, Model::class)) {
            return new $model;
        }

        try {
            return $query->getModel();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Prefix column with table name if not already prefixed and not a function.
     *
     * @param string $column
     * @param string|null $tableName
     * @return string
     */
    private function prefixColumn(string $column, ?string $tableName): string
    {
        if (!$tableName) {
            return $column;
        }

        // Skip if column is already prefixed with a table name
        if (str_contains($column, '.')) {
            return $column;
        }

        // Skip if column is a SQL function (contains parentheses)
        if (str_contains($column, '(')) {
            return $column;
        }

        // Prefix with table name
        return $tableName . '.' . $column;
    }

    /**
     * Parse a condition string into an array of field, operator, and value.
     *
     * @param mixed $condition The condition to parse (must be a string)
     * @return array [field, operator, value]
     * @throws HttpException If condition is not a valid string format
     */
    protected function parseConditionString(mixed $condition): array
    {
        // Validate that condition is a string
        if (!is_string($condition)) {
            $type = gettype($condition);
            $example = is_array($condition) ? json_encode($condition) : (string) $condition;

            throw new HttpException(
                400,
                "Invalid condition format: expected a string like 'field|operator|value', " .
                "but received {$type}" . ($type === 'array' ? " ({$example})" : "") . ". " .
                "Ensure 'oper' is an array of strings, e.g.: " .
                '{"oper": {"and": ["category_id|=|1", "status|=|active"]}} or ' .
                '{"oper": ["category_id|=|1"]}.'
            );
        }

        $parts = explode('|', $condition, 3);

        if (count($parts) !== 3) {
            throw new HttpException(400, "Invalid condition: '$condition'. Expected format 'field|operator|value'.");
        }

        return $parts;
    }

    /**
     * Decodes a string value into its appropriate PHP type.
     */
    protected function decodeValue(string $val)
    {
        $val = trim($val);
        if (str_contains($val, ',')) {
            return array_map([$this, 'decodeValue'], explode(',', $val));
        }
        return match (strtolower($val)) {
            'null' => null,
            'true' => true,
            'false' => false,
            default => is_numeric($val) ? $val + 0 : $val,
        };
    }

    protected function toBetweenArray($val)
    {
        if (!is_array($val) || count($val) !== 2) {
            throw new HttpException(400, "The 'between' operator requires exactly two values.");
        }
        return $val;
    }
}