<div align="center">

# REST Generic Class for Laravel

**Enterprise-grade RESTful API foundation with zero boilerplate**

[![Latest Version](https://img.shields.io/packagist/v/ronu/rest-generic-class.svg?style=flat-square)](https://packagist.org/packages/ronu/rest-generic-class)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![License](https://img.shields.io/packagist/l/ronu/rest-generic-class.svg?style=flat-square)](LICENSE.md)

[Installation](#installation) • [Quick Start](#quick-start) • [Parameters](#parameters) • [Full Documentation](DOCUMENTATION.md)

</div>

---

## Overview

REST Generic Class eliminates repetitive CRUD boilerplate in Laravel applications. Inherit from base classes and get instant REST API capabilities with advanced filtering, nested queries, and field selection.

### Key Features

- 🚀 **Zero Boilerplate** - Full CRUD in 3 lines of code
- 🔍 **Advanced Filtering** - 20+ operators with AND/OR logic
- 🌲 **Nested Queries** - Filter by deeply related data
- 🎯 **Field Selection** - Control response structure
- 🔐 **Security First** - Built-in allowlists and validation
- 📊 **Spatie Integration** - Role-based permissions ready

---

## Installation
```bash
composer require ronu/rest-generic-class
```

### Optional: Publish Configuration
```bash
php artisan vendor:publish --tag=rest-generic-class-config
```

---

## Quick Start

### 1. Setup Model
```php
<?php
// app/Models/Product.php

namespace App\Models;

use Ronu\RestGenericClass\Core\Models\BaseModel;

class Product extends BaseModel
{
    protected $fillable = ['name', 'price', 'stock', 'category_id'];
    
    // Security: Define allowed relations for filtering
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

### 2. Create Service
```php
<?php
// app/Services/ProductService.php

namespace App\Services;

use Ronu\RestGenericClass\Core\Services\BaseService;
use App\Models\Product;

class ProductService extends BaseService
{
    public function __construct()
    {
        parent::__construct(Product::class);
    }
}
```

### 3. Create Controller
```php
<?php
// app/Http/Controllers/Api/ProductController.php

namespace App\Http\Controllers\Api;

use Ronu\RestGenericClass\Core\Controllers\RestController;
use App\Services\ProductService;
use App\Models\Product;

class ProductController extends RestController
{
    protected $modelClass = Product::class;
    
    public function __construct(ProductService $service)
    {
        $this->service = $service;
    }
}
```

### 4. Register Routes
```php
<?php
// routes/api.php

Route::prefix('v1')->group(function () {
    Route::apiResource('products', ProductController::class);
    Route::post('products/update-multiple', [ProductController::class, 'updateMultiple']);
});
```

### 5. Use Your API
```bash
# List all
GET /api/v1/products

# Get one
GET /api/v1/products/1

# Create
POST /api/v1/products
{"name": "Laptop", "price": 999.99}

# Update
PUT /api/v1/products/1
{"price": 899.99}

# Delete
DELETE /api/v1/products/1
```

---

## Parameters

All list operations (`GET /resources`) support these query parameters:

### `select` - Choose Fields

**Type:** `string | array`  
**Purpose:** Specify which fields to return from the main model
```bash
# Return only id and name
GET /api/v1/products?select=["id","name","price"]
```

**Response:**
```json
{
  "data": [
    {"id": 1, "name": "Laptop", "price": 999.99},
    {"id": 2, "name": "Mouse", "price": 29.99}
  ]
}
```

---

### `relations` - Load Related Data

**Type:** `array`  
**Purpose:** Eager load relationships (with optional field selection)
```bash
# Load category and reviews
GET /api/v1/products?relations=["category","reviews"]

# Load with specific fields
GET /api/v1/products?relations=["category:id,name","reviews:id,rating"]
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Laptop",
      "category": {
        "id": 3,
        "name": "Electronics"
      },
      "reviews": [
        {"id": 101, "rating": 5}
      ]
    }
  ]
}
```

---

### `oper` - Filter Data

**Type:** `array | object`  
**Purpose:** Apply filter conditions to results

#### Simple Filters (Array)
```bash
# Products in stock with price > 100
GET /api/v1/products?oper=["stock|>|0","price|>|100"]
```

#### Complex Filters (Object)
```bash
# Active products in Electronics OR Computers category
GET /api/v1/products
```
```json
{
  "oper": {
    "and": ["active|=|1"],
    "or": ["category_id|=|3", "category_id|=|5"]
  }
}
```

#### Available Operators

| Operator | Example | Description |
|----------|---------|-------------|
| `=` | `status\|=\|active` | Equals |
| `!=` | `status\|!=\|deleted` | Not equals |
| `>` | `price\|>\|100` | Greater than |
| `<` | `stock\|<\|10` | Less than |
| `>=` | `rating\|>=\|4` | Greater or equal |
| `<=` | `age\|<=\|18` | Less or equal |
| `like` | `name\|like\|%laptop%` | Contains |
| `in` | `status\|in\|pending,active` | In list |
| `between` | `price\|between\|10,100` | Between range |
| `null` | `deleted_at\|null` | Is null |
| `not null` | `verified_at\|not null` | Is not null |

**See [Full Operator List](DOCUMENTATION.md#operators-reference) for all 20+ operators**

#### Nested Filters (Filter by Relations)
```bash
# Products from verified sellers
GET /api/v1/products
```
```json
{
  "oper": {
    "seller": {
      "and": ["verified|=|1"]
    }
  }
}
```

**This filters the root dataset** - only returns products where the seller is verified.

---

### `orderby` - Sort Results

**Type:** `array`  
**Purpose:** Sort by one or more fields
```bash
# Sort by price descending
GET /api/v1/products?orderby=[{"price":"desc"}]

# Sort by category, then price
GET /api/v1/products?orderby=[{"category_id":"asc"},{"price":"desc"}]
```

---

### `pagination` - Paginate Results

**Type:** `object`  
**Purpose:** Control pagination
```bash
# Page 2, 20 items per page
GET /api/v1/products?pagination={"page":2,"pageSize":20}

# Cursor pagination (infinite scroll)
GET /api/v1/products?pagination={"infinity":true,"pageSize":20}
```

**Response (standard):**
```json
{
  "current_page": 2,
  "data": [...],
  "per_page": 20,
  "total": 150,
  "last_page": 8
}
```

**Response (cursor):**
```json
{
  "data": [...],
  "next_cursor": "eyJpZCI6MjB9",
  "has_more": true
}
```

---

## Complete Example
```bash
GET /api/v1/products
```

**Request Body:**
```json
{
  "select": ["id", "name", "price"],
  "relations": ["category:id,name"],
  "oper": {
    "and": [
      "stock|>|0",
      "active|=|1",
      "price|between|50,500"
    ],
    "category": {
      "and": ["active|=|1"]
    }
  },
  "orderby": [{"price": "asc"}],
  "pagination": {"page": 1, "pageSize": 10}
}
```

**What this does:**
1. Returns only `id`, `name`, `price` fields
2. Loads `category` with only `id`, `name`
3. Filters products: in stock, active, price $50-$500
4. Only products from active categories
5. Sorts by price ascending
6. Returns page 1, 10 items

---

## CRUD Operations

### Create Single
```bash
POST /api/v1/products
Content-Type: application/json

{
  "name": "Wireless Mouse",
  "price": 29.99,
  "stock": 100,
  "category_id": 3
}
```

### Create Bulk
```bash
POST /api/v1/products
Content-Type: application/json

{
  "products": [
    {"name": "Product 1", "price": 10},
    {"name": "Product 2", "price": 20}
  ]
}
```

### Update
```bash
PUT /api/v1/products/1
Content-Type: application/json

{
  "price": 24.99,
  "stock": 150
}
```

### Update Multiple
```bash
POST /api/v1/products/update-multiple
Content-Type: application/json

{
  "products": [
    {"id": 1, "stock": 50},
    {"id": 2, "stock": 75}
  ]
}
```

### Delete
```bash
DELETE /api/v1/products/1
```

---

## Security

### Relation Allowlist

Always define allowed relations in your model:
```php
class Product extends BaseModel
{
    // ✅ Good: Explicit allowlist
    const RELATIONS = ['category', 'reviews'];
}
```

Without this, requests like:
```json
{
  "oper": {
    "internalAuditLogs": {"and": ["..."]}
  }
}
```

Will return `400 Bad Request`:
```json
{
  "message": "Relation 'internalAuditLogs' is not allowed. Allowed: category, reviews"
}
```

### Query Limits

Configure in `config/rest-generic-class.php`:
```php
'filtering' => [
    'max_depth' => 5,        // Max nesting levels
    'max_conditions' => 100, // Max filter conditions
]
```

---

## Configuration

After publishing, edit `config/rest-generic-class.php`:
```php
<?php

return [
    'logging' => [
        'rest-generic-class' => [
            'driver' => 'single',
            'path' => storage_path('logs/rest-generic-class.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
    ],
    
    'filtering' => [
        'max_depth' => 5,
        'max_conditions' => 100,
        'strict_relations' => true,  // Require Model::RELATIONS
    ],
];
```

---

## Next Steps

- 📖 **[Full Documentation](DOCUMENTATION.md)** - Complete developer guide
- 🔍 **[Nested Queries Guide](DOCUMENTATION.md#nested-queries)** - Advanced filtering
- 🎯 **[Field Selection](DOCUMENTATION.md#field-selection)** - Response optimization
- 🔐 **[Permissions](DOCUMENTATION.md#spatie-permissions)** - Role-based access

---

## Requirements

- PHP 8.0+
- Laravel 12.x
- MySQL 5.7+ / PostgreSQL 10+ / MongoDB 4.0+

---

## Support

- 📧 Email: charlietyn@gmail.com
- 🐛 Issues: [GitHub Issues](https://github.com/ronu/rest-generic-class/issues)
- 📖 Docs: [Full Documentation](DOCUMENTATION.md)

---

## License

MIT License - see [LICENSE.md](LICENSE.md)

---

<div align="center">

**[⬆ Back to Top](#rest-generic-class-for-laravel)**

Made with ❤️ by [Charlietyn](https://github.com/charlietyn)

</div>