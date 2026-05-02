# Scenarios

## Scenario 1: Product catalog search with relations

**Goal**
Return a filtered product list with category data and minimal payload.

**Setup**
- `Product` extends `BaseModel`.
- `ProductService` extends `BaseService`.
- `ProductController` extends `RestController`.
- `Product::RELATIONS` includes `category`.

**Steps**
1. Define relations in the model allowlist.
2. Call the listing endpoint with `select`, `relations`, and `oper`.

**Example code**
```http
GET /api/v1/products?select=["id","name","price"]&relations=["category:id,name"]
```

```json
{
  "oper": {
    "and": ["status|=|active", "price|>=|50"]
  }
}
```

**Notes**
- Use `relations` to avoid N+1 queries.
- Keep `select` tight to reduce payload size.

**Common mistakes**
- Forgetting to add `category` to `RELATIONS`, resulting in a 400 error.
- Passing an invalid operator not in the allowlist.

---

## Scenario 2: Bulk updates in an admin workflow

**Goal**
Update multiple records in a single request from an admin UI.

**Setup**
- Define `const MODEL` on the model (e.g., `product`).
- Expose the `updateMultiple` route on the controller.

**Steps**
1. Include the model key (lowercase) in the JSON body.
2. Provide primary key values in each row.

**Example code**
```http
POST /api/v1/products/update-multiple
Content-Type: application/json

{
  "product": [
    {"id": 10, "stock": 50},
    {"id": 11, "stock": 0}
  ]
}
```

**Notes**
- The controller wraps bulk updates in a transaction.

**Common mistakes**
- Omitting the primary key for a row.
- Forgetting to set `MODEL` on the model class.

---

## Scenario 3: Category tree navigation

**Goal**
Return a category tree with children nested under each parent.

**Setup**
- Define `const HIERARCHY_FIELD_ID = 'parent_id'` on the model.

**Steps**
1. Enable hierarchy in the request.
2. Use `filter_mode` to include descendants.

**Example code**
```json
{
  "hierarchy": {
    "filter_mode": "with_descendants",
    "children_key": "children",
    "max_depth": 4
  }
}
```

**Notes**
- Combine `oper` with `filter_mode` to focus on part of the tree.

**Common mistakes**
- Enabling `hierarchy` without defining `HIERARCHY_FIELD_ID`.

---

## Scenario 4: Assign permissions to roles (Spatie integration)

**Goal**
Sync a list of permissions to roles using the built-in trait.

**Setup**
- Use `HasPermissionsController` in your controller.
- Ensure **spatie/laravel-permission** is installed.

**Steps**
1. Send a request to the `assign_roles` action.
2. Choose `mode` as `ADD`, `SYNC`, or `REVOKE`.

**Example code**
```http
POST /api/permissions/assign_roles
Content-Type: application/json

{
  "roles": ["admin", "editor"],
  "guard": "api",
  "mode": "SYNC",
  "perms": ["products.view", "products.create"]
}
```

**Notes**
- The service can also resolve permissions by module or entity.

**Common mistakes**
- Calling the endpoint without installing Spatie permissions.

---

## Scenario 5: Audit large permission sets with wildcard compression

**Goal**
Return readable permission payloads for senior admin screens, reports, or audit tooling without changing the real permissions stored by Spatie.

**Setup**
- Use `HasPermissionsController` in your permission controller.
- Map the read endpoints from your application routes:

```php
Route::get('/api/permissions/roles', [PermissionController::class, 'get_permissions_by_roles']);
Route::get('/api/permissions/users', [PermissionController::class, 'get_permissions_by_users']);
```

**Steps**
1. Call the read endpoint with `compress=true`.
2. Use `roles[]` or `users[]` and choose `by` according to your identifiers.
3. Add `expand=true` only when the client needs the expanded names for drill-down or export.

**Example code**
```http
GET /api/permissions/roles?roles[]=admin&by=name&guard=api&compress=true
```

```json
{
  "ok": true,
  "data": [
    {
      "role": "admin",
      "guard": "api",
      "permissions": [
        "security.*",
        "sales.order.*",
        "reports.dashboard.index"
      ],
      "stats": {
        "original_count": 24,
        "compressed_count": 3,
        "compression_ratio": 8
      }
    }
  ]
}
```

With `expand=true`, the same compressed response includes the expanded permission names:

```http
GET /api/permissions/users?users[]=alice@example.com&by=email&guard=api&compress=true&expand=true
```

**Notes**
- `security.*` means the subject has every permission in the current system universe for the `security` module.
- `security.user.*` means every known action for `security.user`, not an authorization rule stored in the database.
- `compress_global=true` enables `*`; keep it disabled unless the client is trusted and explicitly expects global audit summaries.

**Common mistakes**
- Treating compressed strings as permissions to write back to Spatie.
- Enabling `compress_global=true` in broad client-facing responses without a product reason.

[Back to documentation index](../index.md)

## Evidence
- File: src/Core/Services/BaseService.php
  - Symbol: BaseService::process_query(), BaseService::update_multiple(), BaseService::listHierarchy()
  - Notes: Supports filtering, bulk updates, and hierarchy mode.
- File: src/Core/Controllers/RestController.php
  - Symbol: RestController::updateMultiple()
  - Notes: Wraps bulk updates in a transaction.
- File: src/Core/Models/BaseModel.php
  - Symbol: BaseModel::MODEL, BaseModel::HIERARCHY_FIELD_ID
  - Notes: Defines model key and hierarchy capability.
- File: src/Core/Traits/HasPermissionsController.php
  - Symbol: HasPermissionsController::assign_roles(), HasPermissionsController::get_permissions_by_roles(), HasPermissionsController::get_permissions_by_users()
  - Notes: Provides permission assignment and compressed read endpoints used in the scenarios.
- File: src/Core/Support/Permissions/
  - Symbol: PermissionCompressor
  - Notes: Compresses flat permission names into wildcard presentation strings.
