# Cache strategy (generic and configurable)

This package supports a **store-agnostic cache strategy** through Laravel Cache, so you can use:

- `redis`
- `database`
- `file`
- `memcached`
- any other Laravel-supported cache store

## Configuration keys

Cache behavior is configured in `config/rest-generic-class.php` under `cache`:

| Key | Description |
| --- | --- |
| `cache.enabled` | Enables/disables package cache behavior. |
| `cache.store` | Laravel cache store name (for example: `redis`, `database`). |
| `cache.ttl` | Default TTL (seconds). |
| `cache.ttl_by_method.list_all` | TTL override for list endpoint reads. |
| `cache.ttl_by_method.get_one` | TTL override for single-item reads. |
| `cache.cacheable_methods` | Read methods allowed to use cache. |
| `cache.vary.headers` | Headers included in cache identity (tenant/locale safety). |

## Recommended `.env`

```env
REST_CACHE_ENABLED=true
REST_CACHE_STORE=redis
REST_CACHE_TTL=60
REST_CACHE_TTL_LIST=60
REST_CACHE_TTL_ONE=30
```

To switch backend without code changes:

```env
REST_CACHE_STORE=database
```

## How request-aware keys work

For read operations, the cache identity should vary by request shape:

- query params (`select`, `relations`, `oper`, `pagination`, `orderby`, etc.)
- route/path and HTTP method
- auth scope (when response depends on user)
- tenant/locale headers listed in `cache.vary.headers`
- model cache version (for write invalidation)

This prevents cache pollution between different query schemas and contexts.

## Per-service cache control

Child services can override cache behavior without changing global config:

```php
class ProductService extends BaseService
{
    protected ?bool $cacheable = true;   // force cache ON
    protected ?int $cacheTtl = 300;      // 5 minutes
    protected array $cacheableOperations = ['list_all']; // only cache listings

    public function __construct() {
        parent::__construct(Product::class);
    }
}

class OrderService extends BaseService
{
    protected ?bool $cacheable = false;  // never cache

    public function __construct() {
        parent::__construct(Order::class);
    }
}
```

| Property | Default | Behavior |
| --- | --- | --- |
| `$cacheable` | `null` | `null` = use global config, `true` = force on, `false` = force off |
| `$cacheTtl` | `null` | `null` = use global config, integer = seconds |
| `$cacheableOperations` | `[]` | empty = use global config, non-empty = only these methods |

**Priority chain for TTL:** request `cache_ttl` > `$this->cacheTtl` > config `ttl_by_method` > config `ttl`

## Invalidation model

Use **versioned keys per model**:

1. keep a version key per model
2. include this version in every read key
3. bump version after successful writes (`create`, `update`, `destroy`)

This works across all stores, including stores without tags.

## Cross-entity cache invalidation

When a model is eager-loaded as a relation (e.g., User loads Role), updating the related model must also invalidate the parent's cache. Two mechanisms work together:

### Automatic: composite version keys

When `relations` are requested, `buildCacheKey()` includes the cache version of every related model in the fingerprint. If Role version changes, the User cache key changes automatically.

```
GET /users/1?relations=["roles"]
→ key includes: version=5, rel_versions={roles: 2}

PUT /roles/1 {name: "Super Admin"}
→ Role version bumps to 3

GET /users/1?relations=["roles"]
→ key includes: version=5, rel_versions={roles: 3} ← different key → cache miss
```

This works for nested relations too: `relations=["roles.permissions"]` tracks versions of both Role and Permission.

### Manual: CACHE_INVALIDATES

For edge cases where related data appears without explicit `relations` (e.g., via `$appends` accessors), declare dependencies in the model:

```php
class Role extends BaseModel
{
    const CACHE_INVALIDATES = [
        \App\Models\User::class,  // bump User version when Role changes
    ];
}
```

When Role is written, `bumpCacheVersion()` also increments User's version.

**Next:** [Publishing assets](02-publishing-assets.md)

[Back to documentation index](../index.md)
