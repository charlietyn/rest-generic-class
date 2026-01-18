# REST Generic Class - Documentación para Desarrolladores

Referencia técnica completa para desarrolladores Laravel.

---

## Tabla de Contenidos

1. [Descripción General de Arquitectura](#1-descripción-general-de-arquitectura)
2. [Instalación y Configuración](#2-instalación-y-configuración)
3. [Operaciones CRUD](#3-operaciones-crud)
4. [Listado y Filtrado](#4-listado-y-filtrado)
5. [Consultas Anidadas](#5-consultas-anidadas)
6. [Selección de Campos](#6-selección-de-campos)
7. [Paginación y Ordenamiento](#7-paginación-y-ordenamiento)
8. [Validación](#8-validación)
9. [Permisos Spatie](#9-permisos-spatie)
10. [Patrones Avanzados](#10-patrones-avanzados)
11. [Referencia API](#11-referencia-api)
12. [Resolución de Problemas](#12-resolución-de-problemas)

---

## 1. Descripción General de Arquitectura

### 1.1 Pila de Componentes
```
┌─────────────────────────────────────┐
│   HTTP Request (JSON)               │
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Middleware: TransformData         │  Valida y transforma entrada
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Controller: RestController        │  Parsea parámetros de consulta
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Service: BaseService              │  Lógica de negocio
│   ├─ process_query()                │
│   ├─ applyOperTree()                │  Filtros + whereHas
│   ├─ relations()                    │  Eager loading
│   └─ pagination()                   │
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Eloquent Query Builder            │
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Base de Datos                     │
└──────────────┬──────────────────────┘
               ↓
┌─────────────────────────────────────┐
│   Respuesta JSON                    │
└─────────────────────────────────────┘
```

### 1.2 Clases Principales

| Clase | Ubicación | Propósito |
|-------|-----------|-----------|
| `BaseModel` | `Core/Models/BaseModel.php` | Modelo Eloquent mejorado con validación |
| `BaseService` | `Core/Services/BaseService.php` | Construcción de consultas y operaciones CRUD |
| `RestController` | `Core/Controllers/RestController.php` | Manejo de peticiones HTTP |
| `BaseFormRequest` | `Core/Requests/BaseFormRequest.php` | Gestión de reglas de validación |
| `HasDynamicFilter` | `Core/Traits/HasDynamicFilter.php` | Motor de filtrado dinámico |

### 1.3 Ejemplo de Flujo de Petición

**Petición:**
```http
GET /api/v1/orders?relations=["user:id,name"]&oper={"user":{"and":["active|=|1"]}}
```

**Flujo:**
1. Ruta → `OrderController@index`
2. Controlador llama a `$this->service->list_all($params)`
3. Servicio llama a `process_query($params, $query)`
4. `process_query()`:
    - Llama a `applyOperTree()` → Agrega `whereHas('user', ...)`
    - Llama a `relations()` → Agrega `with(['user:id,name'])`
5. Ejecuta la consulta
6. Retorna JSON

---

## 2. Instalación y Configuración

### 2.1 Instalación vía Composer
```bash
composer require ronu/rest-generic-class
```

### 2.2 Configuración

Publicar configuración (opcional):
```bash
php artisan vendor:publish --tag=rest-generic-class-config
```

**Archivo:** `config/rest-generic-class.php`
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
        // Profundidad máxima de anidación para consultas anidadas
        'max_depth' => env('REST_MAX_DEPTH', 5),
        
        // Máximo total de condiciones de filtro
        'max_conditions' => env('REST_MAX_CONDITIONS', 100),
        
        // Requiere que Model::RELATIONS esté definido
        'strict_relations' => env('REST_STRICT_RELATIONS', true),
        
        // Operadores permitidos
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

**Variables de Entorno:**
```env
# .env
REST_MAX_DEPTH=5
REST_MAX_CONDITIONS=100
REST_STRICT_RELATIONS=true
LOG_LEVEL=debug
```

### 2.3 Configuración del Modelo

**Configuración Mínima:**
```php
<?php
// app/Models/Order.php

namespace App\Models;

use Ronu\RestGenericClass\Core\Models\BaseModel;

class Order extends BaseModel
{
    protected $fillable = ['status', 'user_id', 'total', 'notes'];
    
    const RELATIONS = ['user', 'items', 'payments'];
    const MODEL = 'order'; // Para operaciones masivas
    
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

**Con Validación:**
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

### 2.4 Configuración del Servicio
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
    
    // Agregar métodos personalizados
    public function getPendingOrders()
    {
        return $this->list_all([
            'oper' => ['and' => ['status|=|pending']],
            'orderby' => [['created_at' => 'asc']]
        ]);
    }
}
```

### 2.5 Configuración del Controlador
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
    
    // Métodos heredados:
    // - index()
    // - show($id)
    // - store(Request)
    // - update(Request, $id)
    // - destroy($id)
    // - updateMultiple(Request)
    // - deleteById(Request)
}
```

### 2.6 Configuración de Rutas
```php
<?php
// routes/api.php

use App\Http\Controllers\Api\OrderController;

Route::prefix('v1')->group(function () {
    // Rutas REST estándar
    Route::apiResource('orders', OrderController::class);
    
    // Operaciones masivas
    Route::post('orders/update-multiple', [OrderController::class, 'updateMultiple']);
    Route::post('orders/delete-by-id', [OrderController::class, 'deleteById']);
});
```

**Rutas Generadas:**

| Método | URI | Acción | Descripción |
|--------|-----|--------|-------------|
| `GET` | `/api/v1/orders` | `index` | Listar todas las órdenes |
| `GET` | `/api/v1/orders/{id}` | `show` | Obtener una orden |
| `POST` | `/api/v1/orders` | `store` | Crear orden(es) |
| `PUT/PATCH` | `/api/v1/orders/{id}` | `update` | Actualizar orden |
| `DELETE` | `/api/v1/orders/{id}` | `destroy` | Eliminar orden |
| `POST` | `/api/v1/orders/update-multiple` | `updateMultiple` | Actualización masiva |
| `POST` | `/api/v1/orders/delete-by-id` | `deleteById` | Eliminación masiva |

---

## 3. Operaciones CRUD

### 3.1 Crear Recurso Individual

**Petición en Insomnia/Postman:**
```http
POST http://localhost:8000/api/v1/orders
Content-Type: application/json

{
  "status": "pending",
  "user_id": 42,
  "total": 250.50,
  "notes": "Primera orden del cliente"
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

**Respuesta (Éxito):**
```json
{
  "success": true,
  "model": {
    "id": 101,
    "status": "pending",
    "user_id": 42,
    "total": 250.5,
    "notes": "Primera orden del cliente",
    "created_at": "2024-01-20T10:30:00.000000Z",
    "updated_at": "2024-01-20T10:30:00.000000Z"
  }
}
```

**Respuesta (Error de Validación):**
```json
{
  "success": false,
  "errors": [
    {
      "user_id": [
        "El campo user id es obligatorio."
      ],
      "total": [
        "El campo total debe ser un número."
      ],
      "model": "App\\Models\\Order"
    }
  ]
}
```

### 3.2 Crear Recursos Masivos

**Petición:**
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

**Nota:** La clave debe coincidir con la constante `Model::MODEL` (en minúsculas, ej: "orders" para el modelo Order).

**Respuesta:**
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

**Fallo Parcial:**

Si un elemento falla la validación, continúa con los demás:
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
      "total": ["El campo total debe ser un número."]
    }
  },
  "error": [
    [
      {"total": ["El campo total debe ser un número."]},
      "App\\Models\\Order"
    ]
  ]
}
```

### 3.3 Leer Recurso Individual

**Petición:**
```http
GET http://localhost:8000/api/v1/orders/101
```

**Respuesta:**
```json
{
  "id": 101,
  "status": "pending",
  "user_id": 42,
  "total": 250.5,
  "notes": "Primera orden",
  "created_at": "2024-01-20T10:30:00.000000Z",
  "updated_at": "2024-01-20T10:30:00.000000Z"
}
```

**Con Relaciones:**
```http
GET http://localhost:8000/api/v1/orders/101?relations=["user","items:id,product_name,quantity"]
```

**Respuesta:**
```json
{
  "id": 101,
  "status": "pending",
  "total": 250.5,
  "user": {
    "id": 42,
    "name": "Juan Pérez",
    "email": "juan@ejemplo.com"
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

### 3.4 Actualizar Recurso Individual

**Petición (PUT):**
```http
PUT http://localhost:8000/api/v1/orders/101
Content-Type: application/json

{
  "status": "processing",
  "notes": "Pago confirmado"
}
```

**Petición (PATCH - mismo comportamiento):**
```http
PATCH http://localhost:8000/api/v1/orders/101
Content-Type: application/json

{
  "status": "completed"
}
```

**Respuesta:**
```json
{
  "success": true,
  "model": {
    "id": 101,
    "status": "completed",
    "user_id": 42,
    "total": 250.5,
    "notes": "Pago confirmado",
    "updated_at": "2024-01-20T14:15:00.000000Z"
  }
}
```

### 3.5 Actualizar Múltiples Recursos

**Petición:**
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

**Respuesta:**
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

### 3.6 Eliminar Recurso Individual

**Petición:**
```http
DELETE http://localhost:8000/api/v1/orders/101
```

**Respuesta:**
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

### 3.7 Eliminar Múltiples Recursos

**Petición (Array):**
```http
POST http://localhost:8000/api/v1/orders/delete-by-id
Content-Type: application/json

[101, 102, 103]
```

**Petición (Objeto):**
```http
POST http://localhost:8000/api/v1/orders/delete-by-id
Content-Type: application/json

{
  "ids": [101, 102, 103]
}
```

**Respuesta:**
```json
{
  "success": true
}
```

---

## 4. Listado y Filtrado

### 4.1 Listado Básico

**Petición:**
```http
GET http://localhost:8000/api/v1/orders
```

**Respuesta:**
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

### 4.2 Selección de Campos

**Petición:**
```http
GET http://localhost:8000/api/v1/orders?select=["id","status","total"]
```

**Respuesta:**
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

### 4.3 Filtros Simples (Sintaxis de Array)

**Escenario:** Obtener órdenes activas con total >= 100

**Petición:**
```http
GET http://localhost:8000/api/v1/orders?oper=["status|=|active","total|>=|100"]
```

**SQL Equivalente:**
```sql
SELECT * FROM orders 
WHERE status = 'active' 
  AND total >= 100
```

**Respuesta:**
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

### 4.4 Filtros Complejos (Sintaxis de Objeto)

**Escenario:** Obtener órdenes que estén pendientes O en procesamiento, Y total > 50

**Petición:**
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

**SQL Equivalente:**
```sql
SELECT * FROM orders 
WHERE total > 50
  AND (status = 'pending' OR status = 'processing')
```

### 4.5 Referencia de Operadores

#### Operadores de Comparación
```json
{
  "oper": {
    "and": [
      "price|=|99.99",          // Igual
      "stock|!=|0",             // Diferente
      "rating|>|4.5",           // Mayor que
      "views|>=|1000",          // Mayor o igual
      "discount|<|50",          // Menor que
      "age|<=|18"               // Menor o igual
    ]
  }
}
```

#### Operadores de Cadena
```json
{
  "oper": {
    "and": [
      "name|like|%laptop%",        // Contiene (sensible a mayúsculas)
      "email|not like|%temp%",     // No contiene
      "code|ilike|%ABC%",          // Contiene (insensible a mayúsculas, PostgreSQL)
      "description|not ilike|%test%"
    ]
  }
}
```

#### Operadores de Lista
```json
{
  "oper": {
    "and": [
      "status|in|pending,active,processing",     // En lista
      "type|not in|deleted,archived,spam"        // No en lista
    ]
  }
}
```

#### Operadores de Rango
```json
{
  "oper": {
    "and": [
      "price|between|10,100",           // Entre 10 y 100 (inclusivo)
      "discount|not between|50,100"     // NO entre 50 y 100
    ]
  }
}
```

#### Operadores Null
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

#### Operadores de Fecha
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

#### Específico de PostgreSQL
```json
{
  "oper": {
    "and": [
      "name|ilikeu|%café%"    // Búsqueda sin acentos (encuentra "cafe", "café", "Café")
    ]
  }
}
```

**Implementación:**
```sql
WHERE unaccent(name) ILIKE unaccent('%café%')
```

### 4.6 Ejemplos de Filtrado Complejo

#### Ejemplo 1: Búsqueda de Productos E-Commerce

**Escenario:** Encontrar productos de electrónica, en stock, precio $50-$500, buenas reseñas

**Petición:**
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

#### Ejemplo 2: Panel de Usuario

**Escenario:** Mostrar mis órdenes activas de los últimos 30 días

**Petición:**
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

#### Ejemplo 3: Reporte de Administrador

**Escenario:** Órdenes de alto valor que necesitan atención

**Petición:**
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

## 5. Consultas Anidadas

### 5.1 Concepto

**Las consultas anidadas** filtran el conjunto de datos raíz basándose en condiciones de modelos relacionados usando `whereHas`.

**Diferencia Clave:**

| Enfoque | SQL | Propósito |
|---------|-----|-----------|
| **Eager Loading** | 2 consultas separadas | Cargar datos relacionados |
| **Consulta Anidada** | Subconsulta EXISTS | Filtrar registros raíz |

**Ejemplo:**
```php
// Eager Loading (carga todas las órdenes, luego filtra usuarios cargados)
Order::with(['user' => fn($q) => $q->where('active', 1)])->get();

// Consulta Anidada (solo devuelve órdenes de usuarios activos)
Order::whereHas('user', fn($q) => $q->where('active', 1))->get();
```

### 5.2 Consulta Anidada de Un Nivel

**Escenario:** Obtener órdenes de usuarios verificados

**Petición:**
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

**SQL Generado:**
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

**Equivalente en Eloquent:**
```php
Order::where('status', 'active')
    ->whereHas('user', fn($q) => $q
        ->whereNotNull('email_verified_at')
        ->where('active', 1)
    )
    ->get();
```

### 5.3 Consulta Anidada Multi-Nivel

**Escenario:** Obtener órdenes de usuarios que tienen el rol admin

**Petición:**
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

**SQL Generado:**
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

**Equivalente en Eloquent:**
```php
Order::whereHas('user', fn($q) => $q
    ->where('active', 1)
    ->whereHas('roles', fn($rq) => $rq
        ->where('name', 'admin')
    )
)->get();
```

### 5.4 Notación de Punto (Abreviada)

**Escenario:** Lo mismo que arriba, usando notación de punto

**Petición:**
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

**Equivalente a la consulta multi-nivel anterior**

### 5.5 Múltiples Relaciones

**Escenario:** Órdenes de usuarios verificados con pagos completados

**Petición:**
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

**Eloquent Generado:**
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

### 5.6 Ejemplo Complejo del Mundo Real

**Escenario:** Consulta analítica de e-commerce

**Requisitos:**
- Productos de categorías activas
- De tiendas con calificación > 4.0
- Que tengan reseñas con calificación promedio >= 4.5
- En stock
- Precio entre $50-$1000

**Petición:**
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

**Esto filtra productos en 3 niveles:**
1. Nivel de producto: activo, en stock, rango de precio
2. Categoría → Tienda: categoría activa de tiendas verificadas con alta calificación
3. Reseñas: productos con buenas reseñas

---

## 6. Selección de Campos

### 6.1 Selección de Campos del Modelo Principal

**Petición:**
```http
GET http://localhost:8000/api/v1/orders?select=["id","status","total","created_at"]
```

**Respuesta:**
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

### 6.2 Selección de Campos de Relación

**Sintaxis:** `"relation:field1,field2,field3"`

**Petición:**
```http
GET http://localhost:8000/api/v1/orders?relations=["user:id,name,email"]
```

**Respuesta:**
```json
{
  "data": [
    {
      "id": 1,
      "status": "pending",
      "total": 100.0,
      "user": {
        "id": 42,
        "name": "Juan Pérez",
        "email": "juan@ejemplo.com"
      }
    }
  ]
}
```

**Nota:** Las claves foráneas (`user_id`) se incluyen automáticamente cuando son necesarias.

### 6.3 Múltiples Relaciones con Selección de Campos

**Petición:**
```http
GET http://localhost:8000/api/v1/orders?relations=["user:id,name","items:id,product_name,quantity","payments:id,amount,method"]
```

**Respuesta:**
```json
{
  "data": [
    {
      "id": 1,
      "status": "pending",
      "total": 350.0,
      "user": {
        "id": 42,
        "name": "Juan Pérez"
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

### 6.4 Selección de Campos en Relación Anidada

**Petición:**
```http
GET http://localhost:8000/api/v1/orders?relations=["user.roles:id,name","items.product:id,name,price"]
```

**Respuesta:**
```json
{
  "data": [
    {
      "id": 1,
      "user": {
        "id": 42,
        "name": "Juan Pérez",
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

### 6.5 Combinado: Consultas Anidadas + Selección de Campos

**Escenario:** Obtener órdenes de usuarios admin, solo mostrar id/nombre del usuario

**Petición:**
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

**Comportamiento:**

1. **Filtrado Raíz:** Solo órdenes donde `user.roles.name = 'admin'` (vía `whereHas`)
2. **Selección de Campos:** El objeto usuario contiene solo `id` y `name`

**Respuesta:**
```json
{
  "data": [
    {
      "id": 15,
      "status": "active",
      "total": 500.0,
      "user": {
        "id": 5,
        "name": "Usuario Admin"
      }
    }
  ]
}
```

### 6.6 Inclusión Automática de Claves Foráneas

**Petición:**
```http
GET http://localhost:8000/api/v1/orders?relations=["items:product_name,quantity"]
```

**Lo que Laravel Hace:**
```php
// Tu petición
'items:product_name,quantity'

// Laravel incluye automáticamente
'items:id,order_id,product_name,quantity'
//      ^^  ^^^^^^^^ (claves foráneas agregadas)
```

**Respuesta:**
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

## 7. Paginación y Ordenamiento

### 7.1 Paginación Estándar

**Petición:**
```http
GET http://localhost:8000/api/v1/orders?pagination={"page":2,"pageSize":20}
```

**Respuesta:**
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
      "label": "&laquo; Anterior",
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

### 7.2 Paginación por Cursor (Scroll Infinito)

**Primera Petición:**
```http
GET http://localhost:8000/api/v1/orders?pagination={"infinity":true,"pageSize":20}
```

**Primera Respuesta:**
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

**Petición de Página Siguiente:**
```http
GET http://localhost:8000/api/v1/orders?pagination={"infinity":true,"pageSize":20,"cursor":"eyJpZCI6MjAsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0"}
```

**Ventajas de la Paginación por Cursor:**

- ✅ No hay registros perdidos/duplicados cuando los datos cambian
- ✅ Mejor rendimiento para grandes conjuntos de datos
- ✅ Ideal para interfaces de scroll infinito

### 7.3 Ordenamiento (Una Columna)

**Petición:**
```http
GET http://localhost:8000/api/v1/orders?orderby=[{"created_at":"desc"}]
```

**SQL Generado:**
```sql
SELECT * FROM orders ORDER BY created_at DESC
```

### 7.4 Ordenamiento (Múltiples Columnas)

**Petición:**
```http
GET http://localhost:8000/api/v1/orders?orderby=[{"status":"asc"},{"total":"desc"},{"created_at":"desc"}]
```

**SQL Generado:**
```sql
SELECT * FROM orders 
ORDER BY status ASC, total DESC, created_at DESC
```

### 7.5 Ejemplo de Consulta Completa

**Escenario:** Panel mostrando órdenes recientes de alto valor

**Petición:**
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

**Eloquent Generado:**
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

## 8. Validación

### 8.1 Validación Basada en Modelo

**Definir en el Modelo:**
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

**La validación se dispara automáticamente** en:
- `POST /orders` (escenario: `create` o `bulk_create`)
- `PUT /orders/{id}` (escenario: `update`)
- `POST /orders/update-multiple` (escenario: `bulk_update`)

### 8.2 Validación de Request Personalizada

**Crear Form Request:**
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

**Crear Archivo de Reglas:**
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
        // Igual que create
    ],
    
    'bulk_update' => [
        'id' => 'required|exists:orders,id',
        'status' => 'sometimes|in:pending,processing,completed,cancelled',
    ],
];
```

**Usar en el Controlador:**
```php
<?php
// app/Http/Controllers/Api/OrderController.php

use App\Http\Requests\OrderRequest;

class OrderController extends RestController
{
    public function store(OrderRequest $request)
    {
        // La validación ya pasó
        return parent::store($request);
    }
    
    public function update(OrderRequest $request, $id)
    {
        return parent::update($request, $id);
    }
}
```

### 8.3 Respuesta de Errores de Validación

**Petición (Inválida):**
```http
POST http://localhost:8000/api/v1/orders
Content-Type: application/json

{
  "status": "invalid_status",
  "user_id": "not_a_number",
  "total": -50
}
```

**Respuesta:**
```json
{
  "message": "Los datos proporcionados no son válidos.",
  "errors": {
    "status": [
      "El estado seleccionado es inválido."
    ],
    "user_id": [
      "El campo user id debe ser un número entero.",
      "El user id seleccionado es inválido."
    ],
    "total": [
      "El total debe ser al menos 0."
    ]
  }
}
```

### 8.4 Reglas de Validación Personalizadas
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
        return "No se puede transicionar de {$this->currentStatus} a :input.";
    }
}
```

**Usar en el Controlador:**
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

## 9. Permisos Spatie

### 9.1 Configuración

**Instalar Spatie:**
```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

**Configurar Modelo:**
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

### 9.2 Filtrado por Permisos

**Obtener usuarios con permisos específicos:**
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

**Obtener usuarios con rol admin:**
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

### 9.3 Autorización con Middleware

**Configurar Middleware:**
```php
<?php
// app/Http/Kernel.php

protected $routeMiddleware = [
    'permission' => \Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize::class,
];
```

**Aplicar a Rutas:**
```php
<?php
// routes/api.php

Route::middleware(['auth:api', 'permission'])->group(function () {
    Route::apiResource('orders', OrderController::class);
});
```

**Permisos específicos por ruta:**
```php
Route::get('orders', [OrderController::class, 'index'])
    ->middleware('permission:orders.view');
    
Route::post('orders', [OrderController::class, 'store'])
    ->middleware('permission:orders.create');
```

### 9.4 Comando de Actualización de Permisos

**Generar permisos desde rutas:**
```bash
php artisan permission:refresh --guard=api
```

**Esto crea permisos como:**
- `orders.index`
- `orders.show`
- `orders.create`
- `orders.update`
- `orders.delete`

---

## 10. Patrones Avanzados

### 10.1 Métodos Personalizados en el Servicio
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
        ], false); // false = retornar array, no JSON
    }
}
```

### 10.2 Soft Deletes

**Habilitar en el Modelo:**
```php
<?php
// app/Models/Order.php

use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends BaseModel
{
    use SoftDeletes;
}
```

**Parámetros de Consulta:**
```http
# Incluir eliminados suavemente
GET /api/v1/orders?soft_delete=true

# Solo eliminados suavemente
GET /api/v1/orders?soft_delete=only

# Excluir eliminados suavemente (por defecto)
GET /api/v1/orders
```

### 10.3 Optimización de Rendimiento

**Agregar Índices de Base de Datos:**
```php
<?php
// database/migrations/xxxx_add_indexes_to_orders.php

public function up()
{
    Schema::table('orders', function (Blueprint $table) {
        $table->index('user_id');
        $table->index('status');
        $table->index('created_at');
        $table->index(['status', 'created_at']); // Índice compuesto
    });
}
```

**Monitorear Consultas Lentas:**
```php
<?php
// app/Providers/AppServiceProvider.php

use Illuminate\Support\Facades\DB;

public function boot()
{
    if (config('app.debug')) {
        DB::listen(function ($query) {
            if ($query->time > 1000) { // > 1 segundo
                Log::warning('Consulta lenta detectada', [
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

## 11. Referencia API

### 11.1 Parámetros de Consulta

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|----------|-------------|
| `select` | `string\|array` | No | Campos a retornar del modelo principal |
| `relations` | `array` | No | Relaciones a cargar (soporta selección de campos) |
| `oper` | `array\|object` | No | Condiciones de filtro (soporta consultas anidadas) |
| `orderby` | `array` | No | Orden de clasificación `[{"field":"asc\|desc"}]` |
| `pagination` | `object` | No | Parámetros de paginación `{"page":1,"pageSize":20}` |
| `_nested` | `boolean` | No | Aplicar filtros a relaciones cargadas |
| `soft_delete` | `string` | No | `true\|only` para registros eliminados suavemente |

### 11.2 Formatos de Respuesta

#### Éxito (Lista)
```json
{
  "data": [...]
}
```

#### Éxito (Paginado)
```json
{
  "current_page": 1,
  "data": [...],
  "per_page": 20,
  "total": 100,
  ...
}
```

#### Éxito (Crear/Actualizar)
```json
{
  "success": true,
  "model": {...}
}
```

#### Éxito (Eliminar)
```json
{
  "success": true,
  "model": {...}
}
```

#### Error (Validación)
```json
{
  "message": "Los datos proporcionados no son válidos.",
  "errors": {
    "field": ["Mensaje de error"]
  }
}
```

#### Error (No Encontrado)
```json
{
  "message": "No se encontraron resultados para el modelo [App\\Models\\Order] 999"
}
```

#### Error (Prohibido)
```json
{
  "message": "Prohibido: orders.edit"
}
```

#### Error (Solicitud Incorrecta)
```json
{
  "message": "La relación 'invalidRelation' no está permitida. Permitidas: user, items"
}
```

---

## 12. Resolución de Problemas

### 12.1 Problemas Comunes

#### Problema: "La relación 'X' no está permitida"

**Causa:** Relación no está en la lista de permitidas `Model::RELATIONS`.

**Solución:**
```php
class Order extends BaseModel
{
    const RELATIONS = ['user', 'items', 'payments']; // Agregar relación faltante
}
```

#### Problema: Los filtros anidados no funcionan

**Causa:** La relación no existe o hay un error tipográfico.

**Depuración:**
```php
// Probar que la relación existe
$order = Order::first();
dd($order->user); // Debería cargar el modelo User

// Verificar que el nombre de la relación coincida con el método
public function user() // Debe coincidir con el nombre de la relación en la consulta
{
    return $this->belongsTo(User::class);
}
```

#### Problema: Campos faltantes en la respuesta

**Causa:** Selección de campos sin claves foráneas.

**Solución:**
```json
{
  "relations": ["user:id,name"]  // ✅ Siempre incluir 'id'
}
```

Laravel agrega automáticamente las claves foráneas, pero ser explícito es mejor.

#### Problema: "Profundidad máxima de anidación excedida"

**Causa:** Consulta demasiado anidada.

**Solución:**
```php
// config/rest-generic-class.php
'filtering' => [
    'max_depth' => 6,  // Aumentar límite
]
```

O simplificar la estructura de la consulta.

#### Problema: Consultas lentas

**Soluciones:**

1. Agregar índices de base de datos
2. Usar selección de campos para reducir datos
3. Monitorear con `DB::listen()`
4. Usar eager loading sabiamente
```php
// Malo: Problema N+1
foreach ($orders as $order) {
    echo $order->user->name; // Consulta por orden
}

// Bueno: Eager load
$orders = Order::with('user')->get();
foreach ($orders as $order) {
    echo $order->user->name; // Sin consultas adicionales
}
```

#### Problema: Error de columna ambigua en PostgreSQL

**Causa:** Cuando usas relaciones anidadas con el mismo nombre de campo (ej: `id`).

**Solución:** La biblioteca ahora prefija automáticamente las columnas con el nombre de la tabla. Si encuentras este error, asegúrate de tener la versión más reciente.

**Ejemplo del problema:**
```json
{
  "oper": {
    "user.roles": {
      "and": ["id|=|2"]  // ¿roles.id o users.id?
    }
  }
}
```

**Solución automática:** La biblioteca ahora prefija correctamente como `roles.id`.

---

## Apéndice A: Ejemplo Completo de Funcionamiento

**Escenario:** API de Blog con Posts, Autores, Categorías, Comentarios

### Modelos
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

### Servicio
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

### Controlador
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

### Rutas
```php
<?php
// routes/api.php

Route::prefix('v1')->group(function () {
    Route::apiResource('posts', PostController::class);
});
```

### Ejemplos de Peticiones

**1. Obtener posts publicados de autores verificados:**
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

**2. Obtener posts con buenos comentarios:**
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

**3. Obtener posts de categoría tecnología por autores admin:**
```http
POST http://localhost:8000/api/v1/posts/_search
Content-Type: application/json

{
  "oper": {
    "category": {
      "and": ["name|=|Tecnología"]
    },
    "author.roles": {
      "and": ["name|=|admin"]
    }
  }
}
```

---

## Soporte

- 📧 Email: charlietyn@gmail.com
- 🐛 Incidencias: [GitHub Issues](https://github.com/ronu/rest-generic-class/issues)
- 💬 Discusiones: [GitHub Discussions](https://github.com/ronu/rest-generic-class/discussions)

---

**Última Actualización:** Enero 2024  
**Versión:** 1.8.0  
**Compatibilidad Laravel:** 12.x