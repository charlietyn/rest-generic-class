<?php

namespace Ronu\RestGenericClass\Core\Helpers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ColumnValidator
 *
 * Validates that columns exist in database schema before executing queries.
 * Prevents SQL errors from undefined columns in select, where, orderBy, etc.
 */
class ColumnValidator
{
    /**
     * Get all valid columns for a model's table (cached).
     *
     * @param Model $model
     * @param int $ttl Cache TTL in seconds (default 3600 = 1 hour)
     * @return array List of column names
     */
    public static function getValidColumns(Model $model, int $ttl = 3600): array
    {
        $table = $model->getTable();
        $connection = $model->getConnectionName() ?: config('database.default');
        $cacheKey = "schema_columns_{$connection}_{$table}";

        return Cache::remember($cacheKey, $ttl, function () use ($model, $table) {
            try {
                return Schema::connection($model->getConnectionName())
                    ->getColumnListing($table);
            } catch (\Throwable $e) {
                // If schema introspection fails, fallback to fillable + guarded
                return array_merge(
                    $model->getFillable(),
                    array_diff(
                        array_keys($model->getAttributes()),
                        $model->getGuarded()
                    )
                );
            }
        });
    }

    /**
     * Validate that columns exist in the model's table.
     *
     * @param Model $model
     * @param array|string $columns Single column or array of columns
     * @param bool $throwOnInvalid If true, throws exception; if false, returns filtered list
     * @return array Valid columns (empty if throwOnInvalid=true and validation fails)
     * @throws \InvalidArgumentException
     */
    public static function validateColumns(
        Model $model,
        array|string $columns,
        bool $throwOnInvalid = false
    ): array {
        $columns = is_string($columns) ? [$columns] : $columns;
        $validColumns = self::getValidColumns($model);

        $invalid = [];
        $valid = [];

        foreach ($columns as $column) {
            // Handle dot notation for relations (e.g., "user.name")
            $baseColumn = Str::before($column, '.');

            // Skip wildcards
            if ($baseColumn === '*') {
                $valid[] = $column;
                continue;
            }

            if (in_array($baseColumn, $validColumns, true)) {
                $valid[] = $column;
            } else {
                $invalid[] = $column;
            }
        }

        if (!empty($invalid) && $throwOnInvalid) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid column(s) for table "%s": %s. Valid columns: %s',
                    $model->getTable(),
                    implode(', ', $invalid),
                    implode(', ', $validColumns)
                )
            );
        }

        return $valid;
    }

    /**
     * Validate relation names against model's defined relations.
     *
     * @param Model $model
     * @param array|string $relations
     * @param bool $throwOnInvalid
     * @return array Valid relations
     * @throws \InvalidArgumentException
     */
    public static function validateRelations(
        Model $model,
        array|string $relations,
        bool $throwOnInvalid = false
    ): array {
        $relations = is_string($relations) ? [$relations] : $relations;

        // Get model's RELATIONS constant if available
        $modelClass = get_class($model);
        $definedRelations = defined("$modelClass::RELATIONS")
            ? $modelClass::RELATIONS
            : [];

        // Also check if methods exist on the model
        $invalid = [];
        $valid = [];

        foreach ($relations as $relation) {
            // Handle nested relations (e.g., "user.roles")
            $baseRelation = Str::before($relation, '.');

            // Skip 'all' keyword
            if ($baseRelation === 'all') {
                $valid[] = $relation;
                continue;
            }

            $isValid = in_array($baseRelation, $definedRelations, true)
                || method_exists($model, $baseRelation);

            if ($isValid) {
                $valid[] = $relation;
            } else {
                $invalid[] = $relation;
            }
        }

        if (!empty($invalid) && $throwOnInvalid) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid relation(s) for model "%s": %s. Available relations: %s',
                    class_basename($model),
                    implode(', ', $invalid),
                    implode(', ', $definedRelations)
                )
            );
        }

        return $valid;
    }

    /**
     * Extract column names from operator conditions.
     * Handles formats like "column|operator|value"
     *
     * @param array $conditions
     * @return array Column names
     */
    public static function extractColumnsFromConditions(array $conditions): array
    {
        $columns = [];

        foreach ($conditions as $key => $value) {
            if (is_numeric($key) && is_string($value)) {
                // Format: "column|operator|value"
                $parts = explode('|', $value, 3);
                if (isset($parts[0])) {
                    $columns[] = trim($parts[0]);
                }
            } elseif (is_string($key) && !in_array($key, ['and', 'or'], true)) {
                // Nested relation filters
                if (is_array($value)) {
                    $columns = array_merge(
                        $columns,
                        self::extractColumnsFromConditions($value)
                    );
                }
            }
        }

        return array_unique($columns);
    }

    /**
     * Clear cached columns for a model.
     *
     * @param Model $model
     * @return void
     */
    public static function clearCache(Model $model): void
    {
        $table = $model->getTable();
        $connection = $model->getConnectionName() ?: config('database.default');
        $cacheKey = "schema_columns_{$connection}_{$table}";
        Cache::forget($cacheKey);
    }
}