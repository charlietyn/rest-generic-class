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
  - Symbol: HasPermissionsController::assign_roles()
  - Notes: Provides permission assignment endpoint used in the scenario.
