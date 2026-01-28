# REST Generic Class: Guía de Consultas (Queries)

## Introducción

Esta documentación describe cómo realizar consultas a la API REST usando la biblioteca **REST Generic Class** para Laravel 12. Cubre los endpoints de **listado** (`GET /resource`) y **show** (`GET /resource/{id}`), incluyendo filtrado, relaciones, ordenamiento y paginación.

---

## Tabla de Contenidos

1. [Diferencias entre LISTADO y SHOW](#diferencias-entre-listado-y-show)
2. [Parámetros Soportados](#parámetros-soportados)
3. [Escenarios de Uso](#escenarios-de-uso)
   - [Listado básico](#1-listado-básico-sin-parámetros)
   - [Show básico](#2-show-básico-por-id)
   - [Select de campos](#3-listado-con-select)
   - [Relaciones simples](#4-listado-con-relaciones-simples)
   - [Relaciones con campos específicos](#5-listado-con-relaciones-y-selección-de-campos)
   - [Filtros simples](#6-listado-con-filtros-simples-oper-como-array)
   - [Filtros complejos AND/OR](#7-listado-con-filtros-complejos-andor)
   - [Filtros anidados por relaciones](#8-filtros-anidados-por-relaciones)
   - [Order By](#9-order-by-simple-y-múltiple)
   - [Paginación estándar](#10-paginación-estándar)
   - [Paginación infinita (cursor)](#11-paginación-infinita-cursor)
4. [Manejo de Errores](#manejo-de-errores)
   - [Relación no permitida](#12-error-relación-no-permitida)
   - [Límites de filtros](#13-límites-de-filtros)
5. [Operadores Disponibles](#operadores-disponibles)
6. [Buenas Prácticas](#buenas-prácticas)

---

## Diferencias entre LISTADO y SHOW

| Aspecto | LISTADO (`GET /resource`) | SHOW (`GET /resource/{id}`) |
|---------|---------------------------|----------------------------|
| **Propósito** | Obtener múltiples registros | Obtener un registro específico |
| **Identificador** | No requerido (usa filtros) | Requerido en URL |
| **Paginación** | Soportada | No aplica |
| **Filtros (oper)** | Soportados | No aplica* |
| **Select** | Soportado | Soportado |
| **Relations** | Soportadas | Soportadas |
| **Order By** | Soportado | No aplica |
| **Respuesta** | Array con metadata | Objeto único |

> *El endpoint SHOW busca directamente por ID; para filtrar y obtener un solo registro, usa el endpoint `getOne` si está habilitado.

### Métodos del Controlador

```php
// LISTADO - Retorna colección paginada o array
public function index(Request $request): LengthAwarePaginator|array

// SHOW - Retorna un modelo o 404
public function show(Request $request, $id): mixed
```

---

## Parámetros Soportados

| Parámetro | Tipo | LISTADO | SHOW | Descripción |
|-----------|------|:-------:|:----:|-------------|
| `select` | array | ✅ | ✅ | Campos a retornar del modelo principal |
| `relations` | array | ✅ | ✅ | Relaciones a cargar (con campos opcionales) |
| `oper` | array/object | ✅ | ❌ | Filtros de búsqueda |
| `orderby` | array | ✅ | ❌ | Ordenamiento de resultados |
| `pagination` | object | ✅ | ❌ | Configuración de paginación |
| `_nested` | boolean | ✅ | ✅ | Aplica filtros oper a relaciones cargadas |

---

## Escenarios de Uso

Para los ejemplos usaremos un modelo `Order` con las siguientes relaciones definidas:

```php
class Order extends BaseModel
{
    const RELATIONS = ['user', 'items', 'payments', 'user.roles'];

    public function user(): BelongsTo { /* ... */ }
    public function items(): HasMany { /* ... */ }
    public function payments(): HasMany { /* ... */ }
}
```

---

### 1. Listado básico sin parámetros

Obtiene todos los registros sin filtros ni paginación.

**Request:**
```http
GET /api/orders HTTP/1.1
Host: api.example.com
Accept: application/json
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 10,
      "status": "pending",
      "total": 150.00,
      "created_at": "2026-01-15T10:30:00.000000Z",
      "updated_at": "2026-01-15T10:30:00.000000Z"
    },
    {
      "id": 2,
      "user_id": 12,
      "status": "completed",
      "total": 320.50,
      "created_at": "2026-01-16T14:20:00.000000Z",
      "updated_at": "2026-01-17T09:15:00.000000Z"
    }
  ]
}
```

---

### 2. Show básico por ID

Obtiene un registro específico por su identificador.

**Request:**
```http
GET /api/orders/1 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**Response (200 OK):**
```json
{
  "id": 1,
  "user_id": 10,
  "status": "pending",
  "total": 150.00,
  "created_at": "2026-01-15T10:30:00.000000Z",
  "updated_at": "2026-01-15T10:30:00.000000Z"
}
```

**Response (404 Not Found):**
```json
{
  "message": "No query results for model [App\\Models\\Order] 999"
}
```

---

### 3. Listado con select

Retorna únicamente los campos especificados del modelo principal.

**Request:**
```http
GET /api/orders?select=["id","status","total"] HTTP/1.1
Host: api.example.com
Accept: application/json
```

**Request alternativo (URL encoded):**
```http
GET /api/orders?select=%5B%22id%22%2C%22status%22%2C%22total%22%5D HTTP/1.1
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "status": "pending",
      "total": 150.00
    },
    {
      "id": 2,
      "status": "completed",
      "total": 320.50
    }
  ]
}
```

> **Nota:** Si no se especifica `select`, se retornan todas las columnas (`*`).

---

### 4. Listado con relaciones simples

Carga relaciones usando eager loading. Las relaciones deben estar definidas en `const RELATIONS` del modelo.

**Request:**
```http
GET /api/orders?relations=["user","items"] HTTP/1.1
Host: api.example.com
Accept: application/json
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 10,
      "status": "pending",
      "total": 150.00,
      "created_at": "2026-01-15T10:30:00.000000Z",
      "updated_at": "2026-01-15T10:30:00.000000Z",
      "user": {
        "id": 10,
        "name": "Juan Pérez",
        "email": "juan@example.com",
        "created_at": "2025-06-01T00:00:00.000000Z",
        "updated_at": "2025-06-01T00:00:00.000000Z"
      },
      "items": [
        {
          "id": 100,
          "order_id": 1,
          "product_name": "Laptop",
          "quantity": 1,
          "price": 150.00
        }
      ]
    }
  ]
}
```

**Cargar todas las relaciones permitidas:**
```http
GET /api/orders?relations=["all"] HTTP/1.1
```

---

### 5. Listado con relaciones y selección de campos

Especifica qué campos cargar de cada relación usando la sintaxis `relacion:campo1,campo2`.

**Request:**
```http
GET /api/orders?relations=["user:id,name,email","items:id,product_name,quantity"] HTTP/1.1
Host: api.example.com
Accept: application/json
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 10,
      "status": "pending",
      "total": 150.00,
      "created_at": "2026-01-15T10:30:00.000000Z",
      "updated_at": "2026-01-15T10:30:00.000000Z",
      "user": {
        "id": 10,
        "name": "Juan Pérez",
        "email": "juan@example.com"
      },
      "items": [
        {
          "id": 100,
          "product_name": "Laptop",
          "quantity": 1
        }
      ]
    }
  ]
}
```

**Relaciones anidadas con campos:**
```http
GET /api/orders?relations=["user.roles:id,name"] HTTP/1.1
```

> **Nota:** Las foreign keys necesarias para las relaciones se incluyen automáticamente aunque no se especifiquen.

---

### 6. Listado con filtros simples (oper como array)

Filtra registros usando condiciones en formato `campo|operador|valor`. Cuando `oper` es un array, todas las condiciones se unen con AND.

**Formato de condición:**
```
campo|operador|valor
```

**Request:**
```http
GET /api/orders?oper=["status|=|pending","total|>=|100"] HTTP/1.1
Host: api.example.com
Accept: application/json
```

**SQL generado:**
```sql
SELECT * FROM orders
WHERE status = 'pending' AND total >= 100
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 10,
      "status": "pending",
      "total": 150.00,
      "created_at": "2026-01-15T10:30:00.000000Z",
      "updated_at": "2026-01-15T10:30:00.000000Z"
    }
  ]
}
```

**Ejemplos de filtros comunes:**

| Condición | Descripción |
|-----------|-------------|
| `status|=|active` | Igual a "active" |
| `total|>|100` | Mayor que 100 |
| `total|between|50,200` | Entre 50 y 200 |
| `user_id|in|1,2,3` | En la lista [1, 2, 3] |
| `deleted_at|null|` | Es NULL |
| `email|like|%@gmail.com` | Contiene "@gmail.com" |

---

### 7. Listado con filtros complejos (AND/OR)

Usa un objeto con claves `and` y/o `or` para combinar condiciones con lógica explícita.

**Request (Body JSON para mayor claridad):**
```http
GET /api/orders HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
  "oper": {
    "and": [
      "status|=|pending",
      "total|>|50"
    ],
    "or": [
      "payment_method|=|credit",
      "payment_method|=|debit"
    ]
  }
}
```

**Request (Query String):**
```http
GET /api/orders?oper={"and":["status|=|pending","total|>|50"],"or":["payment_method|=|credit","payment_method|=|debit"]} HTTP/1.1
```

**SQL generado:**
```sql
SELECT * FROM orders
WHERE (status = 'pending' AND total > 50)
  AND (payment_method = 'credit' OR payment_method = 'debit')
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 10,
      "status": "pending",
      "total": 150.00,
      "payment_method": "credit",
      "created_at": "2026-01-15T10:30:00.000000Z",
      "updated_at": "2026-01-15T10:30:00.000000Z"
    }
  ]
}
```

**Filtros anidados (AND dentro de OR):**
```json
{
  "oper": {
    "or": [
      {
        "and": ["status|=|pending", "priority|=|high"]
      },
      {
        "and": ["status|=|processing", "total|>|1000"]
      }
    ]
  }
}
```

**SQL generado:**
```sql
SELECT * FROM orders
WHERE (
    (status = 'pending' AND priority = 'high')
    OR
    (status = 'processing' AND total > 1000)
)
```

---

### 8. Filtros anidados por relaciones

Filtra el modelo principal basándose en condiciones de sus relaciones usando `whereHas`. Las claves del objeto representan nombres de relaciones.

**Request:**
```http
GET /api/orders HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
  "oper": {
    "and": ["status|=|active"],
    "user": {
      "and": ["email_verified_at|not null|", "active|=|1"]
    },
    "items": {
      "and": ["quantity|>|0"]
    }
  }
}
```

**SQL generado:**
```sql
SELECT orders.* FROM orders
WHERE status = 'active'
  AND EXISTS (
    SELECT * FROM users
    WHERE orders.user_id = users.id
      AND users.email_verified_at IS NOT NULL
      AND users.active = 1
  )
  AND EXISTS (
    SELECT * FROM order_items
    WHERE order_items.order_id = orders.id
      AND order_items.quantity > 0
  )
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 10,
      "status": "active",
      "total": 150.00,
      "created_at": "2026-01-15T10:30:00.000000Z",
      "updated_at": "2026-01-15T10:30:00.000000Z"
    }
  ]
}
```

**Relaciones anidadas con notación de puntos:**

Filtra órdenes donde el usuario tenga un rol específico:

```http
GET /api/orders HTTP/1.1
Host: api.example.com
Content-Type: application/json

{
  "oper": {
    "user.roles": {
      "and": ["name|=|admin"]
    }
  }
}
```

**SQL generado:**
```sql
SELECT orders.* FROM orders
WHERE EXISTS (
  SELECT * FROM users
  WHERE orders.user_id = users.id
    AND EXISTS (
      SELECT * FROM roles
      INNER JOIN role_user ON roles.id = role_user.role_id
      WHERE role_user.user_id = users.id
        AND roles.name = 'admin'
    )
)
```

> **Importante:** `whereHas` filtra qué registros del modelo principal se retornan. Para filtrar también los datos de las relaciones cargadas, usa el parámetro `_nested=true`.

---

### 9. Order By simple y múltiple

Ordena los resultados por uno o más campos.

**Order By simple:**
```http
GET /api/orders?orderby=[{"created_at":"desc"}] HTTP/1.1
Host: api.example.com
Accept: application/json
```

**Order By múltiple:**
```http
GET /api/orders?orderby=[{"status":"asc"},{"total":"desc"},{"created_at":"desc"}] HTTP/1.1
Host: api.example.com
Accept: application/json
```

**SQL generado:**
```sql
SELECT * FROM orders
ORDER BY status ASC, total DESC, created_at DESC
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 3,
      "status": "active",
      "total": 500.00,
      "created_at": "2026-01-20T08:00:00.000000Z"
    },
    {
      "id": 1,
      "status": "active",
      "total": 150.00,
      "created_at": "2026-01-15T10:30:00.000000Z"
    },
    {
      "id": 2,
      "status": "pending",
      "total": 320.50,
      "created_at": "2026-01-16T14:20:00.000000Z"
    }
  ]
}
```

---

### 10. Paginación estándar

Usa paginación offset-based con información completa de páginas.

**Request:**
```http
GET /api/orders?pagination={"page":2,"pageSize":10} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**Response (200 OK):**
```json
{
  "current_page": 2,
  "data": [
    {
      "id": 11,
      "status": "pending",
      "total": 89.99
    },
    {
      "id": 12,
      "status": "completed",
      "total": 245.00
    }
  ],
  "first_page_url": "http://api.example.com/api/orders?page=1",
  "from": 11,
  "last_page": 10,
  "last_page_url": "http://api.example.com/api/orders?page=10",
  "links": [
    {
      "url": "http://api.example.com/api/orders?page=1",
      "label": "&laquo; Previous",
      "active": false
    },
    {
      "url": "http://api.example.com/api/orders?page=1",
      "label": "1",
      "active": false
    },
    {
      "url": "http://api.example.com/api/orders?page=2",
      "label": "2",
      "active": true
    },
    {
      "url": "http://api.example.com/api/orders?page=3",
      "label": "3",
      "active": false
    },
    {
      "url": "http://api.example.com/api/orders?page=3",
      "label": "Next &raquo;",
      "active": false
    }
  ],
  "next_page_url": "http://api.example.com/api/orders?page=3",
  "path": "http://api.example.com/api/orders",
  "per_page": 10,
  "prev_page_url": "http://api.example.com/api/orders?page=1",
  "to": 20,
  "total": 100
}
```

**Parámetros de paginación:**

| Parámetro | Tipo | Default | Descripción |
|-----------|------|---------|-------------|
| `page` | int | 1 | Número de página actual |
| `pageSize` | int | Model::$perPage | Registros por página |

---

### 11. Paginación infinita (cursor)

Usa paginación cursor-based, ideal para infinite scroll. Más eficiente que offset cuando los datos cambian frecuentemente.

**Primera petición:**
```http
GET /api/orders?pagination={"infinity":true,"pageSize":20} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 1,
      "status": "pending",
      "total": 150.00
    },
    {
      "id": 2,
      "status": "completed",
      "total": 320.50
    }
  ],
  "next_cursor": "eyJpZCI6MjAsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0",
  "has_more": true
}
```

**Peticiones siguientes (usando el cursor):**
```http
GET /api/orders?pagination={"infinity":true,"pageSize":20,"cursor":"eyJpZCI6MjAsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0"} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**Response última página:**
```json
{
  "data": [
    {
      "id": 99,
      "status": "completed",
      "total": 180.00
    },
    {
      "id": 100,
      "status": "pending",
      "total": 95.00
    }
  ],
  "next_cursor": null,
  "has_more": false
}
```

**Comparación con paginación estándar:**

| Aspecto | Estándar (offset) | Cursor (infinity) |
|---------|-------------------|-------------------|
| Total conocido | ✅ Sí | ❌ No |
| Performance grandes datasets | ⚠️ Degrada | ✅ Constante |
| Datos nuevos durante paginación | ⚠️ Posibles duplicados | ✅ Sin duplicados |
| Navegación directa a página X | ✅ Sí | ❌ No |
| Caso de uso ideal | Tablas con paginación | Infinite scroll |

---

## Manejo de Errores

### 12. Error: Relación no permitida

Ocurre cuando se solicita una relación que no está en `const RELATIONS` del modelo.

**Request:**
```http
GET /api/orders?relations=["audit_logs"] HTTP/1.1
Host: api.example.com
Accept: application/json
```

**Response (400 Bad Request):**
```json
{
  "message": "Relation 'audit_logs' is not allowed. Allowed: user, items, payments, user.roles"
}
```

**En filtros anidados:**
```http
GET /api/orders HTTP/1.1
Content-Type: application/json

{
  "oper": {
    "audit_logs": {
      "and": ["action|=|update"]
    }
  }
}
```

**Response (400 Bad Request):**
```json
{
  "message": "Relation 'audit_logs' is not allowed for filtering on model App\\Models\\Order. Allowed relations: user, items, payments, user.roles"
}
```

**Solución:** Agrega la relación al constant `RELATIONS` del modelo:

```php
class Order extends BaseModel
{
    const RELATIONS = ['user', 'items', 'payments', 'user.roles', 'audit_logs'];
}
```

---

### 13. Límites de filtros

La biblioteca implementa límites de seguridad para prevenir consultas excesivamente complejas.

#### Max Depth (profundidad máxima)

Limita la anidación de relaciones en filtros. Default: 5 niveles.

**Request que excede el límite:**
```http
GET /api/orders HTTP/1.1
Content-Type: application/json

{
  "oper": {
    "user": {
      "roles": {
        "permissions": {
          "modules": {
            "submodules": {
              "features": {
                "and": ["active|=|1"]
              }
            }
          }
        }
      }
    }
  }
}
```

**Response (400 Bad Request):**
```json
{
  "message": "Maximum nesting depth (5) exceeded."
}
```

#### Max Conditions (máximo de condiciones)

Limita el número total de condiciones en una consulta. Default: 100.

**Request que excede el límite:**
```http
GET /api/orders HTTP/1.1
Content-Type: application/json

{
  "oper": {
    "and": [
      "field1|=|value1",
      "field2|=|value2",
      ... (más de 100 condiciones)
    ]
  }
}
```

**Response (400 Bad Request):**
```json
{
  "message": "Maximum conditions (100) exceeded."
}
```

**Configuración de límites:**

```php
// config/rest-generic-class.php
return [
    'filtering' => [
        'max_depth' => 5,        // Máxima profundidad de anidación
        'max_conditions' => 100, // Máximo de condiciones totales
    ],
];
```

---

## Operadores Disponibles

| Operador | Ejemplo | Descripción |
|----------|---------|-------------|
| `=` | `status|=|active` | Igual a |
| `!=`, `<>` | `status|!=|deleted` | Diferente de |
| `<` | `total|<|100` | Menor que |
| `>` | `total|>|100` | Mayor que |
| `<=` | `total|<=|100` | Menor o igual |
| `>=` | `total|>=|100` | Mayor o igual |
| `like` | `name|like|%john%` | Contiene (case sensitive) |
| `not like` | `name|not like|%test%` | No contiene |
| `ilike` | `name|ilike|%john%` | Contiene (case insensitive) |
| `not ilike` | `name|not ilike|%test%` | No contiene (case insensitive) |
| `in` | `status|in|active,pending` | En lista de valores |
| `not in` | `status|not in|deleted,archived` | No en lista |
| `between` | `total|between|100,500` | Entre dos valores |
| `not between` | `total|not between|0,10` | Fuera del rango |
| `null` | `deleted_at|null|` | Es NULL |
| `not null` | `email_verified_at|not null|` | No es NULL |
| `date` | `created_at|date|2026-01-15` | Igual a fecha (ignora hora) |
| `not date` | `created_at|not date|2026-01-15` | Diferente de fecha |
| `exists` | `items|exists|` | Relación existe |
| `not exists` | `items|not exists|` | Relación no existe |
| `regexp` | `email|regexp|^[a-z]+@` | Coincide con regex |
| `not regexp` | `email|not regexp|@temp\.` | No coincide con regex |

**Valores especiales:**
- `true` → boolean true
- `false` → boolean false
- `null` → NULL
- `1,2,3` → array [1, 2, 3]

---

## Buenas Prácticas

### 1. Define siempre RELATIONS en tus modelos

```php
class Order extends BaseModel
{
    // Whitelist explícita de relaciones permitidas
    const RELATIONS = ['user', 'items', 'payments'];
}
```

Esto previene acceso no autorizado a relaciones sensibles.

### 2. Usa select para optimizar

En lugar de cargar todas las columnas:
```http
GET /api/orders
```

Especifica solo lo necesario:
```http
GET /api/orders?select=["id","status","total"]
```

### 3. Limita campos en relaciones

```http
GET /api/orders?relations=["user:id,name","items:id,product_name,quantity"]
```

### 4. Usa paginación siempre en listados

Para datasets grandes, siempre pagina:
```http
GET /api/orders?pagination={"page":1,"pageSize":50}
```

### 5. Prefiere cursor para infinite scroll

```http
GET /api/orders?pagination={"infinity":true,"pageSize":20}
```

### 6. Combina filtros eficientemente

En lugar de múltiples requests:
```http
GET /api/orders?oper={"and":["status|=|active","total|>|100"],"user":{"and":["verified|=|1"]}}
```

### 7. Evita anidación excesiva

Mantén la profundidad de filtros por debajo del límite configurado (default: 5).

### 8. Usa orderby con índices

Ordena por columnas indexadas para mejor performance:
```http
GET /api/orders?orderby=[{"created_at":"desc"}]
```

---

## Ejemplo Completo

Request combinando múltiples funcionalidades:

```http
GET /api/orders HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
  "select": ["id", "status", "total", "created_at"],
  "relations": ["user:id,name,email", "items:id,product_name,quantity"],
  "oper": {
    "and": ["status|in|pending,processing", "total|>=|100"],
    "user": {
      "and": ["active|=|1", "email_verified_at|not null|"]
    }
  },
  "orderby": [{"total": "desc"}, {"created_at": "desc"}],
  "pagination": {"page": 1, "pageSize": 20}
}
```

**Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 45,
      "status": "processing",
      "total": 1250.00,
      "created_at": "2026-01-25T16:45:00.000000Z",
      "user": {
        "id": 10,
        "name": "María García",
        "email": "maria@example.com"
      },
      "items": [
        {
          "id": 201,
          "product_name": "Monitor 4K",
          "quantity": 2
        },
        {
          "id": 202,
          "product_name": "Teclado mecánico",
          "quantity": 1
        }
      ]
    }
  ],
  "per_page": 20,
  "total": 45,
  "last_page": 3
}
```

---

## Referencia Rápida

```
# Listado básico
GET /orders

# Show por ID
GET /orders/1

# Select campos
GET /orders?select=["id","status"]

# Relaciones
GET /orders?relations=["user","items"]

# Relaciones con campos
GET /orders?relations=["user:id,name"]

# Filtros simples (AND implícito)
GET /orders?oper=["status|=|active","total|>|100"]

# Filtros AND/OR explícitos
GET /orders?oper={"and":[...],"or":[...]}

# Filtros en relaciones
GET /orders?oper={"user":{"and":["active|=|1"]}}

# Ordenamiento
GET /orders?orderby=[{"created_at":"desc"}]

# Paginación estándar
GET /orders?pagination={"page":1,"pageSize":20}

# Paginación cursor
GET /orders?pagination={"infinity":true,"pageSize":20}
GET /orders?pagination={"infinity":true,"cursor":"..."}
```
