<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Validates uniqueness of a column in a DB table for bulk (array) operations,
 * supporting composite conditions and per-item ignore (for updates).
 *
 * Usage — bulk create:
 *   new UniqueCompositeInArray(
 *       connection:  $this->connection,
 *       table:       'location.states',
 *       column:      'name',
 *       arrayKey:    'states',
 *       conditions:  ['country_id' => $this->parent_id],
 *   )
 *
 * Usage — bulk update (ignores the record's own ID):
 *   new UniqueCompositeInArray(
 *       connection:  $this->connection,
 *       table:       'location.states',
 *       column:      'name',
 *       arrayKey:    'states',
 *       conditions:  ['country_id' => $this->parent_id],
 *       ignoreField: 'id',
 *   )
 */
final class UniqueCompositeInArray implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly string  $connection,
        private readonly string  $table,
        private readonly string  $column,
        private readonly string  $arrayKey,
        private readonly array   $conditions  = [],
        private readonly ?string $ignoreField = null,
    ) {}

    /** Injected automatically by Laravel's validator. */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $items = $this->data[$this->arrayKey] ?? [];

        if ($this->hasDuplicatesInArray($items, $value)) {
            $fail($this->buildMessage($attribute, $value, duplicate: true));

            return;
        }

        if ($this->existsInDatabase($attribute, $items, $value)) {
            $fail($this->buildMessage($attribute, $value, duplicate: false));
        }
    }

    private function buildMessage(string $attribute, mixed $value, bool $duplicate): string
    {
        $index    = $this->resolveIndex($attribute);
        $position = $index !== null ? "{$this->arrayKey}[{$index}]" : $attribute;

        return $duplicate
            ? "The {$this->column} '{$value}' is duplicated in the request at {$position}."
            : "The {$this->column} '{$value}' at {$position} has already been taken.";
    }

    private function resolveIndex(string $attribute): ?int
    {
        $pattern = '/^' . preg_quote($this->arrayKey, '/') . '\.(\d+)\./';

        return preg_match($pattern, $attribute, $matches) ? (int) $matches[1] : null;
    }

    /** Checks whether the value appears more than once in sibling items. */
    private function hasDuplicatesInArray(array $items, mixed $value): bool
    {
        $columnValues = array_column($items, $this->column);

        return count(array_keys($columnValues, $value, strict: true)) > 1;
    }

    /** Queries the DB for a conflicting record, optionally excluding the current item. */
    private function existsInDatabase(string $attribute, array $items, mixed $value): bool
    {
        $query = DB::connection($this->connection)
            ->table($this->table)
            ->where($this->column, $value);

        foreach ($this->conditions as $column => $conditionValue) {
            $query->where($column, $conditionValue);
        }

        if ($this->ignoreField !== null) {
            $ignoreValue = $this->resolveIgnoreValue($attribute, $items);

            if ($ignoreValue !== null) {
                $query->where($this->ignoreField, '!=', $ignoreValue);
            }
        }

        return $query->exists();
    }

    /**
     * Extracts the array index from the attribute path (e.g. "states.2.name" → 2)
     * and returns the ignore-field value of that sibling item.
     */
    private function resolveIgnoreValue(string $attribute, array $items): mixed
    {
        $index = $this->resolveIndex($attribute);

        return $index !== null ? ($items[$index][$this->ignoreField] ?? null) : null;
    }
}
