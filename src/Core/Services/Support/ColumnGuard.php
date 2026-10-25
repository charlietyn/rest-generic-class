<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;
use WeakMap;

/**
 * The one place that decides whether a client may name a column in a query (shared with the NestJS libraries' `queryableColumn`).
 *
 * - Hidden (`$model->getHidden()`, timestamps included): never a filter or an ordering, because
 *   a `like` on a value the response hides recovers it one character at a time
 *   (`password|like|$2y$%`). In `select` it is dropped, not refused: it never appears anyway.
 *   `filtering.allow_hidden_columns=true` restores the old behaviour.
 * - Unknown (`filtering.strict_column_validation`, default true): a column the table does not
 *   have is a 400 before any SQL runs. It used to reach the database and come back as a 500 that
 *   echoed the generated SQL — and the client's text, e.g. `name) OR (1=1`. A column qualified
 *   with another table, or an expression, is unknown too. The listing is read once per connection
 *   and table; when it cannot be read (empty), only the identifier syntax is checked.
 */
class ColumnGuard
{
    /** @var WeakMap<Connection, array<string, string[]>>|null */
    private static ?WeakMap $listings = null;

    /**
     * 400 unless `$column` of `$model` may be used to filter or sort.
     *
     * @param string $use 'filter' | 'sort'
     */
    public static function assertQueryable(Model $model, string $column, string $use): void
    {
        $column = trim($column);
        if (!self::isOwn($model, $column)) {
            // Another table's column: unknown here in strict mode, else none of this model's business.
            if (self::strict()) {
                throw self::unknown($model, $column);
            }
            return;
        }

        $name = self::bareName($column);
        if (self::isHidden($model, $name)) {
            throw new HttpException(400, "Column '{$name}' of " . class_basename($model) . " cannot be used to {$use}.");
        }

        self::assertExists($model, $name);
    }

    /**
     * The `select` list without the columns the model hides (400 for an unknown one); `null` when
     * nothing is left, and the caller then selects the whole row, which serialises without them.
     */
    public static function selectable(Model $model, array $columns): ?array
    {
        $kept = [];
        foreach ($columns as $column) {
            if (!is_string($column)) {
                $kept[] = $column;
                continue;
            }
            $expression = self::expression($column);
            if (!self::isOwn($model, $expression)) {
                if (self::strict()) {
                    throw self::unknown($model, $expression);
                }
                $kept[] = $column;
                continue;
            }
            $name = self::bareName($expression);
            if (self::isHidden($model, $name)) {
                continue;
            }
            if ($name !== '*') {
                self::assertExists($model, $name);
            }
            $kept[] = $column;
        }

        return $kept === [] ? null : $kept;
    }

    /** `$name` is unqualified. */
    private static function assertExists(Model $model, string $name): void
    {
        if (!self::strict()) {
            return;
        }

        $column = $name;
        $listing = self::columns($model);
        $known = $listing === []
            ? (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)
            : in_array(strtolower($column), array_map('strtolower', $listing), true);

        if (!$known) {
            throw self::unknown($model, $name);
        }
    }

    /** Unqualified, or qualified with the model's own table (`contract_users.name`, `schema.table.name`). */
    private static function isOwn(Model $model, string $column): bool
    {
        $column = explode('->', $column, 2)[0];
        $dot = strrpos($column, '.');
        if ($dot === false) {
            return true;
        }

        $qualifier = substr($column, 0, $dot);
        $table = self::table($model);
        $own = [$table, $model->getTable()];
        if (str_contains($table, '.')) {
            $own[] = substr($table, strrpos($table, '.') + 1);
        }

        return in_array($qualifier, $own, true);
    }

    private static function strict(): bool
    {
        return (bool) self::config('filtering.strict_column_validation', true);
    }

    private static function unknown(Model $model, string $column): HttpException
    {
        return new HttpException(400, "Column '{$column}' does not exist on " . self::table($model) . '.');
    }

    /** @return string[] */
    private static function columns(Model $model): array
    {
        $connection = $model->getConnection();
        $table = self::table($model);
        self::$listings ??= new WeakMap();
        $cached = self::$listings[$connection] ?? [];

        if (!array_key_exists($table, $cached)) {
            try {
                $cached[$table] = $connection->getSchemaBuilder()->getColumnListing($table);
            } catch (\Throwable) {
                $cached[$table] = [];
            }
            self::$listings[$connection] = $cached;
        }

        return $cached[$table];
    }

    /** The real table: inside a self-relation whereHas the model carries Laravel's alias instead. */
    private static function table(Model $model): string
    {
        $table = $model->getTable();
        if (str_starts_with($table, 'laravel_reserved_')) {
            $class = get_class($model);
            $table = (new $class)->getTable();
        }

        return $table;
    }

    private static function isHidden(Model $model, string $column): bool
    {
        if (self::config('filtering.allow_hidden_columns', false)) {
            return false;
        }

        return in_array($column, $model->getHidden(), true);
    }

    /** `users.password` → `password`; `password->hash` → `password` (Laravel's JSON path syntax). */
    private static function bareName(string $column): string
    {
        $column = explode('->', trim($column), 2)[0];
        $dot = strrpos($column, '.');

        return $dot === false ? $column : substr($column, $dot + 1);
    }

    /** `name as label` → `name`. */
    private static function expression(string $column): string
    {
        return preg_split('/\s+as\s+/i', trim($column), 2)[0];
    }

    private static function config(string $key, mixed $default): mixed
    {
        try {
            return config("rest-generic-class.{$key}", $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
