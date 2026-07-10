<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Ronu\RestGenericClass\Core\Validation\UniqueValidationSupport;

/**
 * Validates column uniqueness within a many-to-many relationship scope for
 * bulk array operations, checking request duplicates and pivot-table conflicts.
 */
final class UniqueInPivotArray implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly string  $connection,
        private readonly string  $mainTable,
        private readonly string  $pivotTable,
        private readonly string  $pivotForeignKey,
        private readonly string  $pivotOwnerKey,
        private readonly mixed   $ownerValue,
        private readonly string  $column,
        private readonly string  $arrayKey,
        private readonly string  $mainTablePk  = 'id',
        private readonly ?string $ignoreField  = null,
        private readonly ?string $softDeleteColumn = null,
        private readonly ?string $pivotSoftDeleteColumn = null,
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

        if ($this->existsInPivot($attribute, $items, $value)) {
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

    /** Checks whether the value appears more than once among sibling items. */
    private function hasDuplicatesInArray(array $items, mixed $value): bool
    {
        return UniqueValidationSupport::hasDuplicateValue($items, $this->column, $value, ignoreEmptyValues: true);
    }

    /**
     * Queries the DB via the pivot JOIN for a conflicting record,
     * optionally excluding the current item's own PK.
     */
    private function existsInPivot(string $attribute, array $items, mixed $value): bool
    {
        $ignoreValue = UniqueValidationSupport::resolveIgnoreValue(
            $attribute,
            $items,
            $this->arrayKey,
            $this->ignoreField
        );

        return UniqueValidationSupport::pivotExists(
            $this->connection,
            $this->mainTable,
            $this->pivotTable,
            $this->pivotForeignKey,
            $this->pivotOwnerKey,
            $this->ownerValue,
            $this->column,
            $value,
            $this->mainTablePk,
            $ignoreValue,
            $this->softDeleteColumn,
            $this->pivotSoftDeleteColumn
        );
    }
}
