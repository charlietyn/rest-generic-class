# Cache scenarios (JSON examples)

This page explains whether cache strategy is reliable for real request variants.

## Scenario 1: selected columns + relations

```json
{
  "select": ["id", "name", "price"],
  "relations": ["category:id,name"]
}
```

Expected:
- different `select` or `relations` values produce different cache keys
- safe payload reuse for identical request schema

## Scenario 2: advanced `oper` filters

```json
{
  "oper": {
    "and": [
      "status|=|active",
      "price|>=|100",
      "stock|>|0"
    ]
  }
}
```

Expected:
- filter tree is reflected in key identity
- textually different but semantically equivalent trees can generate different keys (normal unless deep canonicalization is added)

## Scenario 3: pagination variants

Offset:

```json
{
  "pagination": {"page": 2, "pageSize": 25},
  "orderby": [{"id": "desc"}]
}
```

Cursor:

```json
{
  "pagination": {"infinity": true, "pageSize": 25, "cursor": "abc"}
}
```

Expected:
- page/cursor changes produce isolated cache entries

## Scenario 4: hierarchy mode

```json
{
  "hierarchy": {
    "enabled": true,
    "filter_mode": "with_descendants",
    "children_key": "children",
    "max_depth": 3
  }
}
```

Expected:
- hierarchy and non-hierarchy requests do not collide in cache

## Scenario 5: request-level control

```json
{
  "cache": false,
  "cache_ttl": 120
}
```

Expected:
- `cache=false` bypasses cache
- `cache_ttl` overrides default TTL when cache is enabled

## Scenario 6: cross-entity invalidation (relations)

Setup: User model has `roles` relation, Role model changes.

```
Step 1: GET /api/users/1?relations=["roles"]
  → cached with key fingerprint: { version: 5, rel_versions: { roles: 2 } }
  → result: { id: 1, name: "Juan", roles: [{ id: 1, name: "Admin" }] }

Step 2: PUT /api/roles/1 { name: "Super Admin" }
  → Role version bumps from 2 → 3

Step 3: GET /api/users/1?relations=["roles"]
  → key fingerprint: { version: 5, rel_versions: { roles: 3 } } ← CHANGED
  → cache miss → fresh data with updated role name
```

Expected:
- updating a related model automatically invalidates cached responses that include it
- no manual configuration needed when relations are loaded explicitly

## Scenario 7: nested relations (3 levels)

```
GET /api/users/1?relations=["roles.permissions"]
  → rel_versions: { roles: 2, "roles.permissions": 4 }

PUT /api/permissions/3 { name: "can_delete" }
  → Permission version bumps
  → If CACHE_INVALIDATES = [Role::class, User::class], those versions also bump

GET /api/users/1?relations=["roles.permissions"]
  → rel_versions changed → cache miss → fresh data
```

Expected:
- nested dot-notation relations are tracked at every level
- `CACHE_INVALIDATES` propagates invalidation to parent models

## Scenario 8: per-service cache control

```php
class ProductService extends BaseService {
    protected ?bool $cacheable = true;
    protected ?int $cacheTtl = 600;
}

class AuditLogService extends BaseService {
    protected ?bool $cacheable = false; // never cache
}
```

Expected:
- ProductService caches with 10-minute TTL regardless of global config
- AuditLogService never caches even if global cache is enabled
- services without overrides use global config (backward compatible)

## Scenario 9: accessor without explicit relations (CACHE_INVALIDATES)

```php
class User extends BaseModel {
    protected $appends = ['role_name'];
    public function getRoleNameAttribute() {
        return $this->role?->name;
    }
}

class Role extends BaseModel {
    const CACHE_INVALIDATES = [User::class];
}
```

```
GET /api/users/1  (no relations parameter)
  → rel_versions: {} (empty)
  → response includes role_name via $appends

PUT /api/roles/1 { name: "Super Admin" }
  → bumpCacheVersion() bumps Role AND User versions (via CACHE_INVALIDATES)

GET /api/users/1
  → User version changed → different key → fresh data
```

Expected:
- `CACHE_INVALIDATES` covers cases where related data is serialized without explicit `relations`

## Does it handle all variants?

Practical answer:
- **Yes, for most production request patterns** used by this package.
- **Not fully canonicalized** for every semantically equivalent JSON ordering.
- **Cross-entity invalidation** is handled automatically for explicit relations and via `CACHE_INVALIDATES` for implicit ones.

If clients send equivalent payloads with many ordering differences, add canonicalization before hashing key payloads.

[Back to documentation index](../index.md)
