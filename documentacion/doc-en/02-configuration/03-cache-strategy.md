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

## Invalidation model

Use **versioned keys per model**:

1. keep a version key per model
2. include this version in every read key
3. bump version after successful writes (`create`, `update`, `destroy`)

This works across all stores, including stores without tags.

**Next:** [Publishing assets](02-publishing-assets.md)

[Back to documentation index](../index.md)
