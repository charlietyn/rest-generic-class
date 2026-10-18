# Rest Generic Class for Laravel

**A Laravel package that provides base classes for RESTful CRUD with dynamic filtering, relation loading, and hierarchical listing.**

[![Latest Version](https://img.shields.io/packagist/v/ronu/rest-generic-class.svg?style=flat-square)](https://packagist.org/packages/ronu/rest-generic-class)
[![Laravel](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![License](https://img.shields.io/packagist/l/ronu/rest-generic-class.svg?style=flat-square)](LICENSE.md)

## v2.9.0 — Authorization contracts

The CRUD core now authorizes eager loads and field writes only through `Core\Contracts\Authorization` (`AuthorizationContext`, `RelationAuthorizer`, `FieldWriteAuthorizer`) and Laravel's Gate, preparing the route permissions to move to their own package. No behaviour change with the default configuration; `require_read` without a request scope now fails closed. Also fixes `SpatieRole::enabled_permissions()` (PostgreSQL-only SQL, and an expired grant being honoured when another role held an active one). See the [contracts](documentacion/doc-en/04-reference/06-authorization-contracts.md) and the [release notes](docs/release-notes-channel-authorization.md#v290--authorization-contracts--contratos-de-autorización).

## v2.8.2 — Direct user permissions without Spatie's trait

`users:assign-permissions` and the direct/role permission readers now work on user models without Spatie's `HasPermissions`/`HasRoles` (as generated applications are), and validation messages name the field instead of its value (an array value no longer answers 500). See the [release notes](docs/release-notes-channel-authorization.md#v282--direct-user-permissions-without-spaties-trait--permisos-directos-sin-el-trait-de-spatie).

## v2.8.1 — Spatie 7/8 compatibility

`SpatiePermission` and `SpatieRole` failed to load with spatie/laravel-permission 7+ (e.g. Laravel 13.36 + Spatie 8.3.0 stopped `artisan package:discover`). `findByName()`/`findOrCreate()` now accept `BackedEnum|string`, so one release works with Spatie `^6.21 || ^7.0 || ^8.0` on Laravel 12 and 13. See the [release notes](docs/release-notes-channel-authorization.md#v281--spatie-78-compatibility--compatibilidad-con-spatie-78).

## v2.8.0 — Actions by ability and generic notation

Opt-in route attributes `permission_ability` / `permission_requires` collapse the routes of a relation into `read`/`add`/`update`/`delete`/`manage` actions that also require seeing the parent; grant them with `admin.crm.clients.*`-style patterns; the catalog syncs in batch (with `--prune`), and `eager_relations=require_read` authorizes `?relations=`. See [the public API, §10](docs/permissions-public-api.md) and the [release notes](docs/release-notes-channel-authorization.md).

## v2.7.1 — Fixes

`GET /<channel>/modules` no longer fails (with `?enabled=` and the new `?format=uri`), no PHP 8.4 implicit-nullable deprecations, and the [channel authorization migration script](documentacion/doc-en/03-usage/07-enable-channel-authorization-script.md) now ships in `scripts/`.

## v2.7.0 — Explicit global permissions

Opt-in `explicit_globals` (only routes marked with the `permission_global` action attribute are global; everything else fails closed), `catalog_middleware` to keep public routes out of the permission catalog, and the v2.5.0 legacy module-name fix. See the [release notes](docs/release-notes-channel-authorization.md#v270--explicit-globals-and-catalog-scope--globales-y-catálogo-explícitos).

## v2.6.0 — Channel authorization

Optional channel prefixes, literal route permission names, fail-closed middleware, and an effective-permission Gate. Review the [release notes and activation order](docs/release-notes-channel-authorization.md) before enabling authorization for channel-based applications.

## Requirements

| Laravel | PHP | Test runtime |
| --- | --- | --- |
| 12.61.1+ | 8.2+ | PHPUnit 11 |
| 13.12+ | 8.3+ | PHPUnit 12 |

Optional `spatie/laravel-permission`: `^6.21 || ^7.0 || ^8.0` (Laravel 13 needs 6.25+, 7.2.4+ or 8.x); other
lines are rejected by Composer `conflict`. See [requirements](documentacion/doc-en/01-getting-started/00-requirements.md).

The package keeps Laravel 12 support while adding Laravel 13 support. See the detailed Spanish
[Laravel 13 migration guide for junior developers](documentacion/doc-es/01-getting-started/03-migracion-laravel-13-para-juniors.md).

## Installation

```bash
composer require ronu/rest-generic-class
```

### Publish configuration (optional)

```bash
php artisan vendor:publish --tag=rest-generic-class-config
```

## Quickstart

### 1) Model

```php
<?php

namespace App\Models;

use Ronu\RestGenericClass\Core\Models\BaseModel;

class Product extends BaseModel
{
    protected $fillable = ['name', 'price', 'stock', 'category_id'];

    const MODEL = 'product';
    const RELATIONS = ['category', 'reviews'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
```

### 2) Service

```php
<?php

namespace App\Services;

use App\Models\Product;
use Ronu\RestGenericClass\Core\Services\BaseService;

class ProductService extends BaseService
{
    public function __construct()
    {
        parent::__construct(Product::class);
    }
}
```

### 3) Controller

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Services\ProductService;
use Ronu\RestGenericClass\Core\Controllers\RestController;

class ProductController extends RestController
{
    protected $modelClass = Product::class;

    public function __construct(ProductService $service)
    {
        $this->service = $service;
    }
}
```

### 4) Routes

```php
use App\Http\Controllers\Api\ProductController;

Route::prefix('v1')->group(function () {
    Route::apiResource('products', ProductController::class);
    Route::post('products/update-multiple', [ProductController::class, 'updateMultiple']);
});
```

### 5) Query with filtering and relations

```http
GET /api/v1/products?select=["id","name"]&relations=["category:id,name"]
```

```json
{
  "oper": {
    "and": ["status|=|active", "price|>=|50"]
  }
}
```

## Parameter-driven aggregates

List endpoints support `aggregate` for global metrics and `with_aggregates` for
per-record relationship metrics (`count`, `sum`, `avg`, `min`, `max`). Models opt in
through `HasRestAggregates`. Execution uses native Laravel aggregate methods,
without accepting raw SQL. See [aggregation parameters and examples](documentation/aggregations.md)
for authorization, filtering, pagination, cache behavior, and supported relations.

## Configuration

Publish the config file and adjust values in `config/rest-generic-class.php`.

Environment variables:

- `LOG_LEVEL` (default: `debug`)
- `LOG_QUERY` (default: `false`)
- `REST_VALIDATE_COLUMNS` (default: `true`)
- `REST_STRICT_COLUMNS` (default: `true`)
- `REST_CACHE_ENABLED` (default: `false`)
- `REST_CACHE_STORE` (default: `CACHE_STORE`; supports `redis`, `database`, `file`, `memcached`, etc.)
- `REST_CACHE_TTL` (default: `60` seconds)
- `REST_CACHE_TTL_LIST` / `REST_CACHE_TTL_ONE`
- `REST_VALIDATION_CACHE_ENABLED` (default: `true`)
- `REST_VALIDATION_CACHE_TTL` (default: `3600` seconds)
- `REST_VALIDATION_CACHE_PREFIX` (default: `validation`)
- `REST_VALIDATION_CONNECTION` (default: `db`)


## Cache strategy (Laravel native / Redis / database)

This package now supports **generic cache integration** via Laravel Cache stores.

### How it works

- Cache is applied in `BaseService` for read operations: `list_all` and `get_one`.
- Cache key fingerprint includes: model, operation, route, query params, auth user, selected headers, normalized params, and a model cache version.
- Any successful write (`create`, `update`, `destroy`, `destroybyid`) bumps a model-level cache version to avoid stale reads without needing tags.
- Laravel 13's secure `cache.serializable_classes=false` default is honored: array payloads are cached, while paginator objects are not cached unless the host application supplies an explicit class allowlist.
- Cache keys use the `rgc:v2` namespace so incompatible Laravel 12 cache entries are not reused after an upgrade.

### Store selection

Use any Laravel cache store by setting:

```env
REST_CACHE_ENABLED=true
REST_CACHE_STORE=redis
# or: database, file, memcached, dynamodb, etc.
```

### Request-aware behavior

You can control cache per request:

- `cache=false` disables cache for that request.
- `cache_ttl=120` overrides TTL (seconds) for that request.

Example:

```http
GET /api/v1/products?cache_ttl=120&select=["id","name"]
```

### Multi-tenant / locale aware keys

By default cache varies by `Accept-Language` and `X-Tenant-Id` headers to avoid cross-tenant or cross-locale data leaks.

## Common scenarios

### 1) Bulk update

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

### 2) Hierarchy listing

```json
{
  "hierarchy": {
    "filter_mode": "with_descendants",
    "children_key": "children",
    "max_depth": 3
  }
}
```

### 3) Permission sync (Spatie)

```http
POST /api/permissions/assign_roles
Content-Type: application/json

{
  "roles": ["admin"],
  "guard": "api",
  "mode": "SYNC",
  "perms": ["products.view", "products.create"]
}
```

## Edge scenarios

- Config caching hides env updates until you clear the config cache.
- Deep hierarchy trees can time out without `max_depth`.
- Excessive filter conditions trigger safety limits.

See [Edge and extreme scenarios](documentacion/doc-en/03-usage/03-edge-and-extreme-scenarios.md) for detailed guidance.

## API reference

- [Public API](documentacion/doc-en/04-reference/00-public-api.md)
- [Middleware](documentacion/doc-en/04-reference/02-middleware.md)
- [Exceptions](documentacion/doc-en/04-reference/04-exceptions.md)

## Troubleshooting

Start with [Troubleshooting](documentacion/doc-en/05-quality/02-troubleshooting.md) for common errors such as invalid relations, operator errors, and missing optional packages.

## Documentation

- [Documentation index (EN)](documentacion/doc-en/index.md)
- [Documentation index (ES)](documentacion/doc-es/index.md)
- [Migración a Laravel 13 explicada para juniors](documentacion/doc-es/01-getting-started/03-migracion-laravel-13-para-juniors.md)
- [Quickstart](documentacion/doc-en/01-getting-started/02-quickstart.md)
- [Configuration reference](documentacion/doc-en/02-configuration/00-configuration-reference.md)
- [Scenarios](documentacion/doc-en/03-usage/02-scenarios.md)

## Contributing / Security

Please open issues and pull requests on GitHub. For security concerns, report them privately to the maintainer email listed in the package metadata.
