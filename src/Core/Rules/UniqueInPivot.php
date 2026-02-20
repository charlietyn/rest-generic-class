<?php

namespace Ronu\RestGenericClass\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Validates that a column value is unique within the scope of a Many-to-Many
 * relationship, checking through the pivot table.
 *
 * Use this for SINGLE operations (create / update).
 * For bulk (array) operations use UniqueInPivotArray instead.
 *
 * The constraint checked is effectively:
 *   SELECT 1 FROM {mainTable}
 *   INNER JOIN {pivotTable} ON {pivotTable}.{pivotForeignKey} = {mainTable}.{mainTablePk}
 *   WHERE {mainTable}.{column}         = {value}
 *     AND {pivotTable}.{pivotOwnerKey} = {ownerValue}
 *    [AND {mainTable}.{mainTablePk}   != {ignoreValue}]   ← only on update
 *
 * -------------------------------------------------------------------------
 * EXAMPLE 1 — create_address
 * -------------------------------------------------------------------------
 * Scenario: user 42 already has an address with phone "+1-555-0001".
 *           A new address arrives with the same phone → must fail.
 *
 * Rule declaration in UsersRule.php:
 *
 *   'phone' => [
 *       'nullable', 'max:64',
 *       new UniqueInPivot(
 *           connection:      $this->connection,
 *           mainTable:       'security.addresses',
 *           pivotTable:      'security.user_addresses',
 *           pivotForeignKey: 'address_id',
 *           pivotOwnerKey:   'user_id',
 *           ownerValue:      auth()->id(),   // 42
 *           column:          'phone',
 *           // ignoreValue omitted — this is a create, no record to skip
 *       ),
 *   ],
 *
 * HTTP request:
 *   POST /security/users/addresses
 *   { "first_name": "John", "phone": "+1-555-0001", ... }
 *
 * SQL executed:
 *   SELECT EXISTS(
 *       SELECT 1 FROM security.addresses AS _main
 *       INNER JOIN security.user_addresses AS _pivot
 *           ON _pivot.address_id = _main.id
 *       WHERE _main.phone    = '+1-555-0001'
 *         AND _pivot.user_id = 42
 *   )
 *   → true  →  validation fails  →  HTTP 422
 *
 * -------------------------------------------------------------------------
 * EXAMPLE 2 — update_address
 * -------------------------------------------------------------------------
 * Scenario: user 42 edits address id=7 (which already has "+1-555-0001").
 *           Sending the same phone must pass (own record).
 *           Sending the phone of address id=9 must fail (belongs to same user).
 *
 * Rule declaration in UsersRule.php:
 *
 *   'phone' => [
 *       'nullable', 'max:64',
 *       new UniqueInPivot(
 *           connection:      $this->connection,
 *           mainTable:       'security.addresses',
 *           pivotTable:      'security.user_addresses',
 *           pivotForeignKey: 'address_id',
 *           pivotOwnerKey:   'user_id',
 *           ownerValue:      auth()->id(),       // 42
 *           column:          'phone',
 *           ignoreValue:     $this->route('id'), // 7  ← route: PUT /users/addresses/7
 *       ),
 *   ],
 *
 * HTTP request (pass — own phone):
 *   PUT /security/users/addresses/7
 *   { "phone": "+1-555-0001", ... }
 *
 *   SQL: ... WHERE phone = '+1-555-0001' AND user_id = 42 AND _main.id != 7
 *   → no other address found  →  validation passes  →  HTTP 200
 *
 * HTTP request (fail — another address's phone):
 *   PUT /security/users/addresses/7
 *   { "phone": "+1-555-0099", ... }   // phone already on address id=9
 *
 *   SQL: ... WHERE phone = '+1-555-0099' AND user_id = 42 AND _main.id != 7
 *   → finds address id=9  →  validation fails  →  HTTP 422
 */
final class UniqueInPivot implements ValidationRule
{
    public function __construct(
        private readonly string  $connection,
        private readonly string  $mainTable,
        private readonly string  $pivotTable,
        private readonly string  $pivotForeignKey,
        private readonly string  $pivotOwnerKey,
        private readonly mixed   $ownerValue,
        private readonly string  $column,
        private readonly string  $mainTablePk  = 'id',
        private readonly mixed   $ignoreValue  = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($this->existsInPivot($value)) {
            $fail(__('validation.unique'));
        }
    }

    private function existsInPivot(mixed $value): bool
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

        if ($this->ignoreValue !== null) {
            $query->where("_main.{$this->mainTablePk}", '!=', $this->ignoreValue);
        }

        return $query->exists();
    }
}
