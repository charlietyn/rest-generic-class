<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Ronu\RestGenericClass\Core\Validation\UniqueValidationSupport;

/**
 * Validates uniqueness of a column in a DB table for bulk array operations,
 * supporting composite conditions and per-item ignore values for updates.
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
        private readonly ?string $softDeleteColumn = null,
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
        return UniqueValidationSupport::buildArrayMessage(
            $attribute,
            $value,
            $this->column,
            $this->arrayKey,
            $duplicate
        );
    }

    /** Checks whether the value appears more than once in sibling items. */
    private function hasDuplicatesInArray(array $items, mixed $value): bool
    {
        return UniqueValidationSupport::hasDuplicateValue($items, $this->column, $value);
    }

    /** Queries the DB for a conflicting record, optionally excluding the current item. */
    private function existsInDatabase(string $attribute, array $items, mixed $value): bool
    {
        $ignoreValue = UniqueValidationSupport::resolveIgnoreValue(
            $attribute,
            $items,
            $this->arrayKey,
            $this->ignoreField
        );

        return UniqueValidationSupport::compositeExists(
            $this->connection,
            $this->table,
            $this->column,
            $value,
            $this->conditions,
            $this->ignoreField,
            $ignoreValue,
            $this->softDeleteColumn
        );
    }
}
