<?php

namespace Ronu\RestGenericClass\Core\Validation;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class UniqueValidationSupport
{
    public static function resolveIndex(string $attribute, string $arrayKey): ?int
    {
        $pattern = '/^' . preg_quote($arrayKey, '/') . '\.(\d+)\./';

        return preg_match($pattern, $attribute, $matches) ? (int) $matches[1] : null;
    }

    public static function buildArrayMessage(
        string $attribute,
        mixed $value,
        string $column,
        string $arrayKey,
        bool $duplicate
    ): string {
        $index = self::resolveIndex($attribute, $arrayKey);
        $position = $index !== null ? "{$arrayKey}[{$index}]" : $attribute;

        return $duplicate
            ? "The {$column} '{$value}' is duplicated in the request at {$position}."
            : "The {$column} '{$value}' at {$position} has already been taken.";
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public static function hasDuplicateValue(
        array $items,
        string $column,
        mixed $value,
        bool $ignoreEmptyValues = false
    ): bool {
        $columnValues = array_column($items, $column);

        if ($ignoreEmptyValues) {
            $columnValues = array_filter(
                $columnValues,
                static fn (mixed $currentValue): bool => $currentValue !== null && $currentValue !== '',
            );
        }

        return count(array_keys($columnValues, $value, strict: true)) > 1;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public static function resolveIgnoreValue(
        string $attribute,
        array $items,
        string $arrayKey,
        ?string $ignoreField
    ): mixed {
        if ($ignoreField === null) {
            return null;
        }

        $index = self::resolveIndex($attribute, $arrayKey);

        return $index !== null ? ($items[$index][$ignoreField] ?? null) : null;
    }

    /**
     * @param array<string, mixed> $conditions
     */
    public static function compositeExists(
        string $connection,
        string $table,
        string $column,
        mixed $value,
        array $conditions = [],
        ?string $ignoreField = null,
        mixed $ignoreValue = null,
        ?string $softDeleteColumn = null
    ): bool {
        $query = DB::connection($connection)
            ->table($table)
            ->where($column, $value);

        if ($softDeleteColumn !== null) {
            $query->whereNull($softDeleteColumn);
        }

        self::applyConditions($query, $conditions);

        if ($ignoreField !== null && $ignoreValue !== null) {
            $query->where($ignoreField, '!=', $ignoreValue);
        }

        return $query->exists();
    }

    public static function pivotExists(
        string $connection,
        string $mainTable,
        string $pivotTable,
        string $pivotForeignKey,
        string $pivotOwnerKey,
        mixed $ownerValue,
        string $column,
        mixed $value,
        string $mainTablePk = 'id',
        mixed $ignoreValue = null,
        ?string $softDeleteColumn = null,
        ?string $pivotSoftDeleteColumn = null
    ): bool {
        $query = DB::connection($connection)
            ->table("{$mainTable} as _main")
            ->join(
                "{$pivotTable} as _pivot",
                "_pivot.{$pivotForeignKey}",
                '=',
                "_main.{$mainTablePk}",
            )
            ->where("_main.{$column}", $value)
            ->where("_pivot.{$pivotOwnerKey}", $ownerValue);

        if ($softDeleteColumn !== null) {
            $query->whereNull("_main.{$softDeleteColumn}");
        }

        if ($pivotSoftDeleteColumn !== null) {
            $query->whereNull("_pivot.{$pivotSoftDeleteColumn}");
        }

        if ($ignoreValue !== null) {
            $query->where("_main.{$mainTablePk}", '!=', $ignoreValue);
        }

        return $query->exists();
    }

    /**
     * @param Builder $query
     * @param array<string, mixed> $conditions
     */
    private static function applyConditions(Builder $query, array $conditions): void
    {
        foreach ($conditions as $column => $conditionValue) {
            $query->where($column, $conditionValue);
        }
    }
}
