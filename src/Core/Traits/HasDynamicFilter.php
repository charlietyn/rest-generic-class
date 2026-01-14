<?php

/**Generate by ASGENS
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
     * @param EloquentBuilder|BaseBuilder $query
     * @param array $params
     * @param string $condition
     * @return EloquentBuilder|BaseBuilder
     */
    public function scopeWithFilters($query, array $params, string $condition = 'and')
    {
        return $this->applyFilters($query, $params, $condition);
    }


    /**
     * Build a query with a given array of conditions.
     *
     * The first key of the array is a logical operator, either 'and' or 'or'.
     * The value associated with this key is an array of conditions.  Each condition
     * is a string in the format of `column_name operator value`.  For example,
     * `name = John Smith`.  The operator can be any of the following:
     *
     *  - `=`
     *  - `!=`
     *  - `<`
     *  - `>`
     *  - `<=`
     *  - `>=`
     *  - `like`
     *  - `not like`
     *  - `ilike`
     *  - `not ilike`
     *  - `in`
     *  - `not in`
     *  - `between`
     *  - `not between`
     *  - `date`
     *  - `not date`
     *  - `notdate`
     *  - `null`
     *  - `not null`
     *  - `exists`
     *  - `not exists`
     *  - `regexp`
     *  - `not regexp`
     *
     * The value can be a single value, or an array of values.
     *
     * @param Builder $query
     * @param array $params
     * @param string $condition
     * @return Builder
     */
    private function applyFilters($query, $params, $condition = "and"): Builder
    {
        if (is_string($params))
            $params = json_decode($params, true);
        if (!($query instanceof Builder)) {
            throw new HttpException(501, 'The $query must be an instance of Builder or EloquentBuilder.');
        }
        $postgresDriver = $query->getConnection()->getDriverName() === 'pgsql';
        foreach ($params as $logic => $conditions) {
            if (!in_array($logic, ['and', 'or'])) {
                throw new HttpException(400, "Unsupported logical key '$logic'. Only 'and' and 'or' are allowed.");
            }
            $query->{$condition === 'or' ? 'orWhere' : 'where'}(function ($subQuery) use ($conditions, $logic) {
                $laravelOperators = ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like', 'not like', 'ilike', 'ilikeu', 'not ilike', 'in', 'not in', 'notin', 'between', 'date', 'not date', 'notdate', 'not between', 'notbetween', 'null', 'not null', 'notnull', 'exists', 'not exists', 'notexists', 'regexp', 'not regexp'];
                if (is_array($conditions)) {
                    if (array_is_list($conditions)) {
                        foreach ($conditions as $conditionString) {
                            [$field, $operator, $value] = $this->parseConditionString($conditionString);
                            if (!in_array($operator, $laravelOperators))
                                throw new HttpException(400, "The $operator value is not a valid operator.");
                            $value = $this->decodeValue($value);
                            $method = $logic === 'or' ? 'orWhere' : 'where';
                            match (strtolower($operator)) {
                                'in' => $subQuery->{$method . 'In'}($field, (array)$value),
                                'notin' => $subQuery->{$method . 'NotIn'}($field, (array)$value),
                                'not in' => $subQuery->{$method . 'NotIn'}($field, (array)$value),
                                'null' => $subQuery->{$method . 'Null'}($field),
                                'notnull' => $subQuery->{$method . 'NotNull'}($field),
                                'not null' => $subQuery->{$method . 'NotNull'}($field),
                                'exists' => $subQuery->{$method . 'Exists'}($field),
                                'notexists' => $subQuery->{$method . 'NotExists'}($field),
                                'not exists' => $subQuery->{$method . 'NotExists'}($field),
                                'between' => $subQuery->{$method . 'Between'}($field, $this->toBetweenArray($value)),
                                'not between' => $subQuery->{$method . 'NotBetween'}($field, $this->toBetweenArray($value)),
                                'notbetween' => $subQuery->{$method . 'NotBetween'}($field, $this->toBetweenArray($value)),
                                'date' => $subQuery->{$method . 'Date'}($field,$value),
                                'not date' => $subQuery->{$method . 'Date'}($field,'!=',$value),
                                'notdate' => $subQuery->{$method . 'Date'}($field,'!=',$value),
                                'ilikeu' => $logic=='or'?$subQuery->orWhereRaw("unaccent($field) ILIKE unaccent(?)", ["%$value%"]):$subQuery->whereRaw("unaccent($field) ILIKE unaccent(?)", ["%$value%"]),
                                default => $subQuery->{$method}($field, $operator, $value),
                            };
                        }
                    } else {
                        $this->oper($subQuery, $conditions, $logic);
                    }
                }
            });
        }
        return $query;
    }

    /**
     * Parse a condition string into an array of field, operator, and value.
     *
     * The method takes a condition string in the format 'field|operator|value',
     * splits it into an array of three elements, and returns the array. If the
     * condition string is invalid (i.e., does not contain exactly three parts),
     * the method throws an HttpException with a 400 status code.
     *
     * @param string $condition The condition string to parse.
     * @return array The parsed condition array with elements 'field', 'operator', and 'value'.
     * @throws HttpException If the condition string is invalid.
     */
    protected function parseConditionString(string $condition): array
    {
        $parts = explode('|', $condition, 3);

        if (count($parts) !== 3) {
            throw new HttpException(400, "Invalid condition: '$condition'. Expected format 'field|operator|value'.");
        }

        return $parts;
    }

    /**
     * Decodes a string value into its appropriate PHP type.
     *
     * This method trims the input string and checks if it contains a comma,
     * indicating a list of values. If so, it splits the string by commas and
     * recursively decodes each value. Otherwise, it matches the string to
     * specific keywords ('null', 'true', 'false') to return their respective
     * PHP types, or checks if the value is numeric to convert it to an integer
     * or float. If none of these conditions are met, it returns the original
     * string.
     *
     * @param string $val The string value to decode.
     * @return mixed The decoded value, which can be an array, null, boolean,
     *               integer, float, or string.
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
