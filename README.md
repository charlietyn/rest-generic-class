# Rest Generic Class for Laravel

**A Laravel package that provides base classes for RESTful CRUD with dynamic filtering, relation loading, and hierarchical listing.**

[![Latest Version](https://img.shields.io/packagist/v/ronu/rest-generic-class.svg?style=flat-square)](https://packagist.org/packages/ronu/rest-generic-class)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![License](https://img.shields.io/packagist/l/ronu/rest-generic-class.svg?style=flat-square)](LICENSE.md)

## Requirements

- PHP ^8.0
- Laravel (Illuminate components) ^12.0

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

## Configuration

Publish the config file and adjust values in `config/rest-generic-class.php`.

Environment variables:

- `LOG_LEVEL` (default: `debug`)
- `LOG_QUERY` (default: `false`)
- `REST_VALIDATE_COLUMNS` (default: `true`)
- `REST_STRICT_COLUMNS` (default: `true`)

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

See [Edge and extreme scenarios](documentation/03-usage/03-edge-and-extreme-scenarios.md) for detailed guidance.

## API reference

- [Public API](documentation/04-reference/00-public-api.md)
- [Middleware](documentation/04-reference/02-middleware.md)
- [Exceptions](documentation/04-reference/04-exceptions.md)

## Troubleshooting

Start with [Troubleshooting](documentation/05-quality/02-troubleshooting.md) for common errors such as invalid relations, operator errors, and missing optional packages.

## Documentation

- [Documentation index](documentation/index.md)
- [Quickstart](documentation/01-getting-started/02-quickstart.md)
- [Configuration reference](documentation/02-configuration/00-configuration-reference.md)
- [Scenarios](documentation/03-usage/02-scenarios.md)

## Contributing / Security

Please open issues and pull requests on GitHub. For security concerns, report them privately to the maintainer email listed in the package metadata.
