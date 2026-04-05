# Cache Invalidation Analysis (In-Depth)

This document provides a senior-level analysis of the cache invalidation strategy
in `rest-generic-class`, covering per-service cache control and cross-entity
invalidation.

---

## Problem Statement

### 1. Cache toggle is global-only

`shouldUseCache()` only checks the global `REST_CACHE_ENABLED` env variable.
There is no way to make `ProductService` cacheable while `OrderService` is not.

### 2. Cross-entity staleness

When a User is cached with its Role (via eager loading), and the Role is later
updated, the User cache still serves stale Role data because only the Role's
cache version is bumped — the User's version is unchanged.

---

## Solution: Dual Strategy (Composite Versions + CACHE_INVALIDATES)

### Per-service cache control

Three new properties on `BaseService`:

```php
protected ?bool $cacheable = null;        // null = global config, true/false = override
protected ?int $cacheTtl = null;          // null = global config, int = seconds
protected array $cacheableOperations = []; // empty = global config
```

Priority chain:
- **Enabled**: `$cacheable` (service) > `cache.enabled` (config) > `cache` param (request)
- **TTL**: `cache_ttl` (request) > `$cacheTtl` (service) > `ttl_by_method` (config) > `ttl` (config)

### Automatic: composite version keys

`buildCacheKey()` now includes `rel_versions` — the cache version of every
eagerly loaded relation's model. When a relation model is written, its version
changes, which changes the cache key of any parent that loads it.

```php
$fingerprint = [
    // ... existing fields ...
    'version' => $this->getCacheVersion(),
    'rel_versions' => $this->getRelationVersions($params), // NEW
];
```

`getRelationVersions()` resolves nested dot-notation relations by walking the
Eloquent relation chain:

```
relations=["roles.permissions"]
→ rel_versions = { "roles": 2, "roles.permissions": 4 }
```

### Manual: CACHE_INVALIDATES constant

For edge cases where related data appears without explicit `relations`
(e.g., `$appends` accessors), models declare which other models should be
invalidated when they change:

```php
class Role extends BaseModel
{
    const CACHE_INVALIDATES = [\App\Models\User::class];
}
```

`bumpCacheVersion()` now propagates to all models listed in `CACHE_INVALIDATES`.

---

## Why This Strategy Was Chosen

### Compared alternatives

| Criteria | CACHE_INVALIDATES only | Composite versions only | Combined (chosen) | Observers |
|----------|----------------------|------------------------|-------------------|-----------|
| Manual config | High | None | Minimal | Medium |
| Covers nested relations | If declared | Partial (1 level) | Yes | If declared |
| Covers changes outside BaseService | No | No | Partial | Yes |
| Performance | O(1) bump | O(n) reads on build | O(n) reads + O(1) | O(n) events |
| Stale data risk | High (human error) | Low | Very low | Low |
| Backward compatible | 100% | 100% | 100% | 95% |

### Why not CACHE_INVALIDATES only?

In a system with 15+ models, requiring developers to maintain `CACHE_INVALIDATES`
on every model is error-prone. A forgotten entry means silent stale data in
production — no error, no warning, just wrong data. Debugging takes hours.

### Why not composite versions only?

Composite versions automatically handle explicit `relations` parameters. But
they cannot detect data included via `$appends` accessors or lazy-loaded
relations in `jsonSerialize()`. Example:

```php
class User extends BaseModel {
    protected $appends = ['role_name'];
    public function getRoleNameAttribute() {
        return $this->role?->name; // lazy load, no explicit relation param
    }
}
```

Here `getRelationVersions()` returns `[]` (no `relations` in params), but the
cached JSON includes role data. `CACHE_INVALIDATES` covers this edge case.

### Why not Observers?

1. The ServiceProvider would need to register listeners on ALL BaseModel subclasses
2. `update_multiple` with 500 records fires 500 observer events
3. `DB::table()->update()` bypasses observers — false sense of security
4. Harder to debug ("why was my cache invalidated?")

---

## Detailed Scenarios

### Scenario A: User with Roles (the original problem)

```
Step 1: GET /api/users/1?relations=["roles"]
  → key: { version: 5, rel_versions: { roles: 2 } }
  → cached: { id: 1, name: "Juan", roles: [{ name: "Admin" }] }

Step 2: PUT /api/roles/1 { name: "Super Admin" }
  → Role version: 2 → 3

Step 3: GET /api/users/1?relations=["roles"]
  → key: { version: 5, rel_versions: { roles: 3 } } ← DIFFERENT
  → cache miss → fresh query → { roles: [{ name: "Super Admin" }] }
```

Result: correct data, zero configuration needed.

### Scenario B: 3-level chain — User → Role → Permission

```php
class Permission extends BaseModel {
    const CACHE_INVALIDATES = [Role::class, User::class];
}
```

```
Step 1: GET /api/users/1?relations=["roles.permissions"]
  → rel_versions: { roles: 2, "roles.permissions": 4 }

Step 2: PUT /api/permissions/3 { name: "can_delete_users" }
  → bumps: Permission 4→5, Role 2→3, User 5→6

Step 3: GET /api/users/1?relations=["roles.permissions"]
  → rel_versions: { roles: 3, "roles.permissions": 5 } → cache miss → fresh
```

### Scenario C: selective invalidation by loaded relations

```
Request A: GET /api/users?relations=["roles"]        → rel_versions: { roles: 2 }
Request B: GET /api/users?relations=["department"]    → rel_versions: { department: 7 }
Request C: GET /api/users?relations=["roles","dept"]  → rel_versions: { roles: 2, department: 7 }
```

If only Department changes, only requests B and C are invalidated. Request A
remains valid — efficient and selective.

### Scenario D: accessor without explicit relations

```php
class User extends BaseModel {
    protected $appends = ['full_role_name'];
    public function getFullRoleNameAttribute() {
        return $this->roles->pluck('name')->join(', ');
    }
}

class Role extends BaseModel {
    const CACHE_INVALIDATES = [User::class];
}
```

```
GET /api/users/1  (no relations param)
  → rel_versions: {} → cached with User version only

PUT /api/roles/1 { name: "Super Admin" }
  → bumps Role version AND User version (via CACHE_INVALIDATES)

GET /api/users/1
  → User version changed → different key → fresh data
```

---

## Decision Flowchart

```
Does your service need cache?
  ├── NO → protected ?bool $cacheable = false;
  └── YES → Custom TTL?
        ├── YES → protected ?int $cacheTtl = 300;
        └── NO → Use global config (default)

Is your model loaded as a relation by other models?
  ├── NO → No CACHE_INVALIDATES needed
  └── YES → Do consumers always use explicit relations param?
        ├── YES → Composite version keys handle it automatically
        └── NO (accessors, $appends) → Add CACHE_INVALIDATES
```

---

## Files Changed

| File | Changes |
|------|---------|
| `src/Core/Services/BaseService.php` | Added `$cacheable`, `$cacheTtl`, `$cacheableOperations` properties; modified `shouldUseCache()`, `resolveCacheTtl()`, `buildCacheKey()`, `bumpCacheVersion()`; added `getRelationVersions()` |
| `src/Core/Models/BaseModel.php` | Added `CACHE_INVALIDATES` constant |
| `config/rest-generic-class.php` | No changes (backward compatible) |

[Back to documentation index](../index.md)
