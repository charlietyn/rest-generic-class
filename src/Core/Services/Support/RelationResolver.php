<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Ronu\RestGenericClass\Core\Contracts\HasRestRelations;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RelationResolver
{
    public function allowedFor(object|string $modelClass): array
    {
        $model = is_string($modelClass) ? new $modelClass : $modelClass;
        $class = get_class($model);

        if ($model instanceof HasRestRelations) {
            return $model->getRestRelations();
        }

        if (defined("{$class}::RELATIONS")) {
            return $class::RELATIONS;
        }

        $strict = config('rest-generic-class.filtering.strict_relations', true);

        if ($strict) {
            throw new HttpException(
                500,
                "Model {$class} must define const RELATIONS for security. " .
                "Set 'filtering.strict_relations' => false to auto-detect (not recommended)."
            );
        }

        return $this->autoDetect($model);
    }

    public function normalize(mixed $relations, object|string $modelClass): array
    {
        if (!$relations) {
            return [];
        }

        if (is_string($relations)) {
            $decoded = json_decode($relations, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $relations = $decoded;
            } elseif ($relations !== 'all') {
                $relations = [$relations];
            }
        }

        if (!is_array($relations)) {
            return [];
        }

        if (in_array('all', $relations, true)) {
            $relations = $this->allowedFor($modelClass);
        }

        $normalized = [];
        foreach ($relations as $relationString) {
            if (is_string($relationString)) {
                $normalized[] = $this->parseWithFields($relationString);
            }
        }

        return $normalized;
    }

    public function extractFilters(array $normalized, object|string $modelClass): array
    {
        $allowedRelations = $this->allowedFor($modelClass);
        $relationFilters = [];

        foreach ($normalized as $key => $value) {
            if (in_array($key, ['and', 'or'], true)) {
                continue;
            }

            $firstSegment = str_contains($key, '.')
                ? explode('.', $key)[0]
                : $key;

            if (!in_array($firstSegment, $allowedRelations, true)) {
                throw new HttpException(
                    400,
                    "Relation '{$key}' is not allowed for filtering on model " .
                    (is_object($modelClass) ? get_class($modelClass) : $modelClass) .
                    ". Allowed relations: " . implode(', ', $allowedRelations)
                );
            }

            $relationFilters[$key] = $value;
        }

        return $relationFilters;
    }

    public function stripFilters(array $normalized): array
    {
        $cleaned = [];

        foreach ($normalized as $key => $value) {
            if (in_array($key, ['and', 'or'], true)) {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    public function relatedModel(object|string $modelClass, string $relationName): string
    {
        $model = is_string($modelClass) ? new $modelClass : $modelClass;
        $class = get_class($model);

        if (!method_exists($model, $relationName)) {
            throw new HttpException(400, "Relation '{$relationName}' does not exist on model {$class}.");
        }

        try {
            $relation = $model->{$relationName}();

            if (!$relation instanceof Relation) {
                throw new HttpException(400, "Method '{$relationName}' on {$class} is not a valid Eloquent relation.");
            }

            return get_class($relation->getRelated());
        } catch (\Throwable $e) {
            throw new HttpException(400, "Failed to resolve related model for '{$relationName}': " . $e->getMessage());
        }
    }

    public function parseWithFields(string $relationString): array
    {
        $parts = explode(':', $relationString, 2);
        $relation = trim($parts[0]);
        $fields = isset($parts[1]) ? array_map('trim', explode(',', $parts[1])) : null;
        $segments = explode('.', $relation);

        return [
            'relation' => $relation,
            'fields' => $fields,
            'segments' => $segments,
            'base' => $segments[0],
        ];
    }

    public function addRequiredFields(object|string $parentModel, string $relationName, array $fields): array
    {
        if (empty($fields)) {
            return $fields;
        }

        $model = is_string($parentModel) ? new $parentModel : $parentModel;

        try {
            $relation = $model->{$relationName}();
            $relatedKeyName = $relation->getRelated()->getKeyName();

            if (!in_array($relatedKeyName, $fields, true)) {
                array_unshift($fields, $relatedKeyName);
            }

            if ($relation instanceof HasOneOrMany) {
                $foreignKey = $relation->getForeignKeyName();
                $foreignKeyParts = explode('.', $foreignKey);
                $foreignKeyName = end($foreignKeyParts);

                if (!in_array($foreignKeyName, $fields, true)) {
                    array_unshift($fields, $foreignKeyName);
                }
            } elseif ($relation instanceof BelongsTo || $relation instanceof BelongsToMany) {
                // The related primary key is enough for these relation types.
            }

            return array_values(array_unique($fields));
        } catch (\Throwable $e) {
            Log::channel('rest-generic-class')->warning(
                "Could not determine foreign keys for relation {$relationName}: " . $e->getMessage()
            );

            return $fields;
        }
    }

    public function processNestedWithFields(array $normalized): array
    {
        $processed = [];

        foreach ($normalized as $parsed) {
            $relation = $parsed['relation'];
            $fields = $parsed['fields'];

            if (!str_contains($relation, '.')) {
                $processed[] = $parsed;
                continue;
            }

            $segments = $parsed['segments'];

            if ($fields) {
                $processed[] = [
                    'relation' => $relation,
                    'key' => $relation . ':' . implode(',', $fields),
                    'fields' => $fields,
                    'segments' => $segments,
                    'base' => $segments[0],
                ];
            } else {
                $processed[] = $parsed;
            }
        }

        return $processed;
    }

    public function autoDetect(object $model): array
    {
        $relations = [];

        foreach (get_class_methods($model) as $method) {
            if (!method_exists($model, $method) || in_array($method, ['exists', 'increment', 'decrement'], true)) {
                continue;
            }

            try {
                $reflection = new \ReflectionMethod($model, $method);

                if (!$reflection->isPublic() || $reflection->isStatic() || str_starts_with($method, '_')) {
                    continue;
                }

                $returnType = $reflection->getReturnType();
                if (!$returnType || $returnType->isBuiltin()) {
                    continue;
                }

                if (is_subclass_of($returnType->getName(), Relation::class)) {
                    $relations[] = $method;
                }
            } catch (\ReflectionException) {
                continue;
            }
        }

        return $relations;
    }
}
