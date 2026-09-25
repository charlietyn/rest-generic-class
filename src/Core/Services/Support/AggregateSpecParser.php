<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use JsonException;
use Ronu\RestGenericClass\Core\Helpers\Helper;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AggregateSpecParser
{
    public static function requested(array $params): bool
    {
        return array_key_exists('aggregate', $params) || array_key_exists('with_aggregates', $params);
    }

    public static function reject(array $params, string $context): void
    {
        if (self::requested($params)) {
            throw new HttpException(400, "Aggregations are not supported in {$context}; use a list endpoint.");
        }
    }

    public function normalize(array $params): array
    {
        if (!self::requested($params)) {
            return $params;
        }

        foreach (['groupby', 'groupBy', 'having', 'distinct'] as $unsupported) {
            if (array_key_exists($unsupported, $params)) {
                $this->fail($unsupported, 'is not supported with aggregations');
            }
        }
        if (array_key_exists('aggregate', $params) && array_key_exists('with_aggregates', $params)) {
            $this->fail('aggregate', 'cannot be combined with with_aggregates');
        }
        $key = array_key_exists('aggregate', $params) ? 'aggregate' : 'with_aggregates';
        $specs = $this->arrayValue($params[$key], $key);
        if (!$specs || !array_is_list($specs)) {
            $this->fail($key, 'must be a non-empty list');
        }
        if (count($specs) > config('rest-generic-class.aggregations.max_metrics', 10)) {
            $this->fail($key, 'exceeds the maximum number of metrics');
        }

        $aliases = [];
        foreach ($specs as $i => &$spec) {
            $path = "{$key}.{$i}";
            $allowed = $key === 'aggregate' ? ['function', 'column', 'as'] : ['function', 'column', 'as', 'relation', 'attr', 'oper'];
            if (!is_array($spec) || array_diff(array_keys($spec), $allowed)) {
                $this->fail($path, 'must be an object containing only supported options');
            }
            if (!is_string($spec['function'] ?? null)) {
                $this->fail("{$path}.function", 'is required');
            }
            $spec['function'] = strtolower($spec['function']);
            if (!in_array($spec['function'], ['count', 'sum', 'avg', 'min', 'max'], true)) {
                $this->fail("{$path}.function", 'is not supported');
            }
            if (!(($spec['column'] ?? null) === '*' && $spec['function'] === 'count')) {
                $this->identifier($spec['column'] ?? null, "{$path}.column");
            }
            $this->identifier($spec['as'] ?? null, "{$path}.as");
            $alias = strtolower($spec['as']);
            if (strlen($alias) > 63 || str_starts_with($alias, 'laravel_') || in_array($alias, ['data', 'pivot', 'aggregate'], true) || isset($aliases[$alias])) {
                $this->fail("{$path}.as", 'must be unique, non-reserved and at most 63 characters');
            }
            $aliases[$alias] = true;
            if ($key === 'with_aggregates') {
                $this->identifier($spec['relation'] ?? null, "{$path}.relation");
                foreach (['attr', 'oper'] as $filter) {
                    if (array_key_exists($filter, $spec)) {
                        $spec[$filter] = $this->arrayValue($spec[$filter], "{$path}.{$filter}");
                    }
                }
            }
        }
        unset($spec);
        $params[$key] = $specs;

        foreach (['attr', 'eq', 'oper', 'orderby', 'pagination', 'relations'] as $option) {
            if (isset($params[$option])) {
                // Preserve the historical single-relation shorthand for record lists.
                if ($option === 'relations' && is_string($params[$option]) && $key === 'with_aggregates'
                    && preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*(?::[a-zA-Z0-9_,*]+)?$/D', $params[$option])) {
                    $params[$option] = [$params[$option]];
                    continue;
                }
                $params[$option] = $this->arrayValue($params[$option], $option);
            }
        }
        if (isset($params['select'])) {
            $params['select'] = Helper::parseSelect($params['select']);
        }
        if (!empty($params['hierarchy'])) {
            $this->fail('hierarchy', 'cannot be combined with aggregations');
        }
        if ($key === 'aggregate') {
            foreach (['relations', 'orderby', 'pagination'] as $option) {
                if (!empty($params[$option])) {
                    $this->fail($option, 'cannot be combined with aggregate');
                }
            }
            if (isset($params['select']) && $params['select'] !== ['*']) {
                $this->fail('select', 'cannot select records in aggregate mode');
            }
        }
        if (($params['pagination']['infinity'] ?? false) === true) {
            foreach ($params['orderby'] ?? [] as $entry) {
                foreach (array_keys(is_array($entry) ? $entry : []) as $column) {
                    if (isset($aliases[strtolower($column)])) {
                        $this->fail('orderby', 'cursor pagination cannot order by aggregate aliases');
                    }
                }
            }
        }

        $conditions = 0;
        $this->filters($params, '', $conditions);
        foreach ($specs as $i => $spec) {
            $this->filters($spec, "{$key}.{$i}.", $conditions);
        }
        if ($conditions > config('rest-generic-class.filtering.max_conditions', 100)) {
            $this->fail($key, 'exceeds the total filter condition limit');
        }

        return $params;
    }

    public function arrayValue(mixed $value, string $path): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->fail($path, 'contains invalid JSON');
            }
        }
        if (!is_array($value)) {
            $this->fail($path, 'must be an array or object');
        }
        return $value;
    }

    private function filters(array $params, string $path, int &$count): void
    {
        foreach (['attr', 'eq'] as $key) {
            foreach ($params[$key] ?? [] as $field => $value) {
                if (!is_string($field) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/D', $field)) {
                    $this->fail($path.$key, 'contains an invalid field');
                }
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if (!is_scalar($item) && $item !== null) {
                        $this->fail($path.$key.'.'.$field, 'requires scalar values');
                    }
                }
                ++$count;
            }
        }
        $this->conditions($params['oper'] ?? [], $path.'oper', $count, 0);
    }

    private function conditions(array $node, string $path, int &$count, int $depth): void
    {
        if ($depth > config('rest-generic-class.filtering.max_depth', 5)) {
            $this->fail($path, 'exceeds maximum filter depth');
        }
        foreach ($node as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $field = str_contains($value, '|') ? explode('|', $value, 2)[0] : (preg_split('/\s+/', $value, 2)[0] ?? '');
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $field)) {
                    $this->fail($path, 'contains an invalid condition field');
                }
                ++$count;
            } elseif (is_array($value)) {
                $extra = is_string($key) && !in_array($key, ['and', 'or'], true) ? count(explode('.', $key)) : 1;
                $this->conditions($value, $path.'.'.$key, $count, $depth + $extra);
            } else {
                $this->fail($path, 'contains an invalid filter node');
            }
        }
    }

    private function identifier(mixed $value, string $path): void
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $value)) {
            $this->fail($path, 'must be a simple identifier');
        }
    }

    private function fail(string $path, string $message): never
    {
        throw new HttpException(400, "{$path}: {$message}.");
    }
}
