# REST Generic Class - Developer Documentation

Complete technical reference for Laravel developers.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Installation & Setup](#2-installation--setup)
3. [CRUD Operations](#3-crud-operations)
4. [Listing & Filtering](#4-listing--filtering)
5. [Nested Queries](#5-nested-queries)
6. [Field Selection](#6-field-selection)
7. [Pagination & Sorting](#7-pagination--sorting)
8. [Hierarchical Listing](#8-hierarchical-listing)
9. [Validation](#9-validation)
10. [Spatie Permissions](#10-spatie-permissions)
11. [Advanced Patterns](#11-advanced-patterns)
12. [API Reference](#12-api-reference)
13. [Troubleshooting](#13-troubleshooting)

---

## 1. Architecture Overview

### 1.1 Component Stack
```
┌─────────────────────────────────────┐
│   HTTP Request (JSON)               │
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Middleware: TransformData         │  Validates & transforms input
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Controller: RestController        │  Parses query params
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Service: BaseService              │  Business logic
│   ├─ process_query()                │
│   ├─ applyOperTree()                │  Filters + whereHas
│   ├─ relations()                    │  Eager loading
│   └─ pagination()                   │
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Eloquent Query Builder            │
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Database                          │
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   JSON Response                     │
└─────────────────────────────────────┘
```

### 1.2 Core Classes

| Class | Location | Purpose |
|-------|----------|---------|
| `BaseModel` | `Core/Models/BaseModel.php` | Enhanced Eloquent model with validation |
| `BaseService` | `Core/Services/BaseService.php` | Query building & CRUD operations |
| `RestController` | `Core/Controllers/RestController.php` | HTTP request handling |
| `BaseFormRequest` | `Core/Requests/BaseFormRequest.php` | Validation rules management |
| `HasDynamicFilter` | `Core/Traits/HasDynamicFilter.php` | Dynamic filtering engine |

### 1.3 Request Flow Example

**Request:**
```http
GET /api/v1/orders?relations=["user:id,name"]&oper={"user":{"and":["active|=|1"]}}
```

**Flow:**
1. Route → `OrderController@index`
2. Controller calls `$this->service->list_all($params)`
3. Service calls `process_query($params, $query)`
4. `process_query()`:
    - Calls `applyOperTree()` → Adds `whereHas('user', ...)`
    - Calls `relations()` → Adds `with(['user:id,name'])`
5. Executes query
6. Returns JSON

---

## 2. Installation & Setup

### 2.1 Composer Installation
```bash
composer require ronu/rest-generic-class
```

### 2.2 Configuration

Publish config (optional):
```bash
php artisan vendor:publish --tag=rest-generic-class-config
```

**File:** `config/rest-generic-class.php`
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
        // Maximum nesting depth for nested queries
        'max_depth' => env('REST_MAX_DEPTH', 5),
        
        // Maximum total filter conditions
        'max_conditions' => env('REST_MAX_CONDITIONS', 100),
        
        // Require Model::RELATIONS to be defined
        'strict_relations' => env('REST_STRICT_RELATIONS', true),
        
        // Allowed operators
        'allowed_operators' => [
            '=', '!=', '<', '>', '<=', '>=',
            'like', 'not like', 'ilike', 'not ilike',
            'in', 'not in', 'between', 'not between',
            'null', 'not null', 'date', 'not date',
            'exists', 'not exists', 'regexp', 'not regexp'
        ],
    ],
];
```

**Environment Variables:**
```env
# .env
REST_MAX_DEPTH=5
REST_MAX_CONDITIONS=100
REST_STRICT_RELATIONS=true
LOG_LEVEL=debug
```

### 2.3 Model Setup

**Minimal Setup:**
```php
<?php
// app/Models/Order.php

namespace App\Models;

use Ronu\RestGenericClass\Core\Models\BaseModel;

class Order extends BaseModel
{
    protected $fillable = ['status', 'user_id', 'total', 'notes'];
    
    const RELATIONS = ['user', 'items', 'payments'];
    const MODEL = 'order'; // For bulk operations
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
    
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
```

**With Validation:**
```php
<?php

class Order extends BaseModel
{
    // ... fillable, relations ...
    
    protected function rules($scenario)
    {
        return [
            'create' => [
                'status' => 'required|in:pending,processing,completed',
                'user_id' => 'required|exists:users,id',
                'total' => 'required|numeric|min:0',
                'notes' => 'nullable|string|max:1000',
            ],
            'update' => [
                'status' => 'sometimes|in:pending,processing,completed',
                'total' => 'sometimes|numeric|min:0',
                'notes' => 'nullable|string|max:1000',
            ],
        ];
    }
}
```

### 2.4 Service Setup
```php
<?php
// app/Services/OrderService.php

namespace App\Services;

use Ronu\RestGenericClass\Core\Services\BaseService;
use App\Models\Order;

class OrderService extends BaseService
{
    public function __construct()
    {
        parent::__construct(Order::class);
    }
    
    // Add custom methods
    public function getPendingOrders()
    {
        return $this->list_all([
            'oper' => ['and' => ['status|=|pending']],
            'orderby' => [['created_at' => 'asc']]
        ]);
    }
}
```

### 2.5 Controller Setup
```php
<?php
// app/Http/Controllers/Api/OrderController.php

namespace App\Http\Controllers\Api;

use Ronu\RestGenericClass\Core\Controllers\RestController;
use App\Services\OrderService;
use App\Models\Order;

class OrderController extends RestController
{
    protected $modelClass = Order::class;
    
    public function __construct(OrderService $service)
    {
        $this->service = $service;
    }
    
    // Inherited methods:
    // - index()
    // - show($id)
    // - store(Request)
    // - update(Request, $id)
    // - destroy($id)
    // - updateMultiple(Request)
    // - deleteById(Request)
}
```

### 2.6 Routes Setup
```php
<?php
// routes/api.php

use App\Http\Controllers\Api\OrderController;

Route::prefix('v1')->group(function () {
    // Standard REST routes
    Route::apiResource('orders', OrderController::class);
    
    // Bulk operations
    Route::post('orders/update-multiple', [OrderController::class, 'updateMultiple']);
    Route::post('orders/delete-by-id', [OrderController::class, 'deleteById']);
});
```

**Generated Routes:**

| Method | URI | Action | Description |
|--------|-----|--------|-------------|
| `GET` | `/api/v1/orders` | `index` | List all orders |
| `GET` | `/api/v1/orders/{id}` | `show` | Get single order |
| `POST` | `/api/v1/orders` | `store` | Create order(s) |
| `PUT/PATCH` | `/api/v1/orders/{id}` | `update` | Update order |
| `DELETE` | `/api/v1/orders/{id}` | `destroy` | Delete order |
| `POST` | `/api/v1/orders/update-multiple` | `updateMultiple` | Bulk update |
| `POST` | `/api/v1/orders/delete-by-id` | `deleteById` | Bulk delete |

---

## 3. CRUD Operations

### 3.1 Create Single Resource

**Insomnia/Postman Request:**
```http
POST http://localhost:8000/api/v1/orders
Content-Type: application/json

{
  "status": "pending",
  "user_id": 42,
  "total": 250.50,
  "notes": "First order from customer"
}
```

**cURL:**
```bash
curl -X POST http://localhost:8000/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{
    "status": "pending",
    "user_id": 42,
    "total": 250.50
  }'
```

**Response (Success):**
```json
{
  "success": true,
  "model": {
    "id": 101,
    "status": "pending",
    "user_id": 42,
    "total": 250.5,
    "notes": "First order from customer",
    "created_at": "2024-01-20T10:30:00.000000Z",
    "updated_at": "2024-01-20T10:30:00.000000Z"
  }
}
```

**Response (Validation Error):**
```json
{
  "success": false,
  "errors": [
    {
      "user_id": [
        "The user id field is required."
      ],
      "total": [
        "The total must be a number."
      ],
      "model": "App\\Models\\Order"
    }
  ]
}
```

### 3.2 Create Bulk Resources

**Request:**
```http
POST http://localhost:8000/api/v1/orders
Content-Type: application/json

{
  "orders": [
    {
      "status": "pending",
      "user_id": 10,
      "total": 100.00
    },
    {
      "status": "processing",
      "user_id": 11,
      "total": 200.00
    },
    {
      "status": "completed",
      "user_id": 12,
      "total": 150.00
    }
  ]
}
```

**Note:** The key must match `Model::MODEL` constant (lowercase, e.g., "orders" for Order model).

**Response:**
```json
{
  "success": true,
  "0": {
    "success": true,
    "model": {
      "id": 101,
      "status": "pending",
      "user_id": 10,
      "total": 100.0
    }
  },
  "1": {
    "success": true,
    "model": {
      "id": 102,
      "status": "processing",
      "user_id": 11,
      "total": 200.0
    }
  },
  "2": {
    "success": true,
    "model": {
      "id": 103,
      "status": "completed",
      "user_id": 12,
      "total": 150.0
    }
  }
}
```

**Partial Failure:**

If one item fails validation, it continues with others:
```json
{
  "success": false,
  "0": {
    "success": true,
    "model": {...}
  },
  "1": {
    "success": false,
    "errors": {
      "total": ["The total must be a number."]
    }
  },
  "error": [
    [
      {"total": ["The total must be a number."]},
      "App\\Models\\Order"
    ]
  ]
}
```

### 3.3 Read Single Resource

**Request:**
```http
GET http://localhost:8000/api/v1/orders/101
```

**Response:**
```json
{
  "id": 101,
  "status": "pending",
  "user_id": 42,
  "total": 250.5,
  "notes": "First order",
  "created_at": "2024-01-20T10:30:00.000000Z",
  "updated_at": "2024-01-20T10:30:00.000000Z"
}
```

**With Relations:**
```http
GET http://localhost:8000/api/v1/orders/101?relations=["user","items:id,product_name,quantity"]
```

**Response:**
```json
{
  "id": 101,
  "status": "pending",
  "total": 250.5,
  "user": {
    "id": 42,
    "name": "John Doe",
    "email": "john@example.com"
  },
  "items": [
    {
      "id": 501,
      "product_name": "Laptop",
      "quantity": 1
    },
    {
      "id": 502,
      "product_name": "Mouse",
      "quantity": 2
    }
  ]
}
```

### 3.4 Update Single Resource

**Request (PUT):**
```http
PUT http://localhost:8000/api/v1/orders/101
Content-Type: application/json

{
  "status": "processing",
  "notes": "Payment confirmed"
}
```

**Request (PATCH - same behavior):**
```http
PATCH http://localhost:8000/api/v1/orders/101
Content-Type: application/json

{
  "status": "completed"
}
```

**Response:**
```json
{
  "success": true,
  "model": {
    "id": 101,
    "status": "completed",
    "user_id": 42,
    "total": 250.5,
    "notes": "Payment confirmed",
    "updated_at": "2024-01-20T14:15:00.000000Z"
  }
}
```

### 3.5 Update Multiple Resources

**Request:**
```http
POST http://localhost:8000/api/v1/orders/update-multiple
Content-Type: application/json

{
  "orders": [
    {
      "id": 101,
      "status": "shipped"
    },
    {
      "id": 102,
      "status": "shipped"
    },
    {
      "id": 103,
      "status": "delivered"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "models": [
    {
      "success": true,
      "model": {
        "id": 101,
        "status": "shipped",
        "updated_at": "2024-01-20T15:00:00.000000Z"
      }
    },
    {
      "success": true,
      "model": {
        "id": 102,
        "status": "shipped",
        "updated_at": "2024-01-20T15:00:00.000000Z"
      }
    },
    {
      "success": true,
      "model": {
        "id": 103,
        "status": "delivered",
        "updated_at": "2024-01-20T15:00:00.000000Z"
      }
    }
  ]
}
```

### 3.6 Delete Single Resource

**Request:**
```http
DELETE http://localhost:8000/api/v1/orders/101
```

**Response:**
```json
{
  "success": true,
  "model": {
    "id": 101,
    "status": "shipped",
    "user_id": 42,
    "total": 250.5
  }
}
```

### 3.7 Delete Multiple Resources

**Request (Array):**
```http
POST http://localhost:8000/api/v1/orders/delete-by-id
Content-Type: application/json

[101, 102, 103]
```

**Request (Object):**
```http
POST http://localhost:8000/api/v1/orders/delete-by-id
Content-Type: application/json

{
  "ids": [101, 102, 103]
}
```

**Response:**
```json
{
  "success": true
}
```

---

## 4. Listing & Filtering

### 4.1 Basic Listing

**Request:**
```http
GET http://localhost:8000/api/v1/orders
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "status": "pending",
      "total": 100.0
    },
    {
      "id": 2,
      "status": "completed",
      "total": 200.0
    }
  ]
}
```

### 4.2 Field Selection

**Request:**
```http
GET http://localhost:8000/api/v1/orders?select=["id","status","total"]
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "status": "pending",
      "total": 100.0
    },
    {
      "id": 2,
      "status": "completed",
      "total": 200.0
    }
  ]
}
```

### 4.3 Simple Filters (Array Syntax)

**Scenario:** Get active orders with total >= 100

**Request:**
```http
GET http://localhost:8000/api/v1/orders?oper=["status|=|active","total|>=|100"]
```

**Equivalent SQL:**
```sql
SELECT * FROM orders 
WHERE status = 'active' 
  AND total >= 100
```

**Response:**
```json
{
  "data": [
    {
      "id": 5,
      "status": "active",
      "total": 150.0
    },
    {
      "id": 8,
      "status": "active",
      "total": 250.0
    }
  ]
}
```

### 4.4 Complex Filters (Object Syntax)

**Scenario:** Get orders that are either pending OR processing, AND total > 50

**Request:**
```http
POST http://localhost:8000/api/v1/orders/_search
Content-Type: application/json

{
  "oper": {
    "and": [
      "total|>|50"
    ],
    "or": [
      "status|=|pending",
      "status|=|processing"
    ]
  }
}
```

**Equivalent SQL:**
```sql
SELECT * FROM orders 
WHERE total > 50
  AND (status = 'pending' OR status = 'processing')
```

### 4.5 Operators Reference

#### Comparison Operators
```json
{
  "oper": {
    "and": [
      "price|=|99.99",          // Equals
      "stock|!=|0",             // Not equals
      "rating|>|4.5",           // Greater than
      "views|>=|1000",          // Greater or equal
      "discount|<|50",          // Less than
      "age|<=|18"               // Less or equal
    ]
  }
}
```

#### String Operators
```json
{
  "oper": {
    "and": [
      "name|like|%laptop%",        // Contains (case-sensitive)
      "email|not like|%temp%",     // Not contains
      "code|ilike|%ABC%",          // Contains (case-insensitive, PostgreSQL)
      "description|not ilike|%test%"
    ]
  }
}
```

#### List Operators
```json
{
  "oper": {
    "and": [
      "status|in|pending,active,processing",     // In list
      "type|not in|deleted,archived,spam"        // Not in list
    ]
  }
}
```

#### Range Operators
```json
{
  "oper": {
    "and": [
      "price|between|10,100",           // Between 10 and 100 (inclusive)
      "discount|not between|50,100"     // NOT between 50 and 100
    ]
  }
}
```

#### Null Operators
```json
{
  "oper": {
    "and": [
      "deleted_at|null",              // IS NULL
      "verified_at|not null"          // IS NOT NULL
    ]
  }
}
```

#### Date Operators
```json
{
  "oper": {
    "and": [
      "created_at|date|2024-01-20",         // DATE(created_at) = '2024-01-20'
      "updated_at|not date|2024-01-19"      // DATE(updated_at) != '2024-01-19'
    ]
  }
}
```

#### PostgreSQL Specific
```json
{
  "oper": {
    "and": [
      "name|ilikeu|%café%"    // Unaccented search (matches "cafe", "café", "Café")
    ]
  }
}
```

**Implementation:**
```sql
WHERE unaccent(name) ILIKE unaccent('%café%')
```

### 4.6 Complex Filtering Examples

#### Example 1: E-Commerce Product Search

**Scenario:** Find electronics products, in stock, price $50-$500, good reviews

**Request:**
```http
POST http://localhost:8000/api/v1/products/_search
Content-Type: application/json

{
  "oper": {
    "and": [
      "category_id|=|3",
      "stock|>|0",
      "price|between|50,500",
      "active|=|1"
    ]
  },
  "orderby": [
    {"rating": "desc"},
    {"price": "asc"}
  ]
}
```

#### Example 2: User Dashboard

**Scenario:** Show my active orders from last 30 days

**Request:**
```http
POST http://localhost:8000/api/v1/orders/_search
Content-Type: application/json

{
  "select": ["id", "status", "total", "created_at"],
  "relations": ["items:id,product_name,quantity"],
  "oper": {
    "and": [
      "user_id|=|42",
      "status|in|pending,processing,shipped",
      "created_at|>=|2024-01-01"
    ]
  },
  "orderby": [{"created_at": "desc"}]
}
```

#### Example 3: Admin Report

**Scenario:** High-value orders needing attention

**Request:**
```http
POST http://localhost:8000/api/v1/orders/_search
Content-Type: application/json

{
  "select": ["id", "user_id", "total", "status", "created_at"],
  "relations": ["user:id,name,email"],
  "oper": {
    "and": [
      "total|>|1000",
      "status|=|pending",
      "created_at|<|2024-01-15"
    ]
  },
  "orderby": [
    {"total": "desc"}
  ],
  "pagination": {
    "page": 1,
    "pageSize": 50
  }
}
```

---

## 5. Nested Queries

### 5.1 Concept

**Nested queries** filter the root dataset based on related model conditions using `whereHas`.

**Key Difference:**

| Approach | SQL | Purpose |
|----------|-----|---------|
| **Eager Loading** | 2 separate queries | Load related data |
| **Nested Query** | EXISTS subquery | Filter root records |

**Example:**
```php
// Eager Loading (loads all orders, then filters loaded users)
Order::with(['user' => fn($q) => $q->where('active', 1)])->get();

// Nested Query (only returns orders from active users)
Order::whereHas('user', fn($q) => $q->where('active', 1))->get();
```

### 5.2 Single Level Nested Query

**Scenario:** Get orders from verified users

**Request:**
```http
POST http://localhost:8000/api/v1/orders/_search
Content-Type: application/json

{
  "oper": {
    "and": [
      "status|=|active"
    ],
    "user": {
      "and": [
        "email_verified_at|not null",
        "active|=|1"
      ]
    }
  }
}
```

**Generated SQL:**
```sql
SELECT orders.* FROM orders
WHERE status = 'active'
  AND EXISTS (
    SELECT * FROM users 
    WHERE orders.user_id = users.id 
      AND users.email_verified_at IS NOT NULL
      AND users.active = 1
  )
```

**Eloquent Equivalent:**
```php
Order::where('status', 'active')
    ->whereHas('user', fn($q) => $q
        ->whereNotNull('email_verified_at')
        ->where('active', 1)
    )
    ->get();
```

### 5.3 Multi-Level Nested Query

**Scenario:** Get orders from users who have admin role

**Request:**
```http
POST http://localhost:8000/api/v1/orders/_search
Content-Type: application/json

{
  "oper": {
    "user": {
      "and": [
        "active|=|1"
      ],
      "roles": {
        "and": [
          "name|=|admin"
        ]
      }
    }
  }
}
```

**Generated SQL:**
```sql
SELECT orders.* FROM orders
WHERE EXISTS (
  SELECT * FROM users 
  WHERE orders.user_id = users.id 
    AND users.active = 1
    AND EXISTS (
      SELECT * FROM roles
      INNER JOIN role_user ON roles.id = role_user.role_id
      WHERE role_user.user_id = users.id
        AND roles.name = 'admin'
    )
)
```

**Eloquent Equivalent:**
```php
Order::whereHas('user', fn($q) => $q
    ->where('active', 1)
    ->whereHas('roles', fn($rq) => $rq
        ->where('name', 'admin')
    )
)->get();
```

### 5.4 Dot Notation (Shorthand)

**Scenario:** Same as above, using dot notation

**Request:**
```http
POST http://localhost:8000/api/v1/orders/_search
Content-Type: application/json

{
  "oper": {
    "user.roles": {
      "and": [
        "name|=|admin"
      ]
    }
  }
}
```

**Equivalent to previous multi-level query**

### 5.5 Multiple Relations

**Scenario:** Orders from verified users with completed payments

**Request:**
```http
POST http://localhost:8000/api/v1/orders/_search
Content-Type: application/json

{
  "oper": {
    "and": [
      "status|in|pending,processing"
    ],
    "user": {
      "and": [
        "email_verified_at|not null"
      ]
    },
    "payments": {
      "and": [
        "status|=|completed",
        "amount|>|0"
      ]
    }
  }
}
```

**Generated Eloquent:**
```php
Order::whereIn('status', ['pending', 'processing'])
    ->whereHas('user', fn($q) => 
        $q->whereNotNull('email_verified_at')
    )
    ->whereHas('payments', fn($q) => $q
        ->where('status', 'completed')
        ->where('amount', '>', 0)
    )
    ->get();
```

### 5.6 Complex Real-World Example

**Scenario:** E-commerce analytics query

**Requirements:**
- Products from active categories
- From stores with rating > 4.0
- That have reviews with avg rating >= 4.5
- In stock
- Price between $50-$1000

**Request:**
```http
POST http://localhost:8000/api/v1/products/_search
Content-Type: application/json

{
  "select": ["id", "name", "price", "stock"],
  "oper": {
    "and": [
      "active|=|1",
      "stock|>|0",
      "price|between|50,1000"
    ],
    "category": {
      "and": [
        "active|=|1"
      ],
      "store": {
        "and": [
          "rating|>|4.0",
          "verified|=|1"
        ]
      }
    },
    "reviews": {
      "and": [
        "rating|>=|4.5"
      ]
    }
  },
  "orderby": [
    {"rating_avg": "desc"}
  ]
}
```

**This filters products at 3 levels:**
1. Product level: active, in stock, price range
2. Category → Store: active category from verified high-rated stores
3. Reviews: products with good reviews

---

## 6. Field Selection

### 6.1 Main Model Field Selection

**Request:**
```http
GET http://localhost:8000/api/v1/orders?select=["id","status","total","created_at"]
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "status": "pending",
      "total": 100.0,
      "created_at": "2024-01-20T10:00:00.000000Z"
    }
  ]
}
```

### 6.2 Relation Field Selection

**Syntax:** `"relation:field1,field2,field3"`

**Request:**
```http
GET http://localhost:8000/api/v1/orders?relations=["user:id,name,email"]
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "status": "pending",
      "total": 100.0,
      "user": {
        "id": 42,
        "name": "John Doe",
        "email": "john@example.com"
      }
    }
  ]
}
```

**Note:** Foreign keys (`user_id`) are automatically included when needed.

### 6.3 Multiple Relations with Field Selection

**Request:**
```http
GET http://localhost:8000/api/v1/orders?relations=["user:id,name","items:id,product_name,quantity","payments:id,amount,method"]
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "status": "pending",
      "total": 350.0,
      "user": {
        "id": 42,
        "name": "John Doe"
      },
      "items": [
        {
          "id": 501,
          "product_name": "Laptop",
          "quantity": 1
        }
      ],
      "payments": [
        {
          "id": 301,
          "amount": 350.0,
          "method": "credit_card"
        }
      ]
    }
  ]
}
```

### 6.4 Nested Relation Field Selection

**Request:**
```http
GET http://localhost:8000/api/v1/orders?relations=["user.roles:id,name","items.product:id,name,price"]
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "user": {
        "id": 42,
        "name": "John Doe",
        "roles": [
          {
            "id": 2,
            "name": "customer"
          }
        ]
      },
      "items": [
        {
          "id": 501,
          "product": {
            "id": 200,
            "name": "Laptop Pro 15\"",
            "price": 1299.99
          }
        }
      ]
    }
  ]
}
```

### 6.5 Combined: Nested Queries + Field Selection

**Scenario:** Get orders from admin users, only show user id/name

**Request:**
```http
POST http://localhost:8000/api/v1/orders/_search
Content-Type: application/json

{
  "select": ["id", "status", "total"],
  "relations": ["user:id,name"],
  "oper": {
    "and": [
      "status|=|active"
    ],
    "user.roles": {
      "and": [
        "name|=|admin"
      ]
    }
  }
}
```

**Behavior:**

1. **Root Filtering:** Only orders where `user.roles.name = 'admin'` (via `whereHas`)
2. **Field Selection:** User object contains only `id` and `name`

**Response:**
```json
{
  "data": [
    {
      "id": 15,
      "status": "active",
      "total": 500.0,
      "user": {
        "id": 5,
        "name": "Admin User"
      }
    }
  ]
}
```

### 6.6 Automatic Foreign Key Inclusion

**Request:**
```http
GET http://localhost:8000/api/v1/orders?relations=["items:product_name,quantity"]
```

**What Laravel Does:**
```php
// Your request
'items:product_name,quantity'

// Laravel automatically includes
'items:id,order_id,product_name,quantity'
//      ^^  ^^^^^^^^ (foreign keys added)
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "items": [
        {
          "id": 501,
          "order_id": 1,
          "product_name": "Laptop",
          "quantity": 1
        }
      ]
    }
  ]
}
```

---

## 7. Pagination & Sorting

### 7.1 Standard Pagination

**Request:**
```http
GET http://localhost:8000/api/v1/orders?pagination={"page":2,"pageSize":20}
```

**Response:**
```json
{
  "current_page": 2,
  "data": [
    {...},
    {...}
  ],
  "first_page_url": "http://localhost:8000/api/v1/orders?page=1",
  "from": 21,
  "last_page": 10,
  "last_page_url": "http://localhost:8000/api/v1/orders?page=10",
  "links": [
    {
      "url": "http://localhost:8000/api/v1/orders?page=1",
      "label": "&laquo; Previous",
      "active": false
    },
    {
      "url": "http://localhost:8000/api/v1/orders?page=1",
      "label": "1",
      "active": false
    },
    {
      "url": "http://localhost:8000/api/v1/orders?page=2",
      "label": "2",
      "active": true
    },
    {
      "url": "http://localhost:8000/api/v1/orders?page=3",
      "label": "3",
      "active": false
    }
  ],
  "next_page_url": "http://localhost:8000/api/v1/orders?page=3",
  "path": "http://localhost:8000/api/v1/orders",
  "per_page": 20,
  "prev_page_url": "http://localhost:8000/api/v1/orders?page=1",
  "to": 40,
  "total": 200
}
```

### 7.2 Cursor Pagination (Infinite Scroll)

**First Request:**
```http
GET http://localhost:8000/api/v1/orders?pagination={"infinity":true,"pageSize":20}
```

**First Response:**
```json
{
  "data": [
    {...},
    {...}
  ],
  "next_cursor": "eyJpZCI6MjAsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0",
  "has_more": true
}
```

**Next Page Request:**
```http
GET http://localhost:8000/api/v1/orders?pagination={"infinity":true,"pageSize":20,"cursor":"eyJpZCI6MjAsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0"}
```

**Advantages of Cursor Pagination:**

- ✅ No missing/duplicate records when data changes
- ✅ Better performance for large datasets
- ✅ Ideal for infinite scroll UIs

### 7.3 Sorting (Single Column)

**Request:**
```http
GET http://localhost:8000/api/v1/orders?orderby=[{"created_at":"desc"}]
```

**Generated SQL:**
```sql
SELECT * FROM orders ORDER BY created_at DESC
```

### 7.4 Sorting (Multiple Columns)

**Request:**
```http
GET http://localhost:8000/api/v1/orders?orderby=[{"status":"asc"},{"total":"desc"},{"created_at":"desc"}]
```

**Generated SQL:**
```sql
SELECT * FROM orders 
ORDER BY status ASC, total DESC, created_at DESC
```

### 7.5 Complete Query Example

**Scenario:** Dashboard showing recent high-value orders

**Request:**
```http
POST http://localhost:8000/api/v1/orders/_search
Content-Type: application/json

{
  "select": ["id", "status", "total", "created_at"],
  "relations": ["user:id,name,email"],
  "oper": {
    "and": [
      "total|>=|500",
      "status|in|pending,processing",
      "created_at|>=|2024-01-01"
    ],
    "user": {
      "and": [
        "active|=|1"
      ]
    }
  },
  "orderby": [
    {"total": "desc"},
    {"created_at": "desc"}
  ],
  "pagination": {
    "page": 1,
    "pageSize": 25
  }
}
```

**Generated Eloquent:**
```php
Order::select(['id', 'status', 'total', 'created_at'])
    ->where('total', '>=', 500)
    ->whereIn('status', ['pending', 'processing'])
    ->where('created_at', '>=', '2024-01-01')
    ->whereHas('user', fn($q) => $q->where('active', 1))
    ->with(['user:id,name,email'])
    ->orderBy('total', 'desc')
    ->orderBy('created_at', 'desc')
    ->paginate(25);
```

---

## 8. Hierarchical Listing

Hierarchical listing allows you to retrieve data structured as a tree for self-referencing models (such as categories with subcategories, roles with parent roles, employees with managers, etc.).

### 8.1 Model Configuration

To enable hierarchical listing, define the `HIERARCHY_FIELD_ID` constant in your model:

```php
<?php
// app/Models/Role.php

namespace App\Models;

use Ronu\RestGenericClass\Core\Models\BaseModel;

class Role extends BaseModel
{
    protected $fillable = ['name', 'description', 'role_id'];

    const MODEL = 'role';
    const RELATIONS = ['hierarchyParent', 'hierarchyChildren', 'permissions'];

    // FK field pointing to parent (same model)
    const HIERARCHY_FIELD_ID = 'role_id';

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }
}
```

**Note:** The `hierarchyParent()` and `hierarchyChildren()` methods are automatically available in any model that defines `HIERARCHY_FIELD_ID`.

### 8.2 The `hierarchy` Parameter

#### Simple Usage (default values)
```http
GET /api/roles?hierarchy=true
```

#### Full Configuration
```http
POST /api/roles
Content-Type: application/json

{
  "hierarchy": {
    "children_key": "children",
    "max_depth": 3,
    "filter_mode": "with_descendants",
    "include_empty_children": true
  }
}
```

### 8.3 `hierarchy` Object Options

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `children_key` | `string` | `"children"` | Property name where children are nested |
| `max_depth` | `int\|null` | `null` | Maximum tree depth (`null` = unlimited) |
| `filter_mode` | `string` | `"match_only"` | Filter behavior mode |
| `include_empty_children` | `boolean` | `true` | Whether to include `children: []` on leaf nodes |

### 8.4 Filter Modes (`filter_mode`)

| Mode | Description |
|------|-------------|
| `match_only` | Returns only nodes that match the filters, organized hierarchically |
| `with_ancestors` | Matching nodes + all their ancestors up to the root |
| `with_descendants` | Matching nodes + all their descendants |
| `full_branch` | Matching nodes + ancestors + descendants (complete branch) |
| `root_filter` | Filter only applies to root nodes, descendants are included unfiltered |

### 8.5 Practical Example

**Data in `roles` table:**

| id | name | role_id |
|----|------|---------|
| 1 | admin | null |
| 2 | admin_users | 1 |
| 3 | editor | null |
| 4 | editor_posts | 3 |
| 5 | viewer | null |
| 6 | editor_drafts | 4 |

**Request without `hierarchy`:**
```http
GET /api/roles
```

**Response (flat array):**
```json
{
  "data": [
    {"id": 1, "name": "admin", "role_id": null},
    {"id": 2, "name": "admin_users", "role_id": 1},
    {"id": 3, "name": "editor", "role_id": null},
    {"id": 4, "name": "editor_posts", "role_id": 3},
    {"id": 5, "name": "viewer", "role_id": null},
    {"id": 6, "name": "editor_drafts", "role_id": 4}
  ]
}
```

**Request with `hierarchy=true`:**
```http
GET /api/roles?hierarchy=true
```

**Response (tree structure):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "admin",
      "role_id": null,
      "children": [
        {
          "id": 2,
          "name": "admin_users",
          "role_id": 1,
          "children": []
        }
      ]
    },
    {
      "id": 3,
      "name": "editor",
      "role_id": null,
      "children": [
        {
          "id": 4,
          "name": "editor_posts",
          "role_id": 3,
          "children": [
            {
              "id": 6,
              "name": "editor_drafts",
              "role_id": 4,
              "children": []
            }
          ]
        }
      ]
    },
    {
      "id": 5,
      "name": "viewer",
      "role_id": null,
      "children": []
    }
  ]
}
```

### 8.6 Combining with Other Features

Hierarchical listing is compatible with all existing features:

```http
POST /api/roles
Content-Type: application/json

{
  "select": ["id", "name", "role_id", "active"],
  "relations": ["permissions:id,name"],
  "oper": {
    "and": ["active|=|1"]
  },
  "orderby": [{"name": "asc"}],
  "pagination": {
    "page": 1,
    "pageSize": 10
  },
  "hierarchy": {
    "children_key": "sub_roles",
    "max_depth": 2,
    "filter_mode": "with_descendants"
  }
}
```

**Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "name": "admin",
      "role_id": null,
      "active": 1,
      "permissions": [{"id": 1, "name": "all"}],
      "sub_roles": [
        {
          "id": 2,
          "name": "admin_users",
          "role_id": 1,
          "active": 1,
          "permissions": [{"id": 2, "name": "users.manage"}],
          "sub_roles": []
        }
      ]
    }
  ],
  "per_page": 10,
  "total": 3,
  "last_page": 1
}
```

### 8.7 Show with Hierarchy

The `show` endpoint (`GET /resource/{id}`) also supports hierarchy with specific modes:

#### Available modes for show

| Mode | Description |
|------|-------------|
| `node_only` | Only the requested node with `children: []` |
| `with_descendants` | The node + all its descendants (default) |
| `with_ancestors` | Hierarchical chain from root to the node |
| `full_branch` | Ancestors + node + complete descendants |

#### Example: Node with descendants (default)

```http
GET /api/roles/4?hierarchy=true
```

**Response:**
```json
{
  "id": 4,
  "name": "editor_posts",
  "role_id": 3,
  "children": [
    {
      "id": 6,
      "name": "editor_drafts",
      "role_id": 4,
      "children": []
    }
  ]
}
```

#### Example: Ancestor chain

```http
GET /api/roles/6?hierarchy={"mode":"with_ancestors"}
```

**Response:** (shows chain from root to node 6)
```json
{
  "id": 3,
  "name": "editor",
  "role_id": null,
  "children": [
    {
      "id": 4,
      "name": "editor_posts",
      "role_id": 3,
      "children": [
        {
          "id": 6,
          "name": "editor_drafts",
          "role_id": 4,
          "children": []
        }
      ]
    }
  ]
}
```

#### Example: Full branch

```http
GET /api/roles/4?hierarchy={"mode":"full_branch","max_depth":3}
```

**Response:** (ancestors + node + descendants)
```json
{
  "id": 3,
  "name": "editor",
  "role_id": null,
  "children": [
    {
      "id": 4,
      "name": "editor_posts",
      "role_id": 3,
      "children": [
        {
          "id": 6,
          "name": "editor_drafts",
          "role_id": 4,
          "children": []
        }
      ]
    }
  ]
}
```

### 8.8 Pagination with Hierarchy (Listing)

When using pagination with `hierarchy` in listing, **root nodes** are paginated:

- `total`: Number of root nodes
- `per_page`: Root nodes per page
- Each root node includes all its descendants (respecting `max_depth`)

**Standard pagination:**
```http
GET /api/roles?hierarchy=true&pagination={"page":1,"pageSize":2}
```

**Infinite scroll (cursor):**
```http
GET /api/roles?hierarchy=true&pagination={"infinity":true,"pageSize":2}
```

### 8.9 Available Model Methods

When you define `HIERARCHY_FIELD_ID`, the following methods are available:

| Method | Description |
|--------|-------------|
| `hasHierarchyField()` | Checks if model has hierarchy enabled |
| `getHierarchyFieldId()` | Gets the FK field name |
| `hierarchyParent()` | BelongsTo relation to parent |
| `hierarchyChildren()` | HasMany relation to children |
| `isHierarchyRoot()` | Checks if this is a root node (no parent) |
| `getHierarchyAncestors()` | Gets all ancestors up to the root |
| `getHierarchyDescendants($maxDepth)` | Gets all descendants |

**Code usage example:**
```php
$role = Role::find(4); // editor_posts

// Check if root
$isRoot = $role->isHierarchyRoot(); // false

// Get parent
$parent = $role->hierarchyParent; // editor (id: 3)

// Get direct children
$children = $role->hierarchyChildren; // [editor_drafts]

// Get all ancestors
$ancestors = $role->getHierarchyAncestors(); // [editor]

// Get all descendants
$descendants = $role->getHierarchyDescendants(); // [editor_drafts]
```

---

## 9. Validation

### 9.1 Model-Based Validation

**Define in Model:**
```php
<?php
// app/Models/Order.php

class Order extends BaseModel
{
    protected function rules($scenario)
    {
        return [
            'create' => [
                'status' => 'required|in:pending,processing,completed,cancelled',
                'user_id' => 'required|exists:users,id',
                'total' => 'required|numeric|min:0',
                'notes' => 'nullable|string|max:1000',
            ],
            'update' => [
                'status' => 'sometimes|in:pending,processing,completed,cancelled',
                'user_id' => 'sometimes|exists:users,id',
                'total' => 'sometimes|numeric|min:0',
                'notes' => 'nullable|string|max:1000',
            ],
            'bulk_create' => [
                'status' => 'required|in:pending,processing,completed',
                'user_id' => 'required|exists:users,id',
                'total' => 'required|numeric|min:0',
            ],
            'bulk_update' => [
                'id' => 'required|exists:orders,id',
                'status' => 'sometimes|in:pending,processing,completed,cancelled',
                'total' => 'sometimes|numeric|min:0',
            ],
        ];
    }
}
```

**Validation is triggered automatically** in:
- `POST /orders` (scenario: `create` or `bulk_create`)
- `PUT /orders/{id}` (scenario: `update`)
- `POST /orders/update-multiple` (scenario: `bulk_update`)

### 8.2 Custom Request Validation

**Create Form Request:**
```php
<?php
// app/Http/Requests/OrderRequest.php

namespace App\Http\Requests;

use Ronu\RestGenericClass\Core\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class OrderRequest extends BaseFormRequest
{
    protected string $entity_name = "order";
    protected bool $has_escenario = true;
    
    public function pathRules(): string
    {
        return __DIR__ . '/../../Models/Order/rules.php';
    }
}
```

**Create Rules File:**
```php
<?php
// app/Models/Order/rules.php

use Illuminate\Validation\Rule;

return [
    'create' => [
        'status' => 'required|in:pending,processing,completed',
        'user_id' => [
            'required',
            'integer',
            Rule::exists('users', 'id')->where(fn($q) => $q->where('active', 1)),
        ],
        'total' => 'required|numeric|min:0|max:999999.99',
        'notes' => 'nullable|string|max:1000',
        'items' => 'required|array|min:1',
        'items.*.product_id' => 'required|exists:products,id',
        'items.*.quantity' => 'required|integer|min:1|max:1000',
        'items.*.price' => 'required|numeric|min:0',
    ],
    
    'update' => [
        'status' => [
            'sometimes',
            Rule::in(['pending', 'processing', 'completed', 'cancelled']),
        ],
        'notes' => 'nullable|string|max:1000',
    ],
    
    'bulk_create' => [
        // Same as create
    ],
    
    'bulk_update' => [
        'id' => 'required|exists:orders,id',
        'status' => 'sometimes|in:pending,processing,completed,cancelled',
    ],
];
```

**Use in Controller:**
```php
<?php
// app/Http/Controllers/Api/OrderController.php

use App\Http\Requests\OrderRequest;

class OrderController extends RestController
{
    public function store(OrderRequest $request)
    {
        // Validation already passed
        return parent::store($request);
    }
    
    public function update(OrderRequest $request, $id)
    {
        return parent::update($request, $id);
    }
}
```

### 8.3 Validation Errors Response

**Request (Invalid):**
```http
POST http://localhost:8000/api/v1/orders
Content-Type: application/json

{
  "status": "invalid_status",
  "user_id": "not_a_number",
  "total": -50
}
```

**Response:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": [
      "The selected status is invalid."
    ],
    "user_id": [
      "The user id must be an integer.",
      "The selected user id is invalid."
    ],
    "total": [
      "The total must be at least 0."
    ]
  }
}
```

### 8.4 Custom Validation Rules
```php
<?php
// app/Rules/ValidOrderStatus.php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class ValidOrderStatus implements Rule
{
    private string $currentStatus;
    
    public function __construct(string $currentStatus)
    {
        $this->currentStatus = $currentStatus;
    }
    
    public function passes($attribute, $value)
    {
        $allowedTransitions = [
            'pending' => ['processing', 'cancelled'],
            'processing' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];
        
        return in_array($value, $allowedTransitions[$this->currentStatus] ?? []);
    }
    
    public function message()
    {
        return "Cannot transition from {$this->currentStatus} to :input.";
    }
}
```

**Use in Controller:**
```php
public function update(Request $request, $id)
{
    $order = Order::findOrFail($id);
    
    $request->validate([
        'status' => [
            'required',
            new ValidOrderStatus($order->status)
        ],
    ]);
    
    return parent::update($request, $id);
}
```

---

## 10. Spatie Permissions

### 9.1 Setup

**Install Spatie:**
```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

**Configure Model:**
```php
<?php
// app/Models/User.php

use Spatie\Permission\Traits\HasRoles;
use Ronu\RestGenericClass\Core\Traits\HasReadableUserPermissions;

class User extends Authenticatable
{
    use HasRoles, HasReadableUserPermissions;
    
    const RELATIONS = ['roles', 'permissions', 'orders'];
}
```

### 9.2 Permission Filtering

**Get users with specific permissions:**
```http
POST http://localhost:8000/api/v1/users/_search
Content-Type: application/json

{
  "oper": {
    "permissions": {
      "and": [
        "name|like|%edit%"
      ]
    }
  }
}
```

**Get users with admin role:**
```http
POST http://localhost:8000/api/v1/users/_search
Content-Type: application/json

{
  "oper": {
    "roles": {
      "and": [
        "name|=|admin"
      ]
    }
  }
}
```

### 9.3 Middleware Authorization

**Setup Middleware:**
```php
<?php
// app/Http/Kernel.php

protected $routeMiddleware = [
    'permission' => \Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize::class,
];
```

**Apply to Routes:**
```php
<?php
// routes/api.php

Route::middleware(['auth:api', 'permission'])->group(function () {
    Route::apiResource('orders', OrderController::class);
});
```

**Route-specific permissions:**
```php
Route::get('orders', [OrderController::class, 'index'])
    ->middleware('permission:orders.view');
    
Route::post('orders', [OrderController::class, 'store'])
    ->middleware('permission:orders.create');
```

### 9.4 Permission Refresh Command

**Generate permissions from routes:**
```bash
php artisan permission:refresh --guard=api
```

**This creates permissions like:**
- `orders.index`
- `orders.show`
- `orders.create`
- `orders.update`
- `orders.delete`

---

## 11. Advanced Patterns

### 10.1 Custom Service Methods
```php
<?php
// app/Services/OrderService.php

class OrderService extends BaseService
{
    public function getPendingOrders()
    {
        return $this->list_all([
            'oper' => ['and' => ['status|=|pending']],
            'orderby' => [['created_at' => 'asc']]
        ]);
    }
    
    public function getOrdersByDateRange($startDate, $endDate)
    {
        return $this->list_all([
            'oper' => [
                'and' => [
                    "created_at|>=|{$startDate}",
                    "created_at|<=|{$endDate}"
                ]
            ]
        ]);
    }
    
    public function getHighValueOrders($threshold = 1000)
    {
        return $this->list_all([
            'oper' => ['and' => ["total|>|{$threshold}"]],
            'orderby' => [['total' => 'desc']]
        ], false); // false = return array, not JSON
    }
}
```

### 10.2 Soft Deletes

**Enable in Model:**
```php
<?php
// app/Models/Order.php

use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends BaseModel
{
    use SoftDeletes;
}
```

**Query Parameters:**
```http
# Include soft deleted
GET /api/v1/orders?soft_delete=true

# Only soft deleted
GET /api/v1/orders?soft_delete=only

# Exclude soft deleted (default)
GET /api/v1/orders
```

### 10.3 Performance Optimization

**Add Database Indexes:**
```php
<?php
// database/migrations/xxxx_add_indexes_to_orders.php

public function up()
{
    Schema::table('orders', function (Blueprint $table) {
        $table->index('user_id');
        $table->index('status');
        $table->index('created_at');
        $table->index(['status', 'created_at']); // Compound index
    });
}
```

**Monitor Slow Queries:**
```php
<?php
// app/Providers/AppServiceProvider.php

use Illuminate\Support\Facades\DB;

public function boot()
{
    if (config('app.debug')) {
        DB::listen(function ($query) {
            if ($query->time > 1000) { // > 1 second
                Log::warning('Slow query detected', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time,
                ]);
            }
        });
    }
}
```

---

## 12. API Reference

### 11.1 Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `select` | `string\|array` | No | Fields to return from main model |
| `relations` | `array` | No | Relations to eager load (supports field selection) |
| `oper` | `array\|object` | No | Filter conditions (supports nested queries) |
| `orderby` | `array` | No | Sort order `[{"field":"asc\|desc"}]` |
| `pagination` | `object` | No | Pagination params `{"page":1,"pageSize":20}` |
| `_nested` | `boolean` | No | Apply filters to eager loaded relations |
| `soft_delete` | `string` | No | `true\|only` for soft deleted records |

### 11.2 Response Formats

#### Success (List)
```json
{
  "data": [...]
}
```

#### Success (Paginated)
```json
{
  "current_page": 1,
  "data": [...],
  "per_page": 20,
  "total": 100,
  ...
}
```

#### Success (Create/Update)
```json
{
  "success": true,
  "model": {...}
}
```

#### Success (Delete)
```json
{
  "success": true,
  "model": {...}
}
```

#### Error (Validation)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field": ["Error message"]
  }
}
```

#### Error (Not Found)
```json
{
  "message": "No query results for model [App\\Models\\Order] 999"
}
```

#### Error (Forbidden)
```json
{
  "message": "Forbidden: orders.edit"
}
```

#### Error (Bad Request)
```json
{
  "message": "Relation 'invalidRelation' is not allowed. Allowed: user, items"
}
```

---

## 13. Troubleshooting

### 12.1 Common Issues

#### Issue: "Relation 'X' is not allowed"

**Cause:** Relation not in `Model::RELATIONS` allowlist.

**Solution:**
```php
class Order extends BaseModel
{
    const RELATIONS = ['user', 'items', 'payments']; // Add missing relation
}
```

#### Issue: Nested filters not working

**Cause:** Relation doesn't exist or typo.

**Debug:**
```php
// Test relation exists
$order = Order::first();
dd($order->user); // Should load User model

// Check relation method
public function user() // Must match relation name in query
{
    return $this->belongsTo(User::class);
}
```

#### Issue: Missing fields in response

**Cause:** Field selection without foreign keys.

**Solution:**
```json
{
  "relations": ["user:id,name"]  // ✅ Always include 'id'
}
```

Laravel automatically adds foreign keys, but explicit is better.

#### Issue: "Maximum nesting depth exceeded"

**Cause:** Query too deeply nested.

**Solution:**
```php
// config/rest-generic-class.php
'filtering' => [
    'max_depth' => 6,  // Increase limit
]
```

Or simplify query structure.

#### Issue: Slow queries

**Solutions:**

1. Add database indexes
2. Use field selection to reduce data
3. Monitor with `DB::listen()`
4. Use eager loading wisely
```php
// Bad: N+1 problem
foreach ($orders as $order) {
    echo $order->user->name; // Query per order
}

// Good: Eager load
$orders = Order::with('user')->get();
foreach ($orders as $order) {
    echo $order->user->name; // No additional queries
}
```

---

## Appendix A: Complete Working Example

**Scenario:** Blog API with Posts, Authors, Categories, Comments

### Models
```php
<?php
// app/Models/Post.php

class Post extends BaseModel
{
    protected $fillable = ['title', 'content', 'author_id', 'category_id', 'published_at'];
    
    const RELATIONS = ['author', 'category', 'comments', 'tags'];
    
    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
    
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
    
    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
}
```

### Service
```php
<?php
// app/Services/PostService.php

class PostService extends BaseService
{
    public function __construct()
    {
        parent::__construct(Post::class);
    }
}
```

### Controller
```php
<?php
// app/Http/Controllers/Api/PostController.php

class PostController extends RestController
{
    protected $modelClass = Post::class;
    
    public function __construct(PostService $service)
    {
        $this->service = $service;
    }
}
```

### Routes
```php
<?php
// routes/api.php

Route::prefix('v1')->group(function () {
    Route::apiResource('posts', PostController::class);
});
```

### Example Requests

**1. Get published posts from verified authors:**
```http
POST http://localhost:8000/api/v1/posts/_search
Content-Type: application/json

{
  "select": ["id", "title", "published_at"],
  "relations": ["author:id,name", "category:id,name"],
  "oper": {
    "and": [
      "published_at|not null"
    ],
    "author": {
      "and": [
        "verified|=|1"
      ]
    }
  },
  "orderby": [{"published_at": "desc"}],
  "pagination": {"page": 1, "pageSize": 10}
}
```

**2. Get posts with good comments:**
```http
POST http://localhost:8000/api/v1/posts/_search
Content-Type: application/json

{
  "oper": {
    "comments": {
      "and": [
        "rating|>=|4"
      ]
    }
  }
}
```

**3. Get posts from technology category by admin authors:**
```http
POST http://localhost:8000/api/v1/posts/_search
Content-Type: application/json

{
  "oper": {
    "category": {
      "and": ["name|=|Technology"]
    },
    "author.roles": {
      "and": ["name|=|admin"]
    }
  }
}
```

---

## Support

- 📧 Email: charlietyn@gmail.com
- 🐛 Issues: [GitHub Issues](https://github.com/ronu/rest-generic-class/issues)
- 💬 Discussions: [GitHub Discussions](https://github.com/ronu/rest-generic-class/discussions)

---

**Last Updated:** January 2024  
**Version:** 1.8.0  
**Laravel Compatibility:** 12.x