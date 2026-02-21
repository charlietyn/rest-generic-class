# Many-to-many relationships

This section covers the `ManagesManyToMany` trait, which provides generic read and mutation methods for controllers that need to expose many-to-many relationships with full filtering, pagination, ordering, and configurable CRUD/pivot operations.

## Overview

The trait is designed to be used in any controller that manages a `BelongsToMany` relationship. It supports:

- **Listing** related entities with filters, ordering, pagination, and eager-loading.
- **Showing** a single related entity.
- **Creating** related entities (single or bulk).
- **Updating** related entities (single or bulk).
- **Deleting** related entities (with optional model deletion).
- **Attaching/detaching** existing entities (pivot-only operations).
- **Syncing** the full relationship set with pivot data.
- **Toggling** specific IDs in the relationship with pivot data.
- **Updating pivot** fields without modifying the related model.

### Registering the trait

Add `use ManagesManyToMany;` to your controller and define the `$manyToManyConfig` property:

```php
use Ronu\RestGenericClass\Core\Traits\ManagesManyToMany;

class UserController extends Controller
{
    use ManagesManyToMany;

    protected array $manyToManyConfig = [
        'addresses' => [
            'relationship'  => 'array_address',
            'relatedModel'  => Addresses::class,
            'pivotModel'    => UserAddresses::class,
            'parentModel'   => Users::class,
            'parentKey'     => 'user_id',
            'relatedKey'    => 'address_id',

            'mutation' => [
                'dataKey'       => ['Addresses', 'addresses'],
                'deleteRelated' => true,
                'pivotColumns'  => ['is_primary', 'label', 'expires_at'],
            ],
        ],
    ];
}
```

## Configuration reference

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `relationship` | `string` | Yes | — | Name of the `BelongsToMany` method on the parent model |
| `relatedModel` | `string` | Yes | — | Fully qualified class name of the related Eloquent model |
| `pivotModel` | `string` | Yes | — | Fully qualified class name of the pivot Eloquent model |
| `parentModel` | `string` | Yes | — | Fully qualified class name of the parent Eloquent model |
| `parentKey` | `string` | Yes | — | Foreign key column referencing the parent in the pivot table |
| `relatedKey` | `string` | Yes | — | Foreign key column referencing the related model in the pivot table |
| `mutation.dataKey` | `string\|array` | No | `[]` | Key(s) to try when extracting bulk data from the request body |
| `mutation.deleteRelated` | `bool` | No | `true` | Whether to delete the related model when `deleteRelation` is called |
| `mutation.pivotColumns` | `array` | No | `[]` | Whitelist of allowed pivot column names; when empty all columns are accepted |

## Scenarios

The `_scenario` route parameter determines the operation mode. The `inject` middleware must set `_relation` and `_scenario` on the request.

| Scenario | Method | HTTP verb | Description |
| --- | --- | --- | --- |
| `attach` | `attachRelation` | POST | Attach a single related entity with optional pivot data |
| `bulk_attach` | `attachRelation` | POST | Attach multiple related entities with optional pivot data |
| `sync` | `attachRelation` | POST | Replace the entire relationship set (detaches missing, attaches new, updates existing) |
| `toggle` | `attachRelation` | POST | Toggle specific IDs: attached become detached and vice-versa |
| `detach` | `detachRelation` | DELETE | Remove a single pivot row (does not delete the related model) |
| `bulk_detach` | `detachRelation` | DELETE | Remove multiple pivot rows |
| `update_pivot` | `updatePivotRelation` | PUT | Update pivot fields for a single related entity |
| `bulk_update_pivot` | `updatePivotRelation` | PUT | Update pivot fields for multiple related entities |

## Pivot data convention

For scenarios that accept pivot data (`attach`, `bulk_attach`, `sync`, `toggle`), the convention is:

> Everything in the request body except the `relatedKey` and `id` fields is treated as pivot data.

For example, with `relatedKey = "address_id"`, a body of `{"address_id": 5, "is_primary": true, "label": "Home"}` results in attaching address `5` with pivot values `{"is_primary": true, "label": "Home"}`.

## JSON examples for each scenario

### `attach` — single attach with pivot

```http
POST /api/v1/users/1/addresses?_relation=addresses&_scenario=attach
Content-Type: application/json

{
  "address_id": 5,
  "is_primary": true,
  "label": "Home"
}
```

Response:

```json
{
  "attached": [5]
}
```

### `bulk_attach` — multiple attach with pivot

Requires `dataKey` to be configured so the array of items can be extracted from the body.

```http
POST /api/v1/users/1/addresses?_relation=addresses&_scenario=bulk_attach
Content-Type: application/json

{
  "addresses": [
    { "address_id": 5, "is_primary": true, "label": "Home" },
    { "address_id": 8, "is_primary": false, "label": "Work" }
  ]
}
```

Response:

```json
{
  "attached": [5, 8]
}
```

### `sync` — replace relationship set

Sync accepts three input shapes:

**Shape 1** — flat list of IDs (no pivot data):

```http
POST /api/v1/users/1/addresses?_relation=addresses&_scenario=sync
Content-Type: application/json

[1, 2, 3]
```

**Shape 2** — list of objects with `relatedKey` and pivot columns:

```http
POST /api/v1/users/1/addresses?_relation=addresses&_scenario=sync
Content-Type: application/json

[
  { "address_id": 1, "is_primary": true },
  { "address_id": 2, "is_primary": false, "label": "Work" },
  { "address_id": 3 }
]
```

**Shape 3** — Laravel-native associative map:

```http
POST /api/v1/users/1/addresses?_relation=addresses&_scenario=sync
Content-Type: application/json

{
  "1": { "is_primary": true },
  "2": { "is_primary": false, "label": "Work" },
  "3": {}
}
```

Response (all shapes):

```json
{
  "attached": [2, 3],
  "detached": [7, 9],
  "updated": [1]
}
```

### `toggle` — toggle specific IDs

Toggle accepts the same three input shapes as `sync`.

**Shape 1** — flat list of IDs:

```json
[1, 2, 3]
```

**Shape 2** — objects with pivot columns:

```json
[
  { "address_id": 1, "is_primary": true },
  { "address_id": 2, "label": "Work" }
]
```

**Shape 3** — Laravel-native map:

```json
{
  "1": { "is_primary": true },
  "2": {}
}
```

Response:

```json
{
  "attached": [2, 3],
  "detached": [1]
}
```

### `detach` — single detach

```http
DELETE /api/v1/users/1/addresses/5?_relation=addresses&_scenario=detach
```

Response:

```json
{
  "detached": 1
}
```

### `bulk_detach` — multiple detach

```http
DELETE /api/v1/users/1/addresses?_relation=addresses&_scenario=bulk_detach
Content-Type: application/json

[5, 8, 12]
```

Response:

```json
{
  "detached": 3
}
```

### `update_pivot` — update pivot fields (single)

```http
PUT /api/v1/users/1/addresses/5?_relation=addresses&_scenario=update_pivot
Content-Type: application/json

{
  "is_primary": true,
  "label": "Main office"
}
```

### `bulk_update_pivot` — update pivot fields (bulk)

Requires `dataKey` to be configured.

```http
PUT /api/v1/users/1/addresses?_relation=addresses&_scenario=bulk_update_pivot
Content-Type: application/json

{
  "addresses": [
    { "address_id": 5, "is_primary": true, "label": "Main office" },
    { "address_id": 8, "is_primary": false, "label": "Warehouse" }
  ]
}
```

## `pivotColumns` whitelist

The optional `pivotColumns` key in the mutation config provides a security whitelist that restricts which pivot attributes are accepted. When configured, any pivot column **not** in the whitelist is silently stripped from the data before it reaches the database — the request is not rejected.

### Why it matters

Without a whitelist, any field sent in the request body (other than the `relatedKey` and `id`) is written directly to the pivot table. A malicious or careless client could overwrite columns like `created_by`, `approved_at`, or other sensitive fields. The whitelist prevents this.

### Configuration

```php
'mutation' => [
    'dataKey'       => ['Addresses', 'addresses'],
    'deleteRelated' => true,
    'pivotColumns'  => ['is_primary', 'label', 'expires_at'],
],
```

### Behavior

With the configuration above, if a client sends:

```json
{
  "address_id": 5,
  "is_primary": true,
  "label": "Home",
  "approved_at": "2025-01-01"
}
```

The `approved_at` field is silently stripped. Only `is_primary` and `label` are stored in the pivot table.

When `pivotColumns` is not set or is an empty array, all pivot columns are accepted (backwards-compatible behavior).

The whitelist is enforced across all four attach scenarios: `attach`, `bulk_attach`, `sync`, and `toggle`.

## Model setup requirements

For pivot columns to appear in query responses, the `BelongsToMany` relationship on the parent model must declare them with `->withPivot()`:

```php
// In the Users model
public function array_address(): BelongsToMany
{
    return $this->belongsToMany(Addresses::class, 'user_addresses', 'user_id', 'address_id')
                ->withPivot(['is_primary', 'label', 'expires_at'])
                ->withTimestamps();
}
```

Without `->withPivot()`, the pivot columns will be stored correctly but will **not** be included in the response when listing or showing related entities.

## Route setup

The `inject` middleware must inject `_relation` and `_scenario` into the request. A minimal route registration example:

```php
use Illuminate\Support\Facades\Route;

Route::prefix('users/{parent_id}/addresses')->group(function () {
    // Read
    Route::get('/', [UserController::class, 'listRelation'])
        ->middleware('inject:_relation,addresses');

    Route::get('/{relatedId}', [UserController::class, 'showRelation'])
        ->middleware('inject:_relation,addresses');

    // Attach / sync / toggle
    Route::post('/', [UserController::class, 'attachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,attach');

    Route::post('/bulk', [UserController::class, 'attachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,bulk_attach');

    Route::post('/sync', [UserController::class, 'attachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,sync');

    Route::post('/toggle', [UserController::class, 'attachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,toggle');

    // Detach
    Route::delete('/{relatedId}', [UserController::class, 'detachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,detach');

    Route::delete('/bulk', [UserController::class, 'detachRelation'])
        ->middleware('inject:_relation,addresses,_scenario,bulk_detach');

    // Update pivot
    Route::put('/{relatedId}/pivot', [UserController::class, 'updatePivotRelation'])
        ->middleware('inject:_relation,addresses,_scenario,update_pivot');

    Route::put('/pivot/bulk', [UserController::class, 'updatePivotRelation'])
        ->middleware('inject:_relation,addresses,_scenario,bulk_update_pivot');
});
```

[Back to documentation index](../index.md)

## Evidence

- File: `src/Core/Traits/ManagesManyToMany.php`
  - Symbol: `ManagesManyToMany::attachRelation()`, `ManagesManyToMany::detachRelation()`, `ManagesManyToMany::updatePivotRelation()`
  - Notes: Entry-point methods for all attach/detach/pivot-update scenarios. `attachRelation()` reads `pivotColumns` from the config and delegates to scenario-specific private methods.
- File: `src/Core/Traits/ManagesManyToMany.php`
  - Symbol: `ManagesManyToMany::buildPivotMap()`, `ManagesManyToMany::processSyncAttach()`, `ManagesManyToMany::processToggleAttach()`
  - Notes: `buildPivotMap()` normalizes the three input shapes into a Laravel-compatible `[id => [pivot_cols]]` map. `processSyncAttach()` and `processToggleAttach()` delegate to it before calling `sync()` / `toggle()`.
- File: `src/Core/Traits/ManagesManyToMany.php`
  - Symbol: `ManagesManyToMany::processSingleAttach()`, `ManagesManyToMany::processBulkAttach()`
  - Notes: Single and bulk attach helpers. Both accept an optional `$allowedPivotCols` parameter and filter pivot data through the whitelist when configured.
- File: `src/Core/Traits/ManagesManyToMany.php`
  - Symbol: `ManagesManyToMany::listRelation()`, `ManagesManyToMany::showRelation()`
  - Notes: Read methods supporting filters, pagination, ordering, and eager-loading on many-to-many relationships.
