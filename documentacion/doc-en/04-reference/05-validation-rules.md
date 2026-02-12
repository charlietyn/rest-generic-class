# Validation Rules and Database Validation System

This package ships a complete system for validating arrays of IDs against the database, composed of six custom validation rules and a reusable trait. All rules implement Laravel 12's `ValidationRule` and `ValidatorAwareRule` for native integration with the validation pipeline.

---

## Architecture overview

```
BaseFormRequest
    └─ ValidatesExistenceInDatabase (trait)
           ├─ validateIdsExistInTable()
           ├─ validateIdsExistWithStatus()
           ├─ validateIdsExistNotDeleted()
           ├─ validateIdsExistWithAnyStatus()
           ├─ validateIdsExistWithDateRange()
           ├─ validateIdsWithCustomQuery()
           └─ getMissingIds()

Validation Rules (use the same trait internally):
    ├─ IdsExistInTable
    ├─ IdsExistNotDelete
    ├─ IdsExistWithAnyStatus
    ├─ IdsExistWithDateRange
    ├─ IdsWithCustomQuery
    └─ ArrayCount
```

All ID rules operate on **arrays**, not single values. If the value is not an array or is empty, validation passes (defensive pass-through). Internally they leverage Laravel's cache with a 1-hour TTL (configurable) to avoid hitting the database on every request.

---

## Trait `ValidatesExistenceInDatabase`

Namespace: `Ronu\RestGenericClass\Core\Traits\ValidatesExistenceInDatabase`

The trait is the central piece of the system. It can be used directly in any `FormRequest` or service class. It is already included in `BaseFormRequest`.

### Configurable properties

| Property | Type | Default | Description |
|---|---|---|---|
| `$validationCacheTtl` | `int` | `3600` | Cache TTL in seconds |
| `$enableValidationCache` | `bool` | `true` | Enable/disable validation cache |
| `$cacheKeyPrefix` | `string` | `'validation'` | Cache key prefix |
| `$connection` | `string` | `'db'` | Database connection name |

### Core methods

#### `validateIdsExistInTable(array $ids, string $table, string $column = 'id', array $additionalConditions = []): array|bool`

Checks that all IDs exist in the table with optional conditions. Returns an associative array:

```php
[
    'success'      => bool,
    'missing_ids'  => array,
    'existing_ids' => array,
]
```

```php
// Simple existence check
$result = $this->validateIdsExistInTable([1, 2, 3], 'users');

// With an extra condition (active users only)
$result = $this->validateIdsExistInTable([1, 2], 'users', 'id', ['status' => 'active']);

// Multiple conditions
$result = $this->validateIdsExistInTable([1, 2], 'users', 'id', [
    'status'   => 'active',
    'verified' => true,
]);

if (!$result['success']) {
    // Non-existing IDs: $result['missing_ids']
}
```

#### `validateIdsExistWithStatus(array $ids, string $table, string $status = 'active', string $statusColumn = 'status'): array|bool`

Shortcut for validating IDs with a specific status value.

```php
// Active roles
$this->validateIdsExistWithStatus([1, 2], 'roles');

// Published posts with a custom column
$this->validateIdsExistWithStatus([10, 20], 'posts', 'published', 'state');
```

#### `validateIdsExistNotDeleted(array $ids, string $table, string $column = 'id'): bool`

Excludes records where `deleted_at IS NOT NULL` (soft deletes).

```php
$valid = $this->validateIdsExistNotDeleted([1, 2, 3], 'departments');
```

#### `validateIdsExistWithAnyStatus(array $ids, string $table, array $statuses, string $statusColumn = 'status'): bool`

Accepts IDs that have any of the specified statuses (OR condition).

```php
// Users that are 'active' OR 'pending'
$valid = $this->validateIdsExistWithAnyStatus([1, 2], 'users', ['active', 'pending']);
```

#### `validateIdsExistWithDateRange(array $ids, string $table, string $dateColumn, ?string $startDate = null, ?string $endDate = null, array $additionalConditions = []): bool`

Validates that IDs exist and their date field falls within the specified range. Both bounds are optional.

```php
$valid = $this->validateIdsExistWithDateRange(
    [1, 2, 3],
    'orders',
    'created_at',
    now()->subDays(30)->toDateString(),
    now()->toDateString()
);
```

#### `validateIdsWithCustomQuery(array $ids, Closure $queryCallback, string $column = 'id'): bool`

Validation with a completely custom query. The callback receives a raw `Query\Builder` and must return the same (modified) builder.

```php
$valid = $this->validateIdsWithCustomQuery([1, 2], function ($query) {
    return $query->from('products')
                 ->where('price', '>', 100)
                 ->where('stock', '>', 0);
});
```

#### `getMissingIds(array $ids, string $table, string $column = 'id', array $additionalConditions = []): array`

Returns only the IDs that do **not** exist. Useful for building descriptive error messages.

```php
$missing = $this->getMissingIds([1, 2, 999], 'roles', 'id', ['status' => 'active']);
// [999]

if (!empty($missing)) {
    $msg = 'Invalid role IDs: ' . implode(', ', $missing);
}
```

#### `clearValidationCache(string $table): bool`

Clears the validation cache for a table. Works optimally with Redis; with other drivers returns `false` (the caller must manage invalidation).

```php
$this->clearValidationCache('users');
```

### Runtime cache control

```php
// Disable for this request
$this->disableValidationCaching();

// Reduce TTL to 5 minutes
$this->setValidationCacheTtl(300);

// Re-enable
$this->enableValidationCaching();
```

---

## Validation Rules

All rules are under the namespace `Ronu\RestGenericClass\Core\Rules` and take the **database connection name** as their first argument.

---

### `IdsExistInTable`

Verifies that all IDs in the array exist in the given table and column.

**Constructor:**
```php
new IdsExistInTable(
    string $connection,
    string $table,
    string $column = 'id'
)
```

**Basic usage:**
```php
use Ronu\RestGenericClass\Core\Rules\IdsExistInTable;

public function rules(): array
{
    return [
        'role_ids'   => ['required', 'array', new IdsExistInTable('mysql', 'roles')],
        'tag_ids'    => ['nullable', 'array', new IdsExistInTable('mysql', 'tags', 'id')],
        // Against a secondary connection
        'branch_ids' => ['required', 'array', new IdsExistInTable('pgsql', 'branches')],
    ];
}
```

**Error message produced:**
```
The following IDs do not exist: 5, 12, 99
```

---

### `IdsExistNotDelete`

Identical to `IdsExistInTable` but excludes soft-deleted records (`deleted_at IS NOT NULL`).

**Constructor:**
```php
new IdsExistNotDelete(
    string $connection,
    string $table,
    string $column = 'id'
)
```

**Typical usage (ensure the resource was not soft-deleted):**
```php
use Ronu\RestGenericClass\Core\Rules\IdsExistNotDelete;

'user_ids' => [
    'required',
    'array',
    new IdsExistNotDelete('mysql', 'users'),
],
```

> **Note:** The table must have the `deleted_at` column. If the column does not exist, the query will throw an exception that is caught internally and causes the rule to return `false`.

---

### `IdsExistWithAnyStatus`

Validates that the IDs exist with at least one of the specified statuses.

**Constructor:**
```php
new IdsExistWithAnyStatus(
    string $connection,
    string $table,
    array  $statuses,
    string $column = 'status'
)
```

**Usage:**
```php
use Ronu\RestGenericClass\Core\Rules\IdsExistWithAnyStatus;

// Accept active or trial clients
'client_ids' => [
    'required',
    'array',
    new IdsExistWithAnyStatus('mysql', 'clients', ['active', 'trial']),
],

// With a custom column name
'product_ids' => [
    'required',
    'array',
    new IdsExistWithAnyStatus('mysql', 'products', ['available', 'preorder'], 'state'),
],
```

---

### `IdsExistWithDateRange`

Validates that the IDs exist and their date field falls within the specified range.

**Constructor:**
```php
new IdsExistWithDateRange(
    string  $connection,
    string  $table,
    string  $dateColumn,
    ?string $startDate            = null,
    ?string $endDate              = null,
    array   $additionalConditions = []
)
```

**Usage:**
```php
use Ronu\RestGenericClass\Core\Rules\IdsExistWithDateRange;

// Orders from the current month
'order_ids' => [
    'required',
    'array',
    new IdsExistWithDateRange(
        connection:  'mysql',
        table:       'orders',
        dateColumn:  'created_at',
        startDate:   now()->startOfMonth()->toDateString(),
        endDate:     now()->endOfMonth()->toDateString(),
    ),
],

// Upper bound only (before a specific date)
'invoice_ids' => [
    'required',
    'array',
    new IdsExistWithDateRange('mysql', 'invoices', 'issued_at', null, '2024-12-31'),
],

// With additional conditions
'shift_ids' => [
    'required',
    'array',
    new IdsExistWithDateRange(
        'mysql',
        'shifts',
        'date',
        now()->subDays(7)->toDateString(),
        now()->toDateString(),
        ['status' => 'completed']
    ),
],
```

> **Behaviour:** Unlike other rules, `IdsExistWithDateRange` queries directly against the provided IDs (does not cache the full table) since the range is dynamic.

---

### `IdsWithCustomQuery`

The most flexible rule: accepts a `Closure` that receives a raw `Query\Builder` and must return the same configured builder. The resulting count is compared against the number of IDs.

**Constructor:**
```php
new IdsWithCustomQuery(
    string  $connection,
    Closure $queryCallback,
    string  $column = 'id'
)
```

**Usage:**
```php
use Ronu\RestGenericClass\Core\Rules\IdsWithCustomQuery;

// Available products with stock > 0
'product_ids' => [
    'required',
    'array',
    new IdsWithCustomQuery('mysql', function ($query) {
        return $query->from('products')
                     ->where('active', 1)
                     ->where('stock', '>', 0);
    }),
],

// Active memberships (future expiry)
'membership_ids' => [
    'required',
    'array',
    new IdsWithCustomQuery('mysql', function ($query) {
        return $query->from('memberships')
                     ->where('expires_at', '>', now());
    }),
],

// Complex joins
'employee_ids' => [
    'required',
    'array',
    new IdsWithCustomQuery('mysql', function ($query) {
        return $query->from('employees')
                     ->join('departments', 'employees.department_id', '=', 'departments.id')
                     ->where('departments.active', true)
                     ->where('employees.status', 'active')
                     ->select('employees.id');
    }),
],
```

> **Important:** The callback receives an `\Illuminate\Database\Query\Builder` (not Eloquent). The `whereIn($column, $ids)` is applied **after** the callback configures the base query.

---

### `ArrayCount`

Validates the number of elements in an array. Supports minimum, maximum, exact, or range, with fully customisable error messages at three priority levels.

**Constructor:**
```php
new ArrayCount(
    ?int    $min      = null,
    ?int    $max      = null,
    ?string $message  = null,   // Level 1: global message
    array   $messages = [],     // Level 2: per-scenario messages
)
```

At least one of `$min` or `$max` is **required**. If `$min > $max`, an `\InvalidArgumentException` is thrown at construction time.

**Scenarios and tokens:**

| Scenario key | When triggered | Available tokens |
|---|---|---|
| `onNotArray` | Value is not an array | `:attribute`, `:count` |
| `onExact` | `min === max` and count differs | `:attribute`, `:min`, `:max`, `:count` |
| `onBetween` | `min !== null && max !== null` and out of range | `:attribute`, `:min`, `:max`, `:count` |
| `onMin` | Only `min` set and count is less | `:attribute`, `:min`, `:count` |
| `onMax` | Only `max` set and count is greater | `:attribute`, `:max`, `:count` |

**Message priority:** `messages[scenario]` > `message` > built-in default.

**Examples:**

```php
use Ronu\RestGenericClass\Core\Rules\ArrayCount;

// Max only (max 1 address)
'address_ids' => [
    'required',
    'array',
    new ArrayCount(max: 1, message: 'Only one shipping address is allowed.'),
],

// Min only
'tag_ids' => [
    'required',
    'array',
    new ArrayCount(min: 1),
],

// Exactly N elements
'player_ids' => [
    'required',
    'array',
    new ArrayCount(min: 11, max: 11, message: 'A team must have exactly 11 players.'),
],

// Range with per-scenario messages
'item_ids' => [
    'required',
    'array',
    new ArrayCount(
        min: 2,
        max: 10,
        messages: [
            'onMin'     => 'You must select at least :min items.',
            'onMax'     => 'You cannot select more than :max items.',
            'onBetween' => 'Select between :min and :max items (you sent :count).',
        ]
    ),
],

// Tokens in a global message
'photo_ids' => [
    'nullable',
    'array',
    new ArrayCount(
        max: 5,
        message: 'The :attribute field accepts a maximum of :max photos (you sent :count).'
    ),
],
```

> **Why `ValidatorAwareRule`:** Laravel internally calls `Str::upper()` on the raw value when building replacement messages — which causes "Array to string conversion" for arrays. `ArrayCount` pushes messages directly to `errors()->add()`, bypassing that pipeline entirely.

---

## `BaseFormRequest`: advanced validation

`BaseFormRequest` already includes the `ValidatesExistenceInDatabase` trait and exposes additional methods for complex scenarios.

### `addMessageValidator(Closure $callback): Closure`

Registers a deferred validation callback that runs **after** Laravel builds the `Validator`. This is necessary when you need to access `$validator->errors()` safely or when validation logic depends on previously validated fields.

```php
public function rules(): array
{
    return [
        'users'   => ['required', 'array'],
        'users.*' => ['integer'],
        // The returned closure is a no-op for Laravel;
        // the real logic runs in withValidator() via pendingValidatorCallbacks
        'users'   => $this->addMessageValidator(function (Validator $validator) {
            $ids     = $this->input('users', []);
            $missing = $this->getMissingIds($ids, 'users', 'id', ['active' => true]);
            if (!empty($missing)) {
                $validator->errors()->add(
                    'users',
                    'The following users do not exist or are inactive: ' . implode(', ', $missing)
                );
            }
        }),
    ];
}
```

### `withValidator(Validator $validator): void`

Native Laravel hook that the package uses to flush all callbacks registered with `addMessageValidator()`. You do not need to override it in your `FormRequest`; if you do, call `parent::withValidator($validator)` to preserve the mechanism.

### `validateIdsWithRelation(array $ids, string $table, string $relationColumn, int|string $relationValue, array $additionalConditions = []): bool`

Validates that IDs exist **and** belong to a specific parent resource.

```php
// In the FormRequest for a nested route: /departments/{department}/employees
protected function validateEmployeesBelongToDepartment(array $ids): bool
{
    return $this->validateIdsWithRelation(
        $ids,
        'employees',
        'department_id',
        $this->route('department')
    );
}
```

### `validateUnique(string $value, string $table, string $column, ?int|string $excludeId = null, array $additionalConditions = []): bool`

Uniqueness validation excluding the current record (useful in `update` scenarios).

```php
public function rules(): array
{
    $userId = $this->route('user');

    return [
        'email' => [
            'required',
            'email',
            function ($attribute, $value, $fail) use ($userId) {
                if (!$this->validateUnique($value, 'users', 'email', $userId)) {
                    $fail('This email is already registered by another user.');
                }
            },
        ],
    ];
}
```

### `getMissingIdsMessage(array $ids, string $table, string $resourceName, array $conditions = []): ?string`

Returns a formatted error string or `null` if all IDs exist.

```php
$error = $this->getMissingIdsMessage([1, 2, 999], 'roles', 'roles', ['active' => 1]);
// "The following roles IDs do not exist or are invalid: 999"
```

### `validatedWith(array $additionalData): array`

Merges validated data with additional computed data (e.g., the authenticated user's ID).

```php
public function store(MyFormRequest $request): JsonResponse
{
    $data = $request->validatedWith([
        'created_by' => auth()->id(),
        'tenant_id'  => request()->header('X-Tenant-Id'),
    ]);

    $this->service->create($data);
    // ...
}
```

### `isCreating()` / `isUpdating()`

Semantic helpers for conditional rule logic.

```php
public function rules(): array
{
    return [
        'password' => $this->isCreating() ? ['required', 'min:8'] : ['nullable', 'min:8'],
        'email'    => [
            'required',
            'email',
            $this->isUpdating()
                ? Rule::unique('users')->ignore($this->route('user'))
                : Rule::unique('users'),
        ],
    ];
}
```

---

## Combined usage patterns

### Pattern 1: FormRequest with multiple ID rules

```php
<?php

namespace App\Http\Requests;

use Ronu\RestGenericClass\Core\Requests\BaseFormRequest;
use Ronu\RestGenericClass\Core\Rules\IdsExistInTable;
use Ronu\RestGenericClass\Core\Rules\IdsExistNotDelete;
use Ronu\RestGenericClass\Core\Rules\IdsExistWithAnyStatus;
use Ronu\RestGenericClass\Core\Rules\ArrayCount;

class OrderStoreRequest extends BaseFormRequest
{
    protected string $entity_name = 'order';

    public function rules(): array
    {
        return [
            'product_ids' => [
                'required',
                'array',
                new ArrayCount(min: 1, max: 50, messages: [
                    'onMin' => 'The order must include at least one product.',
                    'onMax' => 'Orders cannot include more than :max products.',
                ]),
                new IdsExistWithAnyStatus('mysql', 'products', ['active', 'featured']),
            ],
            'coupon_ids' => [
                'nullable',
                'array',
                new ArrayCount(max: 3, message: 'A maximum of 3 coupons per order.'),
                new IdsExistNotDelete('mysql', 'coupons'),
            ],
            'address_id' => [
                'required',
                'integer',
                // Validate the address belongs to the authenticated user
                function ($attribute, $value, $fail) {
                    if (!$this->validateIdsExistInTable([$value], 'addresses', 'id', [
                        'user_id' => auth()->id(),
                    ])['success']) {
                        $fail('The address does not belong to your account.');
                    }
                },
            ],
        ];
    }
}
```

### Pattern 2: Deferred validation with `addMessageValidator`

```php
<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;
use Ronu\RestGenericClass\Core\Requests\BaseFormRequest;

class BulkAssignRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'user_ids'         => ['required', 'array'],
            'user_ids.*'       => ['integer', 'min:1'],
            'permission_ids'   => ['required', 'array'],
            'permission_ids.*' => ['integer', 'min:1'],

            // Deferred validation: needs the Validator to be built first
            '_deferred_users' => $this->addMessageValidator(
                function (Validator $v) {
                    $ids     = $this->input('user_ids', []);
                    $missing = $this->getMissingIds($ids, 'users', 'id', ['active' => true]);
                    if (!empty($missing)) {
                        $v->errors()->add(
                            'user_ids',
                            'Inactive or non-existent users: ' . implode(', ', $missing)
                        );
                    }
                }
            ),
        ];
    }
}
```

### Pattern 3: Dynamic date range from request input

```php
<?php

namespace App\Http\Requests;

use Ronu\RestGenericClass\Core\Requests\BaseFormRequest;
use Ronu\RestGenericClass\Core\Rules\IdsExistWithDateRange;

class ReportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $startDate = $this->input('start_date');
        $endDate   = $this->input('end_date');

        return [
            'start_date'  => ['required', 'date'],
            'end_date'    => ['required', 'date', 'after_or_equal:start_date'],
            'invoice_ids' => [
                'required',
                'array',
                new IdsExistWithDateRange(
                    'mysql',
                    'invoices',
                    'issued_at',
                    $startDate,
                    $endDate,
                    ['status' => 'paid']
                ),
            ],
        ];
    }
}
```

### Pattern 4: Custom query with complex business logic

```php
use Ronu\RestGenericClass\Core\Rules\IdsWithCustomQuery;

'slot_ids' => [
    'required',
    'array',
    new ArrayCount(min: 1, max: 5),
    new IdsWithCustomQuery('mysql', function ($query) {
        // Available slots: not booked AND in the future
        return $query->from('appointment_slots')
                     ->where('available', true)
                     ->where('starts_at', '>', now())
                     ->whereNotExists(function ($sub) {
                         $sub->from('appointments')
                             ->whereColumn('appointments.slot_id', 'appointment_slots.id')
                             ->where('appointments.status', '!=', 'cancelled');
                     });
    }),
],
```

---

## Error handling and logging

All ID rules capture database exceptions internally and log them via `Log::error()` on the `rest-generic-class` channel. On error, validation returns `false` (fail-safe). Default error messages are:

```
The following IDs do not exist: 5, 12
```

For more descriptive messages, use `getMissingIds()` or `getMissingIdsMessage()` directly in the `FormRequest`.

---

## Performance considerations

| Rule | Cache strategy | When cache is NOT used |
|---|---|---|
| `IdsExistInTable` | Full table pluck cached | When `enableValidationCache = false` |
| `IdsExistNotDelete` | Non-deleted IDs cached | Same |
| `IdsExistWithAnyStatus` | Cached per status combination | Same |
| `IdsExistWithDateRange` | **No cache** (dynamic range) | Always direct query |
| `IdsWithCustomQuery` | **No cache** (arbitrary logic) | Always direct query |
| `ArrayCount` | N/A (no database) | N/A |

> For `IdsExistWithDateRange` and `IdsWithCustomQuery`, if performance is critical, consider pre-validating IDs with `IdsExistInTable` first and using the date/custom rule only for the additional condition.

---

## Evidence

- `src/Core/Rules/IdsExistInTable.php` — Basic existence rule
- `src/Core/Rules/IdsExistNotDelete.php` — Rule with soft deletes
- `src/Core/Rules/IdsExistWithAnyStatus.php` — Rule with multiple statuses
- `src/Core/Rules/IdsExistWithDateRange.php` — Rule with date range
- `src/Core/Rules/IdsWithCustomQuery.php` — Rule with custom query
- `src/Core/Rules/ArrayCount.php` — Array count rule
- `src/Core/Traits/ValidatesExistenceInDatabase.php` — Base trait with all logic
- `src/Core/Requests/BaseFormRequest.php` — FormRequest with trait integration and additional helpers

[Back to documentation index](../index.md)
