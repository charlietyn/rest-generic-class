<?php

/**
 * @author Charlietyn
 */
namespace Ronu\RestGenericClass\Core\Traits;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Database\Eloquent\Builder;

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
     * - `exists`
     * - `not exists`
     * - `regexp`
     * - `not regexp`
     *
     * The value can be a single value, or an array of values.
     * @param Builder $query
     * @param array $params
     * @param string $condition
     * @param mixed $model Model instance or class for determining table name
     * @return Builder
     */
    private function applyFilters($query, $params, $condition = "and", $model = null): Builder
    {
        if (is_string($params)) {
            $params = json_decode($params, true);
        }

        if (!($query instanceof Builder)) {
            throw new HttpException(501, 'The $query must be an instance of Builder or EloquentBuilder.');
        }

        // Determine table name for prefixing
        $tableName = $this->getTableName($query, $model);

        $postgresDriver = $query->getConnection()->getDriverName() === 'pgsql';

        foreach ($params as $logic => $conditions) {
            if (!in_array($logic, ['and', 'or'])) {
                throw new HttpException(400, "Unsupported logical key '$logic'. Only 'and' and 'or' are allowed.");
            }

            $query->{$condition === 'or' ? 'orWhere' : 'where'}(function ($subQuery) use ($conditions, $logic, $tableName) {
                $laravelOperators = [
                    '=', '!=', '<>', '<', '>', '<=', '>=',
                    'like', 'not like', 'ilike', 'ilikeu', 'not ilike',
                    'in', 'not in', 'notin',
                    'between', 'not between', 'notbetween',
                    'date', 'not date', 'notdate',
                    'null', 'not null', 'notnull',
                    'exists', 'not exists', 'notexists',
                    'regexp', 'not regexp'
                ];

                if (is_array($conditions)) {
                    if (array_is_list($conditions)) {
                        foreach ($conditions as $conditionString) {
                            [$field, $operator, $value] = $this->parseConditionString($conditionString);

                            if (!in_array($operator, $laravelOperators)) {
                                throw new HttpException(400, "The $operator value is not a valid operator.");
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
                                'exists' => $subQuery->{$method . 'Exists'}($field),
                                'notexists', 'not exists' => $subQuery->{$method . 'NotExists'}($field),
                                'between' => $subQuery->{$method . 'Between'}($field, $this->toBetweenArray($value)),
                                'notbetween', 'not between' => $subQuery->{$method . 'NotBetween'}($field, $this->toBetweenArray($value)),
                                'date' => $subQuery->{$method . 'Date'}($field, $value),
                                'notdate', 'not date' => $subQuery->{$method . 'Date'}($field, '!=', $value),
                                'ilikeu' => $logic == 'or'
                                    ? $subQuery->orWhereRaw("unaccent({$field}) ILIKE unaccent(?)", ["%$value%"])
                                    : $subQuery->whereRaw("unaccent({$field}) ILIKE unaccent(?)", ["%$value%"]),
                                default => $subQuery->{$method}($field, $operator, $value),
                            };
                        }
                    } else {
                        // Recursive call for nested conditions
                        $this->applyFilters($subQuery, $conditions, $logic, null);
                    }
                }
            });
        }

        return $query;
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