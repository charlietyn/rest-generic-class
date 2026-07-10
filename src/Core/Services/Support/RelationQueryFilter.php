<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Illuminate\Database\Eloquent\Relations\Relation;
use Ronu\RestGenericClass\Core\Traits\HasDynamicOrderBy;

class RelationQueryFilter
{
    use HasDynamicOrderBy;

    /**
     * Apply equality filters: { "field": "value" } or { "field": [1,2,3] }.
     */
    public function applyEq(Relation $query, array $eq): void
    {
        foreach ($eq as $field => $value) {
            if ($field === '_relation' || $field === '_scenario') {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($field, $value);
            } elseif ($value === null) {
                $query->whereNull($field);
            } else {
                $query->where($field, '=', $value);
            }
        }
    }

    /**
     * Apply complex oper filters.
     *
     * Supports format:
     *   { "and": ["field operator value", ...], "or": ["field operator value", ...] }
     */
    public function applyOper(Relation $query, array $oper): void
    {
        if (empty($oper)) {
            return;
        }

        if (isset($oper['and'])) {
            $query->where(function ($q) use ($oper) {
                foreach ($oper['and'] as $condition) {
                    $this->applySingleCondition($q, $condition, 'and');
                }
            });
        }

        if (isset($oper['or'])) {
            $query->where(function ($q) use ($oper) {
                foreach ($oper['or'] as $condition) {
                    $this->applySingleCondition($q, $condition, 'or');
                }
            });
        }
    }

    /**
     * Parse a single condition string: "field operator value".
     *
     * Supported operators: =, !=, <, >, <=, >=, like, not like, ilike, not ilike,
     * in, not in, between, not between, null, not null.
     */
    public function applySingleCondition($query, string $condition, string $boolean): void
    {
        $condition = trim($condition);

        if (preg_match('/^(.+?)\s+(not\s+null|null)$/i', $condition, $m)) {
            $field = trim($m[1]);
            $op = strtolower(trim($m[2]));

            if ($op === 'null') {
                $query->whereNull($field, $boolean);
            } else {
                $query->whereNotNull($field, $boolean);
            }

            return;
        }

        if (preg_match('/^(.+?)\s+(not\s+between|between)\s+(.+)$/i', $condition, $m)) {
            $field = trim($m[1]);
            $op = strtolower(trim($m[2]));
            $values = array_map('trim', explode(',', $m[3]));

            if (count($values) === 2) {
                if ($op === 'between') {
                    $query->whereBetween($field, $values, $boolean);
                } else {
                    $query->whereNotBetween($field, $values, $boolean);
                }
            }

            return;
        }

        if (preg_match('/^(.+?)\s+(not\s+in|in)\s+(.+)$/i', $condition, $m)) {
            $field = trim($m[1]);
            $op = strtolower(trim($m[2]));
            $rawValues = trim($m[3], '[] ');
            $values = array_map('trim', explode(',', $rawValues));

            if ($op === 'in') {
                $query->whereIn($field, $values, $boolean);
            } else {
                $query->whereNotIn($field, $values, $boolean);
            }

            return;
        }

        $operators = ['not ilike', 'not like', 'ilike', 'like', '!=', '>=', '<=', '>', '<', '='];

        foreach ($operators as $op) {
            $pos = stripos($condition, " {$op} ");
            if ($pos === false) {
                continue;
            }

            $field = trim(substr($condition, 0, $pos));
            $value = trim(substr($condition, $pos + strlen($op) + 2));
            $normalizedOp = strtolower($op);

            if (in_array($normalizedOp, ['like', 'not like'], true)) {
                $value = str_contains($value, '%') ? $value : "%{$value}%";
                $method = $boolean === 'or' ? 'orWhere' : 'where';
                $query->{$method}($field, $op, $value);
            } elseif (in_array($normalizedOp, ['ilike', 'not ilike'], true)) {
                $value = str_contains($value, '%') ? $value : "%{$value}%";
                $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
                $negation = str_starts_with($normalizedOp, 'not') ? 'NOT ' : '';
                $query->{$method}("{$field} {$negation}ILIKE ?", [$value]);
            } elseif ($boolean === 'or') {
                $query->orWhere($field, $op, $value);
            } else {
                $query->where($field, $op, $value);
            }

            return;
        }
    }

    /**
     * Apply ordering: [{"field":"asc"}, {"field2":"desc"}].
     */
    public function applyOrdering(Relation $query, array $orderby): void
    {
        $this->applyDynamicOrderBy($query, $orderby, $query->getRelated());
    }
}
