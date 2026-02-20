<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Validates column uniqueness within a Many-to-Many relationship scope
 * for BULK (array) operations, checking through the pivot table.
 *
 * Handles two levels of validation per item:
 *   1. Intra-array duplicates: the same value appears more than once in the payload.
 *   2. DB uniqueness via JOIN: checks that no other record linked to the owner
 *      through the pivot already has that value (optionally ignoring the current
 *      record's own PK — required for updates).
 *
 * The DB constraint checked is effectively:
 *   SELECT 1 FROM {mainTable}
 *   INNER JOIN {pivotTable} ON {pivotTable}.{pivotForeignKey} = {mainTable}.{mainTablePk}
 *   WHERE {mainTable}.{column}         = {value}
 *     AND {pivotTable}.{pivotOwnerKey} = {ownerValue}
 *    [AND {mainTable}.{mainTablePk}   != {ignoreValue}]   ← only on update
 *
 * -------------------------------------------------------------------------
 * EXAMPLE 1 — bulk_create_address
 * -------------------------------------------------------------------------
 * Scenario: user 42 already has an address with phone "+1-555-0001".
 *           A bulk request arrives with a duplicate in the array itself,
 *           and another item that collides with an existing DB record.
 *
 * Rule declaration in UsersRule.php:
 *
 *   'addresses.*.phone' => [
 *       'nullable', 'max:64',
 *       new UniqueInPivotArray(
 *           connection:      $this->connection,
 *           mainTable:       'security.addresses',
 *           pivotTable:      'security.user_addresses',
 *           pivotForeignKey: 'address_id',
 *           pivotOwnerKey:   'user_id',
 *           ownerValue:      auth()->id(),   // 42
 *           column:          'phone',
 *           arrayKey:        'addresses',
 *           // ignoreField omitted — this is a create
 *       ),
 *   ],
 *
 * HTTP request (fail — intra-array duplicate):
 *   POST /security/users/addresses/bulk
 *   {
 *     "addresses": [
 *       { "phone": "+1-555-8888", ... },   // addresses.0.phone
 *       { "phone": "+1-555-8888", ... }    // addresses.1.phone  ← same!
 *     ]
 *   }
 *   Check for addresses.0.phone:
 *     hasDuplicatesInArray() → "+1-555-8888" appears 2 times → fail
 *   → HTTP 422 on addresses.0.phone and addresses.1.phone
 *
 * HTTP request (fail — DB collision):
 *   {
 *     "addresses": [
 *       { "phone": "+1-555-0001", ... }   // already linked to user 42 in DB
 *     ]
 *   }
 *   Check for addresses.0.phone:
 *     hasDuplicatesInArray() → only 1 occurrence → pass
 *     existsInPivot():
 *       SELECT EXISTS(
 *           SELECT 1 FROM security.addresses AS _main
 *           INNER JOIN security.user_addresses AS _pivot
 *               ON _pivot.address_id = _main.id
 *           WHERE _main.phone    = '+1-555-0001'
 *             AND _pivot.user_id = 42
 *       )
 *       → true  →  fail
 *   → HTTP 422 on addresses.0.phone
 *
 * -------------------------------------------------------------------------
 * EXAMPLE 2 — bulk_update_address
 * -------------------------------------------------------------------------
 * Scenario: user 42 updates two addresses in a single request.
 *           address id=7 keeps its own phone → must pass (self-ignore).
 *           address id=9 tries to take the phone of address id=7 → must fail.
 *
 * Rule declaration in UsersRule.php:
 *
 *   'addresses.*.phone' => [
 *       'nullable', 'max:64',
 *       new UniqueInPivotArray(
 *           connection:      $this->connection,
 *           mainTable:       'security.addresses',
 *           pivotTable:      'security.user_addresses',
 *           pivotForeignKey: 'address_id',
 *           pivotOwnerKey:   'user_id',
 *           ownerValue:      auth()->id(),   // 42
 *           column:          'phone',
 *           arrayKey:        'addresses',
 *           ignoreField:     'id',           // ← exclude each item's own PK
 *       ),
 *   ],
 *
 * HTTP request:
 *   PUT /security/users/addresses/bulk
 *   {
 *     "addresses": [
 *       { "id": 7, "phone": "+1-555-0001", ... },   // addresses.0.phone  (own phone)
 *       { "id": 9, "phone": "+1-555-0001", ... }    // addresses.1.phone  (stolen phone!)
 *     ]
 *   }
 *
 *   Check for addresses.0.phone (value="+1-555-0001", item id=7):
 *     hasDuplicatesInArray() → appears 2 times in array → FAIL immediately
 *   → HTTP 422 on addresses.0.phone and addresses.1.phone
 *
 * HTTP request (no array duplicate, but DB collision on update):
 *   {
 *     "addresses": [
 *       { "id": 9, "phone": "+1-555-0001", ... }   // id=7's phone, different item
 *     ]
 *   }
 *
 *   Check for addresses.0.phone (value="+1-555-0001", item id=9):
 *     hasDuplicatesInArray() → only 1 occurrence → pass
 *     resolveIgnoreValue("addresses.0.phone") → index=0 → items[0]['id'] = 9
 *     existsInPivot():
 *       SELECT EXISTS(
 *           SELECT 1 FROM security.addresses AS _main
 *           INNER JOIN security.user_addresses AS _pivot
 *               ON _pivot.address_id = _main.id
 *           WHERE _main.phone    = '+1-555-0001'
 *             AND _pivot.user_id = 42
 *             AND _main.id      != 9        ← ignores item's own id
 *       )
 *       → finds address id=7  →  fail
 *   → HTTP 422 on addresses.0.phone
 *
 * HTTP request (valid — each address keeps its own phone):
 *   {
 *     "addresses": [
 *       { "id": 7, "phone": "+1-555-0001", "city": "Miami"   },
 *       { "id": 9, "phone": "+1-555-0099", "city": "Chicago" }
 *     ]
 *   }
 *   Check addresses.0.phone: no array dup + DB query finds nothing after ignoring id=7 → pass
 *   Check addresses.1.phone: no array dup + DB query finds nothing after ignoring id=9 → pass
 *   → HTTP 200
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

    /** Checks whether the value appears more than once among sibling items. */
    private function hasDuplicatesInArray(array $items, mixed $value): bool
    {
        $columnValues = array_filter(
            array_column($items, $this->column),
            static fn (mixed $v): bool => $v !== null && $v !== '',
        );

        return count(array_keys($columnValues, $value, strict: true)) > 1;
    }

    /**
     * Queries the DB via the pivot JOIN for a conflicting record,
     * optionally excluding the current item's own PK.
     */
    private function existsInPivot(string $attribute, array $items, mixed $value): bool
    {
        $query = DB::connection($this->connection)
            ->table("{$this->mainTable} as _main")
            ->join(
                "{$this->pivotTable} as _pivot",
                "_pivot.{$this->pivotForeignKey}",
                '=',
                "_main.{$this->mainTablePk}",
            )
            ->where("_main.{$this->column}", $value)
            ->where("_pivot.{$this->pivotOwnerKey}", $this->ownerValue);

        if ($this->ignoreField !== null) {
            $ignoreValue = $this->resolveIgnoreValue($attribute, $items);

            if ($ignoreValue !== null) {
                $query->where("_main.{$this->mainTablePk}", '!=', $ignoreValue);
            }
        }

        return $query->exists();
    }

    /**
     * Extracts the array index from the attribute path
     * (e.g. "addresses.2.phone" → 2) and returns the ignore-field
     * value of that sibling item (e.g. items[2]['id']).
     */
    private function resolveIgnoreValue(string $attribute, array $items): mixed
    {
        $index = $this->resolveIndex($attribute);

        return $index !== null ? ($items[$index][$this->ignoreField] ?? null) : null;
    }
}
